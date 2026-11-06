<?php
if (!defined('ZBP_PATH')) { exit('Access denied'); }

/**
 * 自动更新（把 GitHub 仓库当分发服务器）
 *
 * 思路和 HtmlCms 那套一致：比一下远程最新提交与本插件 plugin.xml 的版本号，
 * 有新的就把源码 zip 拉下来，只覆盖插件目录内的程序文件。
 *
 * 几条硬规则，改代码时别破坏：
 *   · 只走 https，只认 github.com / api.github.com / codeload.github.com，URL 全部由本文件拼接
 *   · 仓库地址只接受 owner/repo，不接受完整 URL，避免被指向别处
 *   · 覆盖前先备份，任何一步失败整批回滚，不留半新半旧
 *   · 解压前校验路径，拒绝 ../、绝对路径、以及白名单之外的任何文件
 *   · 远程版本低于本地一律拒绝，避免手滑把插件降级
 *
 * 运行时状态（上次检查时间、已应用的 commit、备份记录）存在插件目录的 runtime/update.json，
 * 这个目录永远不在覆盖白名单里。
 */

/* ---------------- 基础 ---------------- */

/** 插件根目录 */
function everfresh_UpdateRoot()
{
    return dirname(__DIR__);
}

/** 当前插件版本（读 plugin.xml，不依赖 Z-Blog 的插件缓存） */
function everfresh_UpdateVersion()
{
    static $ver = null;
    if ($ver !== null) {
        return $ver;
    }

    $xml = @file_get_contents(everfresh_UpdateRoot() . '/plugin.xml');
    if (is_string($xml) && preg_match('#<version>\s*([0-9][0-9A-Za-z._-]*)\s*</version>#', $xml, $m)) {
        $ver = $m[1];
    } else {
        $ver = '0.0.0';
    }

    return $ver;
}

/** 运行时目录，存放状态、临时包与备份 */
function everfresh_UpdateRuntime($sub = '')
{
    static $base = null;
    if ($base === null) {
        $base = everfresh_UpdateRoot() . '/runtime';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $base = is_dir($base) ? $base : false;
    }
    if ($base === false) {
        return false;
    }
    if ($sub === '') {
        return $base;
    }

    $dir = $base . '/' . trim($sub, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return is_dir($dir) ? $dir : false;
}

/** 状态文件 */
function everfresh_UpdateStateFile()
{
    $dir = everfresh_UpdateRuntime();

    return $dir === false ? false : $dir . '/update.json';
}

/** 读状态，永不出错：文件坏了就当空状态 */
function everfresh_UpdateStateRead()
{
    $out = array();
    $f = everfresh_UpdateStateFile();
    if ($f !== false && is_file($f)) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j)) {
            $out = $j;
        }
    }

    return $out;
}

/** 写状态（临时名 + rename，避免读到半个文件） */
function everfresh_UpdateStateWrite($state)
{
    $f = everfresh_UpdateStateFile();
    if ($f === false) {
        return false;
    }

    $data = json_encode((array) $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, $data) === false) {
        return false;
    }
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/** 人类可读的字节数 */
function everfresh_FormatBytes($n)
{
    $n = (int) $n;
    if ($n >= 1048576) {
        return round($n / 1048576, 1) . ' MB';
    }
    if ($n >= 1024) {
        return round($n / 1024, 1) . ' KB';
    }

    return $n . ' B';
}

/* ---------------- 配置 ---------------- */

/** 仓库（owner/repo），不合法返回空串 */
function everfresh_UpdateRepo($cfg)
{
    $r = trim((string) $cfg->github_repo);
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $r)) {
        return '';
    }

    return $r;
}

/** 分支，默认 main */
function everfresh_UpdateBranch($cfg)
{
    $b = trim((string) $cfg->github_branch);
    if ($b === '' || strlen($b) > 100 || strpos($b, '..') !== false || !preg_match('#^[A-Za-z0-9._/-]+$#', $b)) {
        return 'main';
    }

    return $b;
}

/** 自动检查间隔（秒），默认 6 小时 */
function everfresh_UpdateInterval($cfg)
{
    $h = (int) $cfg->update_interval;
    if ($h < 1) {
        $h = 6;
    }
    if ($h > 168) {
        $h = 168;
    }

    return $h * 3600;
}

/* ---------------- HTTP ---------------- */

/**
 * 取一个地址
 *
 * @param string      $url
 * @param string|null $dest 给了就写文件，否则返回字符串
 * @param int         $maxBytes
 * @param string      $err
 * @param int         $timeout
 *
 * @return string|bool
 */
function everfresh_Http($url, $dest, $maxBytes, &$err, $timeout = 60)
{
    $err = '';
    $body = '';
    $fp = null;
    $got = 0;
    $tooBig = false;

    if ($dest !== null) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            $err = '无法写入临时文件，请检查插件目录的 runtime 是否可写';
            return false;
        }
    }

    $write = function ($ch, $chunk) use (&$body, &$fp, &$got, $maxBytes, &$tooBig) {
        $got += strlen($chunk);
        if ($got > $maxBytes) {
            $tooBig = true;
            return 0; // 中断传输
        }
        if ($fp) {
            fwrite($fp, $chunk);
        } else {
            $body .= $chunk;
        }

        return strlen($chunk);
    };

    $ua = 'Everfresh-Updater/' . everfresh_UpdateVersion();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(10, (int) $timeout));
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/vnd.github+json'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $write);

        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($fp) {
            fclose($fp);
            $fp = null;
        }

        if ($tooBig) {
            $err = '响应内容超过 ' . everfresh_FormatBytes($maxBytes);
            return false;
        }
        if ($ok === false) {
            $err = $cerr !== '' ? '连接失败：' . $cerr : '连接失败';
            if (stripos($cerr, 'certificate') !== false || stripos($cerr, 'SSL') !== false) {
                $err .= '。服务器缺 CA 证书：Windows 在 php.ini 里把 curl.cainfo 指向 cacert.pem，Linux 装 ca-certificates 即可';
            }
            return false;
        }
        if ($code === 404) {
            $err = '仓库或分支不存在（HTTP 404），请检查仓库地址与分支名';
            return false;
        }
        if ($code === 403) {
            $err = 'GitHub 拒绝访问（HTTP 403），可能是接口调用过于频繁，稍后再试';
            return false;
        }
        if ($code < 200 || $code >= 300) {
            $err = '请求失败（HTTP ' . $code . '）';
            return false;
        }

        return $dest !== null ? true : $body;
    }

    /* 没有 curl 的主机：流式读取兜底 */
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout' => max(10, (int) $timeout),
            'user_agent' => $ua,
            'follow_location' => 1,
            'max_redirects' => 5,
            'header' => "Accept: application/vnd.github+json\r\n",
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) {
        $err = '无法打开地址（可能是网络不通、被墙，或服务器没有开启 allow_url_fopen）';
        return false;
    }
    while (!feof($src)) {
        $chunk = fread($src, 8192);
        if ($chunk === false) {
            break;
        }
        /* 闭包签名与 curl 的回调一致（$ch, $chunk），这里没有句柄，传 null 占位 */
        if ($write(null, $chunk) === 0) {
            break;
        }
    }
    fclose($src);
    if ($fp) {
        fclose($fp);
        $fp = null;
    }
    if ($tooBig) {
        $err = '响应内容超过 ' . everfresh_FormatBytes($maxBytes);
        return false;
    }
    if ($dest !== null) {
        return is_file($dest) && filesize($dest) > 0;
    }
    if ($body === '') {
        $err = '返回内容为空';
        return false;
    }

    return $body;
}

/* ---------------- 查询远程 ---------------- */

/**
 * 远程最新提交与版本号
 *
 * @return array|false array('sha','version','msg','date','branch')
 */
function everfresh_UpdateRemoteInfo($cfg, &$err, $timeout = 60)
{
    $err = '';
    $repo = everfresh_UpdateRepo($cfg);
    if ($repo === '') {
        $err = '仓库地址未设置或格式不对，应为 owner/repo，例如 hexoblog-web-site/Everfresh';
        return false;
    }
    $branch = everfresh_UpdateBranch($cfg);

    $api = 'https://api.github.com/repos/' . $repo;
    $json = everfresh_Http($api . '/commits/' . rawurlencode($branch), null, 1048576, $err, $timeout);
    if ($json === false) {
        return false;
    }
    $j = json_decode($json, true);
    if (!is_array($j) || empty($j['sha'])) {
        $hint = (is_array($j) && !empty($j['message'])) ? (string) $j['message'] : '返回内容不是 JSON';
        $err = '查询提交失败：' . $hint;
        return false;
    }

    $sha = (string) $j['sha'];
    $msg = '';
    if (!empty($j['commit']['message'])) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $j['commit']['message']);
        $msg = trim((string) $lines[0]);
        if (function_exists('mb_substr')) {
            $msg = mb_substr($msg, 0, 100, 'UTF-8');
        } else {
            $msg = substr($msg, 0, 100);
        }
    }
    $date = !empty($j['commit']['committer']['date']) ? (string) $j['commit']['committer']['date'] : '';

    /* 版本号从远程 plugin.xml 里取：还没打 tag 时也能判断新旧 */
    $version = '';
    $raw = everfresh_Http($api . '/contents/plugin.xml?ref=' . rawurlencode($sha), null, 2097152, $e2, $timeout);
    if ($raw !== false) {
        $f = json_decode($raw, true);
        if (!empty($f['content'])) {
            $src = base64_decode(str_replace(array("\n", "\r"), '', (string) $f['content']));
            if (preg_match('#<version>\s*([0-9][0-9A-Za-z._-]*)\s*</version>#', (string) $src, $m)) {
                $version = $m[1];
            }
        }
    }

    return array(
        'sha' => $sha,
        'version' => $version,
        'msg' => $msg,
        'date' => $date,
        'branch' => $branch,
        'repo' => $repo,
    );
}

/** 状态里是否存在「有新版」 */
function everfresh_UpdateHasNew($state)
{
    if (empty($state['remote']['sha'])) {
        return false;
    }
    $remote = (string) $state['remote']['sha'];
    $applied = isset($state['applied_sha']) ? (string) $state['applied_sha'] : '';
    if ($remote === $applied) {
        return false;
    }
    $local = everfresh_UpdateVersion();
    if (!empty($state['remote']['version']) && version_compare((string) $state['remote']['version'], $local, '>')) {
        return true;
    }
    /* 版本号没变（提交者忘了改）但确实推了新代码：只有记录过已应用 commit 时才提示，
       否则本地开发中的版本永远显示「有更新」 */
    if ($applied !== '' && $remote !== $applied) {
        return true;
    }

    return false;
}

/**
 * 检查更新
 *
 * @param bool $force 忽略间隔，强制联网
 *
 * @return array ok / cached / has_update / state / err
 */
function everfresh_UpdateCheck($cfg, $force = false, &$err = '', $timeout = 60)
{
    $err = '';
    $state = everfresh_UpdateStateRead();

    if (!$force && !empty($state['checked_at']) && (time() - (int) $state['checked_at']) < everfresh_UpdateInterval($cfg)) {
        return array(
            'ok' => true,
            'cached' => true,
            'has_update' => everfresh_UpdateHasNew($state),
            'state' => $state,
            'err' => '',
        );
    }

    $info = everfresh_UpdateRemoteInfo($cfg, $err, $timeout);
    if ($info === false) {
        $state['checked_at'] = time();
        $state['error'] = $err;
        everfresh_UpdateStateWrite($state);

        return array('ok' => false, 'cached' => false, 'has_update' => false, 'state' => $state, 'err' => $err);
    }

    $state['checked_at'] = time();
    $state['error'] = '';
    $state['remote'] = $info;
    everfresh_UpdateStateWrite($state);

    return array(
        'ok' => true,
        'cached' => false,
        'has_update' => everfresh_UpdateHasNew($state),
        'state' => $state,
        'err' => '',
    );
}

/** 自动检查是否到点（只读本地状态，不联网） */
function everfresh_UpdateAutoDue($cfg)
{
    if (empty($cfg->update_auto_check)) {
        return false;
    }
    $state = everfresh_UpdateStateRead();

    return empty($state['checked_at']) || (time() - (int) $state['checked_at']) >= everfresh_UpdateInterval($cfg);
}

/**
 * 后台打开时静默查一次（只查不装），失败不打断页面
 */
function everfresh_UpdateMaybeAuto($cfg)
{
    if (!everfresh_UpdateAutoDue($cfg)) {
        return false;
    }
    /* 状态写不进去就别联网：否则每次打开后台都会卡在这条请求上 */
    if (everfresh_UpdateRuntime() === false) {
        return false;
    }

    return everfresh_UpdateCheck($cfg, false, $e, 8);
}

/* ---------------- 备份与回滚 ---------------- */

/** 参与更新的文件与目录（白名单，runtime 永远不在里面） */
function everfresh_UpdateItems()
{
    return array(
        'files' => array('include.php', 'main.php', 'plugin.xml', 'logo.png', 'logo.svg', 'README.md', 'LICENSE'),
        'dirs' => array('language', 'style', 'inc'),
    );
}

/** 备份目录的根 */
function everfresh_UpdateBackupRoot()
{
    return everfresh_UpdateRuntime('backup');
}

/**
 * 备份当前插件文件
 *
 * @return string|false 备份目录
 */
function everfresh_UpdateBackup(&$err)
{
    $err = '';
    $root = everfresh_UpdateBackupRoot();
    if ($root === false) {
        $err = '创建备份目录失败，请检查插件目录的 runtime 是否可写';
        return false;
    }

    $dir = $root . '/' . date('Ymd-His') . '-' . str_replace('.', '_', everfresh_UpdateVersion());
    if (!everfresh_EnsureDir($dir)) {
        $err = '创建备份目录失败：' . $dir;
        return false;
    }

    $items = everfresh_UpdateItems();
    $base = everfresh_UpdateRoot();

    foreach ($items['files'] as $f) {
        if (is_file($base . '/' . $f) && !@copy($base . '/' . $f, $dir . '/' . $f)) {
            $err = '备份 ' . $f . ' 失败';
            return false;
        }
    }
    foreach ($items['dirs'] as $d) {
        if (is_dir($base . '/' . $d) && !everfresh_CopyTree($base . '/' . $d, $dir . '/' . $d, $e)) {
            $err = '备份 ' . $d . ' 失败：' . $e;
            return false;
        }
    }

    everfresh_UpdatePruneBackups(3);

    return $dir;
}

/** 递归复制目录 */
function everfresh_CopyTree($src, $dst, &$err)
{
    $err = '';
    if (!is_dir($src)) {
        return true;
    }
    if (!everfresh_EnsureDir($dst)) {
        $err = '创建目录失败：' . $dst;
        return false;
    }
    $dh = @opendir($src);
    if (!$dh) {
        $err = '读取目录失败：' . $src;
        return false;
    }
    while (($item = readdir($dh)) !== false) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!everfresh_CopyTree($s, $d, $err)) {
                closedir($dh);
                return false;
            }
        } elseif (!@copy($s, $d)) {
            $err = '复制失败：' . $item;
            closedir($dh);
            return false;
        }
    }
    closedir($dh);

    return true;
}

/** 确保目录存在 */
function everfresh_EnsureDir($dir)
{
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, 0755, true) || is_dir($dir);
}

/**
 * 用备份覆盖回插件目录
 */
function everfresh_UpdateRestore($dir, &$err)
{
    $err = '';
    $root = realpath((string) everfresh_UpdateBackupRoot());
    $real = realpath((string) $dir);
    if ($root === false || $real === false || strpos($real, $root) !== 0 || !is_dir($real)) {
        $err = '备份目录不合法';
        return false;
    }

    $items = everfresh_UpdateItems();
    $base = everfresh_UpdateRoot();

    foreach ($items['files'] as $f) {
        if (is_file($real . '/' . $f) && !@copy($real . '/' . $f, $base . '/' . $f)) {
            $err = '恢复 ' . $f . ' 失败';
            return false;
        }
    }
    foreach ($items['dirs'] as $d) {
        if (!is_dir($real . '/' . $d)) {
            continue;
        }
        if (!everfresh_CopyTree($real . '/' . $d, $base . '/' . $d, $e)) {
            $err = '恢复 ' . $d . ' 失败：' . $e;
            return false;
        }
        /* 把「更新时新增、备份里没有」的文件清掉，否则回滚回来还留着新版文件 */
        everfresh_PruneExtra($real . '/' . $d, $base . '/' . $d);
    }

    return true;
}

/** 删掉目标目录里备份中没有的文件 */
function everfresh_PruneExtra($backupDir, $targetDir)
{
    if (!is_dir($targetDir) || !is_dir($backupDir)) {
        return;
    }
    foreach ((array) @scandir($targetDir) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $t = $targetDir . '/' . $n;
        $b = $backupDir . '/' . $n;
        if (is_dir($t)) {
            if (is_dir($b)) {
                everfresh_PruneExtra($b, $t);
            } else {
                everfresh_Rmdir($t);
            }
        } elseif (!is_file($b)) {
            @unlink($t);
        }
    }
}

/** 只保留最近 N 份备份 */
function everfresh_UpdatePruneBackups($keep = 3)
{
    $root = everfresh_UpdateBackupRoot();
    if ($root === false || !is_dir($root)) {
        return;
    }
    $list = array();
    foreach ((array) @scandir($root) as $n) {
        if ($n === '.' || $n === '..' || !is_dir($root . '/' . $n)) {
            continue;
        }
        $list[] = $n;
    }
    sort($list);
    $drop = array_slice($list, 0, max(0, count($list) - $keep));
    foreach ($drop as $n) {
        everfresh_Rmdir($root . '/' . $n);
    }
}

/** 递归删目录（只用于 runtime 下自己建的目录） */
function everfresh_Rmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) @scandir($dir) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $dir . '/' . $n;
        if (is_dir($p)) {
            everfresh_Rmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/** 可用备份列表（新 → 旧） */
function everfresh_UpdateBackups()
{
    $root = everfresh_UpdateBackupRoot();
    $out = array();
    if ($root === false || !is_dir($root)) {
        return $out;
    }
    foreach ((array) @scandir($root) as $n) {
        if ($n === '.' || $n === '..' || !is_dir($root . '/' . $n)) {
            continue;
        }
        $out[] = array('name' => $n, 'path' => $root . '/' . $n);
    }
    /* 目录名是 Ymd-His-版本，字典序就是时间序 */
    usort($out, function ($a, $b) {
        return strcmp($b['name'], $a['name']);
    });

    return $out;
}

/* ---------------- 写入 ---------------- */

/**
 * 允许被更新覆盖的相对路径
 */
function everfresh_UpdatePathAllowed($rel)
{
    $rel = str_replace('\\', '/', ltrim((string) $rel, '/'));
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
        return false;
    }

    $items = everfresh_UpdateItems();
    if (in_array($rel, $items['files'], true)) {
        return true;
    }
    foreach ($items['dirs'] as $d) {
        if (strpos($rel, $d . '/') === 0) {
            return true;
        }
    }

    return false;
}

/**
 * 写一个文件：先写临时名再 rename，单文件原子替换
 */
function everfresh_UpdateWriteFile($rel, $data, &$err)
{
    $err = '';
    $rel = str_replace('\\', '/', $rel);
    if (!everfresh_UpdatePathAllowed($rel)) {
        $err = '路径不在允许范围内';
        return false;
    }

    $dst = everfresh_UpdateRoot() . '/' . $rel;
    if (!everfresh_EnsureDir(dirname($dst))) {
        $err = '创建目录失败';
        return false;
    }

    $tmp = $dst . '.evfnew';
    if (@file_put_contents($tmp, $data) === false) {
        $err = '写入失败（目录不可写？）';
        return false;
    }
    if (!@rename($tmp, $dst)) {
        // Windows 下目标文件可能被占用，删掉再试一次
        @unlink($dst);
        if (!@rename($tmp, $dst)) {
            @unlink($tmp);
            $err = '替换文件失败';
            return false;
        }
    }
    @chmod($dst, 0644);

    return true;
}

/**
 * 写权限预检：真写一个探针文件，比 is_writable 可靠
 */
function everfresh_UpdateWritablePreflight(&$err)
{
    $err = '';
    $base = everfresh_UpdateRoot();
    foreach (array($base, $base . '/language', $base . '/style', $base . '/inc') as $d) {
        if (!is_dir($d)) {
            $err = '目录不存在：' . str_replace($base, '插件目录', $d);
            return false;
        }
        $probe = $d . '/.evf-write-test';
        if (@file_put_contents($probe, 'x') === false) {
            $err = str_replace($base, '插件目录', $d) . ' 不可写，请把插件目录设为 755（属主为 PHP 运行用户）后重试';
            return false;
        }
        @unlink($probe);
    }

    return true;
}

/** 清缓存，避免更新后仍走旧缓存 */
function everfresh_UpdateClearCache()
{
    global $zbp;

    try {
        if (is_object($zbp) && method_exists($zbp, 'ClearCache')) {
            $zbp->ClearCache();
        }
    } catch (\Throwable $e) {
        // 缓存清不掉不影响本次更新
    }
}

/* ---------------- 下载与安装 ---------------- */

/** 按 commit 下载源码包 */
function everfresh_UpdateDownload($cfg, $sha, &$err)
{
    $err = '';
    $repo = everfresh_UpdateRepo($cfg);
    if ($repo === '' || !preg_match('#^[0-9a-f]{7,40}$#i', (string) $sha)) {
        $err = '提交号或仓库地址不合法';
        return false;
    }

    $dir = everfresh_UpdateRuntime('tmp');
    if ($dir === false) {
        $err = '创建临时目录失败，请检查插件目录的 runtime 是否可写';
        return false;
    }
    $zip = $dir . '/update-' . substr($sha, 0, 12) . '.zip';
    @unlink($zip);

    $url = 'https://codeload.github.com/' . $repo . '/zip/' . rawurlencode($sha);
    if (!everfresh_Http($url, $zip, 20 * 1024 * 1024, $err, 120)) {
        @unlink($zip);
        return false;
    }
    if (!is_file($zip) || filesize($zip) < 1024) {
        @unlink($zip);
        $err = '下载的压缩包异常';
        return false;
    }

    return $zip;
}

/** zip 内部的统一前缀（GitHub 打包会套一层 repo-sha/） */
function everfresh_UpdateZipPrefix($names)
{
    $first = null;
    foreach ((array) $names as $n) {
        $pos = strpos($n, '/');
        if ($pos === false) {
            return '';
        }
        $seg = substr($n, 0, $pos + 1);
        if ($first === null) {
            $first = $seg;
        } elseif ($first !== $seg) {
            return false;
        }
    }

    return $first === null ? false : $first;
}

/**
 * 从 zip 安装更新
 *
 * @return array|false array('version','files','backup')
 */
function everfresh_UpdateInstallZip($zipPath, $info, &$err)
{
    $err = '';

    require_once __DIR__ . '/zipreader.php';

    $zip = new Everfresh_Zip_Reader($zipPath);
    if ($zip->error() !== '') {
        $err = '压缩包无法读取：' . $zip->error();
        return false;
    }
    $entries = $zip->entries();
    $prefix = everfresh_UpdateZipPrefix(array_keys($entries));
    if ($prefix === false) {
        $err = '压缩包结构不符合预期（找不到统一的根目录）';
        return false;
    }

    /* 关键文件必须在，且必须确认是本插件，别把别的仓库塞进来 */
    foreach (array('plugin.xml', 'include.php', 'main.php') as $k) {
        if (!isset($entries[$prefix . $k])) {
            $err = '压缩包缺少关键文件：' . $k;
            return false;
        }
    }

    $xmlData = $zip->read($prefix . 'plugin.xml');
    if ($xmlData === false) {
        $err = '读取 plugin.xml 失败：' . $zip->error();
        return false;
    }
    if (!preg_match('#<id>\s*everfresh\s*</id>#', (string) $xmlData)) {
        $err = '压缩包不是 Everfresh 插件，已拒绝';
        return false;
    }
    if (!preg_match('#<version>\s*([0-9][0-9A-Za-z._-]*)\s*</version>#', (string) $xmlData, $m)) {
        $err = '压缩包里读不到版本号，已拒绝更新';
        return false;
    }
    $remoteVer = $m[1];
    $localVer = everfresh_UpdateVersion();
    if (version_compare($remoteVer, $localVer, '<')) {
        $err = '远程版本 ' . $remoteVer . ' 低于当前 ' . $localVer . '，已取消';
        return false;
    }

    /* 先备份，后动手 */
    $backup = everfresh_UpdateBackup($err);
    if ($backup === false) {
        return false;
    }

    if (!everfresh_UpdateWritablePreflight($perr)) {
        $err = '插件目录不可写，已取消更新：' . $perr;
        return false;
    }

    $written = 0;
    $failed = array();

    foreach ($entries as $name => $e) {
        if ($prefix !== '' && strpos($name, $prefix) !== 0) {
            continue;
        }
        $rel = $prefix === '' ? $name : substr($name, strlen($prefix));
        if (!everfresh_UpdatePathAllowed($rel)) {
            continue;
        }
        $data = $zip->read($name);
        if ($data === false) {
            $failed[] = $rel . '（' . $zip->error() . '）';
            continue;
        }
        if (!everfresh_UpdateWriteFile($rel, $data, $werr)) {
            $failed[] = $rel . '（' . $werr . '）';
        } else {
            $written++;
        }
    }

    /* 写完复核一遍关键文件，避免解压中途出问题还当成成功 */
    if (!$failed && !preg_match('#<version>#', (string) @file_get_contents(everfresh_UpdateRoot() . '/plugin.xml'))) {
        $failed[] = 'plugin.xml 写入后不可读';
    }

    if ($failed) {
        $restoreErr = '';
        everfresh_UpdateRestore($backup, $restoreErr);
        $err = '更新中断，' . ($restoreErr === '' ? '已回滚到更新前的版本' : '回滚也失败了，请手工从备份恢复：' . $backup)
            . '。原因：' . implode('；', array_slice($failed, 0, 3));
        return false;
    }

    everfresh_UpdateClearCache();
    everfresh_UpdatePruneBackups(3);

    $state = everfresh_UpdateStateRead();
    $state['applied_sha'] = (string) $info['sha'];
    $state['applied_version'] = $remoteVer;
    $state['last_update_at'] = time();
    $state['last_backup'] = $backup;
    $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : array();
    array_unshift($history, array(
        'at' => time(),
        'version' => $remoteVer,
        'from' => $localVer,
        'sha' => substr((string) $info['sha'], 0, 12),
        'files' => $written,
        'backup' => basename($backup),
    ));
    $state['history'] = array_slice($history, 0, 10);
    everfresh_UpdateStateWrite($state);

    return array('version' => $remoteVer, 'from' => $localVer, 'files' => $written, 'backup' => $backup);
}

/**
 * 更新锁：同一时间只允许一个更新在跑
 *
 * @return resource|false
 */
function everfresh_UpdateLock()
{
    $dir = everfresh_UpdateRuntime();
    if ($dir === false) {
        return false;
    }
    $fp = @fopen($dir . '/update.lock', 'c');
    if (!$fp) {
        return false;
    }
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }

    return $fp;
}

function everfresh_UpdateUnlock($fp)
{
    if ($fp) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/**
 * 一键更新：查 → 下载 → 安装
 *
 * @return array|false array('version','files','backup')
 */
function everfresh_UpdateApply($cfg, &$err)
{
    $err = '';
    $lock = everfresh_UpdateLock();
    if ($lock === false) {
        $err = '另一个更新正在进行，请稍后刷新再试';
        return false;
    }
    $r = everfresh_UpdateApplyLocked($cfg, $err);
    everfresh_UpdateUnlock($lock);

    return $r;
}

/** 真正干活的那个，由 everfresh_UpdateApply 持锁调用 */
function everfresh_UpdateApplyLocked($cfg, &$err)
{
    $err = '';
    @set_time_limit(180);

    $info = everfresh_UpdateRemoteInfo($cfg, $err, 60);
    if ($info === false) {
        return false;
    }
    $local = everfresh_UpdateVersion();
    if ($info['version'] !== '' && version_compare($info['version'], $local, '<')) {
        $err = '远程版本 ' . $info['version'] . ' 低于当前 ' . $local . '，已取消';
        return false;
    }

    $zip = everfresh_UpdateDownload($cfg, $info['sha'], $err);
    if ($zip === false) {
        return false;
    }

    $r = everfresh_UpdateInstallZip($zip, $info, $err);
    @unlink($zip);

    return $r;
}

/** 回滚到指定的更新备份 */
function everfresh_UpdateRollback($dir, &$err)
{
    $err = '';
    $r = everfresh_UpdateRestore($dir, $err);
    if (!$r) {
        return false;
    }

    $state = everfresh_UpdateStateRead();
    unset($state['applied_sha'], $state['applied_version']);
    $state['last_rollback_at'] = time();
    everfresh_UpdateStateWrite($state);
    everfresh_UpdateClearCache();

    return true;
}

/* ---------------- 后台动作入口 ---------------- */

/**
 * 处理检查 / 更新 / 回滚三个动作，结果写进状态文件，页面刷新后展示
 *
 * @param string $action check|update|rollback
 * @param string $backup 回滚时指定的备份目录名
 */
function everfresh_UpdateHandleAction($cfg, $action, $backup = '')
{
    $ok = false;
    $msg = '';

    if ($action === 'check') {
        $r = everfresh_UpdateCheck($cfg, true, $err, 30);
        $ok = !empty($r['ok']);
        if ($ok) {
            $state = $r['state'];
            $remoteVer = isset($state['remote']['version']) ? (string) $state['remote']['version'] : '';
            $msg = $r['has_update']
                ? '发现有新版：' . ($remoteVer !== '' ? $remoteVer : substr((string) $state['remote']['sha'], 0, 7))
                : '已是最新版本';
        } else {
            $msg = $err;
        }
    } elseif ($action === 'update') {
        $r = everfresh_UpdateApply($cfg, $err);
        $ok = $r !== false;
        $msg = $ok
            ? '已更新 ' . $r['from'] . ' → ' . $r['version'] . '，覆盖 ' . $r['files'] . ' 个文件'
            : $err;
    } elseif ($action === 'rollback') {
        $name = basename((string) $backup);
        $root = everfresh_UpdateBackupRoot();
        $dir = ($root === false) ? '' : $root . '/' . $name;
        if ($name === '' || !is_dir($dir)) {
            $msg = '找不到这个备份';
        } else {
            $ok = everfresh_UpdateRollback($dir, $err);
            $msg = $ok ? '已回滚到 ' . $name : $err;
        }
    } else {
        $msg = '未知操作';
    }

    $state = everfresh_UpdateStateRead();
    $state['last_action'] = $action;
    $state['last_message'] = $msg;
    $state['last_message_ok'] = $ok ? 1 : 0;
    $state['last_message_at'] = time();
    everfresh_UpdateStateWrite($state);

    return array('ok' => $ok, 'msg' => $msg);
}
