<?php
if (!defined('ZBP_PATH')) { exit('Access denied'); }

RegisterPlugin('everfresh', 'ActivePlugin_everfresh');

/**
 * Everfresh（常新）：按天把池子里的旧文章一批批翻上首页，并把列表日期改写成今天，
 * 让长期不更新的站点在首页看起来仍在每天更新。
 *
 * 只改渲染用的内存对象，不改主题模板、不写数据库。
 */
function ActivePlugin_everfresh()
{
    global $zbp;
    $zbp->LoadLanguage('plugin', 'everfresh');
    everfresh_EnsureConfig();
    Add_Filter_Plugin('Filter_Plugin_ViewList_Template', 'everfresh_ViewListTemplate');
}

function InstallPlugin_everfresh()
{
    global $zbp;
    $zbp->LoadLanguage('plugin', 'everfresh');
    everfresh_EnsureConfig();
}

/**
 * 停用只清理运行期状态，配置项全部保留（重新启用后设置还在）。
 */
function UninstallPlugin_everfresh()
{
}

/**
 * 自愈式初始化：安装回调不一定执行，这里按版本键补默认配置，可重复调用。
 * 版本键变更会为旧版站点补齐新配置项，已有键不会被覆盖。
 */
function everfresh_EnsureConfig()
{
    global $zbp;

    $cfg = $zbp->Config('everfresh');
    if ((int) $cfg->version >= 5) {
        return;
    }

    $defaults = array(
        'enable' => 1,
        'mode' => 'pool',
        'start_id' => 1,
        'daily_count' => 5,
        'keep_latest' => 5,
        'date_mode' => 'shift',
        'day_hours' => 12,
        'start_date' => '',
        'end_date' => '',
        // GitHub 自动更新
        'github_repo' => 'hexoblog-web-site/Everfresh',
        'github_branch' => 'main',
        'update_auto_check' => 1,
        'update_interval' => 6,
    );

    // 从旧插件 ID「ghx_recycle」迁来设置：改名后 conf_Name 变了，
    // 不迁的话老用户升级后要全部重填一遍。
    if (!isset($cfg->enable)) {
        $legacy = $zbp->Config('ghx_recycle');
        if (isset($legacy->enable)) {
            foreach (array_keys($defaults) as $key) {
                if (isset($legacy->$key)) {
                    $cfg->$key = $legacy->$key;
                }
            }
        }
    }

    foreach ($defaults as $key => $value) {
        if (!isset($cfg->$key)) {
            $cfg->$key = $value;
        }
    }
    $cfg->version = 5;

    $zbp->SaveConfig('everfresh');
}

/**
 * 首页列表渲染前替换文章数组：只处理首页第一页，分类/标签/作者/日期/搜索页不动。
 * 外层兜住所有异常，任何意外都只是不生效，不能让首页报错。
 */
function everfresh_ViewListTemplate($template)
{
    try {
        everfresh_ApplyList($template);
    } catch (\Throwable $e) {
        return;
    }
}

function everfresh_ApplyList($template)
{
    global $zbp;

    if (!is_object($template)) {
        return;
    }
    // isset() 对不可访问的属性返回 false，避免直接读取触发 Error
    $tags = isset($template->templateTags) ? $template->templateTags : null;
    if (!is_array($tags)) {
        return;
    }
    if (!isset($tags['type']) || $tags['type'] !== 'index') {
        return;
    }
    if (isset($tags['page']) && (int) $tags['page'] > 1) {
        return;
    }
    if (!isset($tags['articles']) || !is_array($tags['articles']) || count($tags['articles']) === 0) {
        return;
    }

    $cfg = $zbp->Config('everfresh');
    if (empty($cfg->enable)) {
        return;
    }

    $list = everfresh_BuildList($tags['articles'], $cfg);
    if (count($list) === 0) {
        return;
    }

    $tags['articles'] = $list;
    $template->templateTags = $tags;
}

/**
 * 生成首页第一页要显示的文章数组。
 */
function everfresh_BuildList($articles, $cfg)
{
    $articles = array_values($articles);
    if (count($articles) < 1) {
        return array();
    }

    $shift = ((string) $cfg->date_mode === 'shift');

    if ((string) $cfg->mode === 'pool') {
        $built = everfresh_BuildPoolList($articles, $cfg);
        // 轮换期结束或池子不可用：原样返回。此时连日期也不能改，
        // 否则会出现「假日期 + 真实顺序」的矛盾页面。
        if (count($built) === 0) {
            return $articles;
        }
        $list = $built;
    } else {
        // 只重排当前页，不引入池里的其他文章。
        // 要改日期时 ApplyTodayTime 末尾会按新时间重排，这里的种子排序会被覆盖，故跳过。
        $list = $shift ? $articles : everfresh_SortBySeed($articles, date('Ymd'));
    }

    if ($shift) {
        $list = everfresh_ApplyTodayTime($list, $cfg);
    }

    // 统一按「置顶优先 + 时间倒序」输出：池子是按 ID 升序取的，
    // 不排序首页第一行会是最旧的一篇。
    return everfresh_SortByTime($list);
}

/**
 * 置顶优先、其余按发布时间倒序。
 */
function everfresh_SortByTime($list)
{
    usort($list, 'everfresh_ComparePostTime');

    return $list;
}

/**
 * 从候选池按游标取一页文章。
 *
 * 游标步长 = 当页实际能上的新面孔数（页容量 − 置顶 − 保留段），
 * 第 k 天从池中 k * 步长 的位置连续取，因此一轮内每篇恰好出现一次。
 * 周期天数仍按「池总篇数 ÷ 每天翻新篇数」展示，两者在配置满足
 * 「翻新 + 保留 = 每页篇数」且没有置顶时完全一致。
 */
function everfresh_BuildPoolList($articles, $cfg)
{
    global $zbp;

    $target = count($articles);
    // 周期展示用的是配置值，这里与后台统计保持同一口径（不再按当页篇数压缩）
    $dailyCount = max(1, (int) $cfg->daily_count);

    $safeStart = (int) $cfg->start_id;
    if ($safeStart < 1) {
        $safeStart = 1;
    }

    $poolTotal = everfresh_GetPoolTotal($safeStart);
    if ($poolTotal < 1) {
        return array();
    }

    $todayTs = strtotime(date('Y-m-d'));
    $startTs = everfresh_StartDate($cfg);

    // 只有手填了结束日才会停手；留空表示一直循环跑下去
    $endTs = everfresh_EndDate($cfg);
    if ($endTs !== null && $todayTs > $endTs) {
        return array();
    }

    $dayIndex = (int) round(($todayTs - $startTs) / 86400);
    if ($dayIndex < 0) {
        $dayIndex = 0;
    }

    // 保留段（池尾最新的几篇）不参与轮换，游标只在轮换段内推进，
    // 这样一轮里每篇恰好出现一次，不会出现重复或永远轮不到。
    $keepLatest = (int) $cfg->keep_latest;
    if ($keepLatest > $target - 1) {
        $keepLatest = $target - 1;
    }
    if ($keepLatest > $poolTotal - 1) {
        $keepLatest = $poolTotal - 1;
    }
    if ($keepLatest < 0) {
        $keepLatest = 0;
    }

    $rotateTotal = $poolTotal - $keepLatest;
    if ($rotateTotal < 1) {
        $rotateTotal = $poolTotal;
        $keepLatest = 0;
    }

    $result = array();
    $used = array();

    // 1. 置顶文章优先占位（只认当前页里的置顶，避免把没上首页的置顶也拉进来）
    foreach ($articles as $post) {
        if (count($result) >= $target) {
            break;
        }
        $id = (int) $post->ID;
        if ($id > 0 && (int) $post->IsTop !== 0 && !isset($used[$id])) {
            $result[] = $id;
            $used[$id] = true;
        }
    }
    $stickyCount = count($result);

    // 2. 游标段：每天真正能上的「新面孔」= 页容量 − 置顶 − 保留段。
    // 游标必须按这个实际值推进：按配置里的 daily_count 推进，在两者不相等时
    // 会跳过文章（永远轮不到）或重复（同一篇连着两天出现）。
    $slots = $target - $stickyCount - $keepLatest;
    if ($slots < 1) {
        $slots = 1;
    }
    if ($slots > $rotateTotal) {
        $slots = $rotateTotal;
    }
    $cursor = ($dayIndex * $slots) % $rotateTotal;

    // 3. 只按位置取需要的那几段 ID：保留段 + 游标段（回绕时拆成两段）。
    // 池子几千上万篇也只读十几行，不再整池加载。
    if ($keepLatest > 0) {
        foreach (everfresh_GetPoolIdsAt($safeStart, $rotateTotal, $keepLatest) as $id) {
            if (!isset($used[$id]) && count($result) < $target) {
                $result[] = $id;
                $used[$id] = true;
            }
        }
    }

    // 多取几篇备用：置顶文章本身也在池子里，可能正好落在游标段上，
    // 重合时往后顺延一篇，避免当页少文章。
    $take = $slots + $stickyCount + 1;
    if ($take > $rotateTotal) {
        $take = $rotateTotal;
    }
    $first = $take;
    if ($cursor + $first > $rotateTotal) {
        $first = $rotateTotal - $cursor;
    }
    $cursorIds = everfresh_GetPoolIdsAt($safeStart, $cursor, $first);
    $left = $take - count($cursorIds);
    if ($left > 0) {
        $cursorIds = array_merge($cursorIds, everfresh_GetPoolIdsAt($safeStart, 0, $left));
    }
    foreach ($cursorIds as $id) {
        if (count($result) >= $target) {
            break;
        }
        if (!isset($used[$id])) {
            $result[] = $id;
            $used[$id] = true;
        }
    }

    if (count($result) === 0) {
        return array();
    }

    // 一次 IN 查询取回对象，再按挑好的顺序重排，避免逐篇查询
    $found = $zbp->GetPostList(null, array(array('IN', 'log_ID', $result)), array(), null);
    $byId = array();
    foreach ($found as $post) {
        $byId[(int) $post->ID] = $post;
    }

    $list = array();
    foreach ($result as $id) {
        if (isset($byId[$id])) {
            $list[] = $byId[$id];
        }
    }

    return $list;
}

/**
 * 候选项的公共筛选条件：已发布的文章（不含页面、草稿、回收站）。
 *
 * @param int $startId
 *
 * @return array
 */
function everfresh_PoolWhere($startId)
{
    $where = array(
        array('=', 'log_Type', 0),
        array('=', 'log_Status', 0),
    );
    if ($startId > 1) {
        $where[] = array('>=', 'log_ID', $startId);
    }

    return $where;
}

/**
 * 候选池大小。只查一个数字，池子上万篇也不占内存。
 * 计数失败时退回取 ID 再数（有上限），保证功能不丢。
 */
function everfresh_GetPoolTotal($startId)
{
    global $zbp;

    static $cache = array();

    $key = $startId < 1 ? 1 : (int) $startId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $total = -1;
    try {
        $sql = $zbp->db->sql->Count($zbp->table['Post'], array('num' => '*'), everfresh_PoolWhere($key));
        $total = everfresh_ReadNum($zbp->db->Query($sql));
    } catch (\Throwable $e) {
        $total = -1;
    }

    if ($total < 0) {
        $total = count(everfresh_GetPoolIdsAll($key));
    }

    $cache[$key] = $total;

    return $total;
}

/**
 * 从 COUNT 查询结果里取出数字，兼容数组/对象行结构。
 *
 * @param mixed $rows
 *
 * @return int 取不到返回 -1
 */
function everfresh_ReadNum($rows)
{
    if (!is_array($rows) || count($rows) === 0) {
        return -1;
    }

    $row = reset($rows);
    if (is_array($row) || is_object($row)) {
        foreach (array('num', 'NUM', 'count', 'COUNT') as $k) {
            if (is_array($row) && isset($row[$k])) {
                return (int) $row[$k];
            }
            if (is_object($row) && isset($row->$k)) {
                return (int) $row->$k;
            }
        }
        $values = is_array($row) ? array_values($row) : array_values(get_object_vars($row));
        if (count($values) > 0) {
            return (int) $values[0];
        }
        return -1;
    }

    return (int) $row;
}

/**
 * 按位置取候选池里的一段 ID（池子按 log_ID 升序排队）。
 * 只取需要的那十来条，池子再大也不影响首页速度。
 */
function everfresh_GetPoolIdsAt($startId, $offset, $length)
{
    global $zbp;

    $offset = (int) $offset;
    $length = (int) $length;
    if ($offset < 0) {
        $offset = 0;
    }
    if ($length < 1) {
        return array();
    }
    if ($length > 500) {
        $length = 500;
    }

    $key = $startId < 1 ? 1 : (int) $startId;

    try {
        $sql = $zbp->db->sql->Select(
            $zbp->table['Post'],
            array('log_ID'),
            everfresh_PoolWhere($key),
            array('log_ID' => 'ASC'),
            array($offset, $length)
        );
        $rows = $zbp->db->Query($sql);
        if (is_array($rows)) {
            $ids = array();
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['log_ID'])) {
                    $id = (int) $row['log_ID'];
                } elseif (is_object($row) && isset($row->log_ID)) {
                    $id = (int) $row->log_ID;
                } else {
                    $id = 0;
                }
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }
    } catch (\Throwable $e) {
        // 落到下面的退化实现
    }

    return array_slice(everfresh_GetPoolIdsAll($key), $offset, $length);
}

/**
 * 退化实现：一次取回全部 ID 再切片。仅在原生查询不可用时使用。
 */
function everfresh_GetPoolIdsAll($startId)
{
    global $zbp;

    static $cache = array();

    $key = $startId < 1 ? 1 : (int) $startId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $ids = array();
    try {
        $rows = $zbp->GetPostList('log_ID', everfresh_PoolWhere($key), array('log_ID' => 'ASC'), array(0, 20000));
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } catch (\Throwable $e) {
        $ids = array();
    }

    $cache[$key] = $ids;

    return $ids;
}

/**
 * 轮换起始日：留空或填未来日期都按今天算。
 */
function everfresh_StartDate($cfg)
{
    $today = strtotime(date('Y-m-d'));
    $raw = trim((string) $cfg->start_date);
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts !== false && $ts <= $today) {
            return $ts;
        }
    }

    return $today;
}

/**
 * 轮完一圈要几天：池总篇数 ÷ 每天翻新篇数，向上取整。
 */
function everfresh_CycleDays($poolTotal, $dailyCount)
{
    $poolTotal = (int) $poolTotal;
    $dailyCount = (int) $dailyCount;
    if ($poolTotal < 1) {
        $poolTotal = 1;
    }
    if ($dailyCount < 1) {
        $dailyCount = 1;
    }

    return (int) ceil($poolTotal / $dailyCount);
}

/**
 * 轮换结束日：留空表示不设终点，一直按天循环（走完一圈自动从头再来）。
 * 手填则以手填日期为准，到那天停手、首页恢复真实列表。
 *
 * @return int|null 时间戳；null 表示无限循环
 */
function everfresh_EndDate($cfg)
{
    $raw = trim((string) $cfg->end_date);
    if ($raw === '') {
        return null;
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    return $ts;
}

/**
 * 把整页文章的日期落到「今天」，时刻散在指定的每日时段内，只改内存对象不写库。
 * 排序由 BuildList 末尾统一处理（置顶优先 + 时间倒序）。
 */
function everfresh_ApplyTodayTime($list, $cfg)
{
    $count = count($list);
    if ($count === 0) {
        return $list;
    }

    $hours = (int) $cfg->day_hours;
    if ($hours < 1) {
        $hours = 1;
    }
    if ($hours > 24) {
        $hours = 24;
    }

    $now = time();
    $todayStart = strtotime(date('Y-m-d', $now));
    $window = $hours * 3600;

    // 时间窗口必须落在「今天已经过去的部分」。否则凌晨访问时整段窗口都在未来，
    // 全被压到 now-60，多篇撞成同一秒、顺序变成随机。
    // 按整点收缩：同一小时内结果稳定，跨小时才整体前移，不影响当天的顺序稳定。
    $elapsed = $now - $todayStart;
    if ($elapsed < 3600) {
        $window = $elapsed - 60;
    } else {
        $hourEdge = (int) (floor($elapsed / 3600) * 3600);
        if ($hourEdge < $window) {
            $window = $hourEdge;
        }
    }
    if ($window < 120) {
        $window = 120;
    }

    $seed = date('Ymd', $now);

    foreach ($list as $post) {
        // 固定窗口取偏移，同一天内多次刷新结果稳定；落在未来时刻的压回当前
        $offset = everfresh_Hash($seed . '|' . (int) $post->ID) % $window;
        $ts = $todayStart + $offset;
        if ($ts > $now - 60) {
            $ts = $now - 60;
        }
        if ($ts < $todayStart + 60) {
            $ts = $todayStart + 60;
        }
        $post->PostTime = $ts;
    }

    return $list;
}

/**
 * 置顶优先，其余按（改写后的）发布时间倒序。
 */
function everfresh_ComparePostTime($a, $b)
{
    $ta = (int) $a->IsTop;
    $tb = (int) $b->IsTop;
    if ($ta !== $tb) {
        return $tb - $ta;
    }

    $pa = (int) $a->PostTime;
    $pb = (int) $b->PostTime;
    if ($pa === $pb) {
        return 0;
    }

    return ($pb > $pa) ? 1 : -1;
}

/**
 * 按当日种子稳定排序（同一天结果一致，跨天变化）。
 */
function everfresh_SortBySeed($articles, $seed)
{
    $scored = array();
    foreach ($articles as $post) {
        $scored[] = array('hash' => md5($seed . '|' . (int) $post->ID), 'post' => $post);
    }
    usort($scored, 'everfresh_CompareHash');

    $list = array();
    foreach ($scored as $row) {
        $list[] = $row['post'];
    }

    return $list;
}

function everfresh_CompareHash($a, $b)
{
    return strcmp((string) $a['hash'], (string) $b['hash']);
}

/**
 * 跨平台稳定散列值（0 ~ 2^28）。
 */
function everfresh_Hash($text)
{
    return (int) hexdec(substr(md5((string) $text), 0, 7));
}

/**
 * 后台用：把配置换算成「池子多大、几天轮完、起止日期」供页面展示。
 */
function everfresh_Stat($cfg)
{
    $poolTotal = everfresh_GetPoolTotal((int) $cfg->start_id);
    $dailyCount = max(1, (int) $cfg->daily_count);
    $cycleDays = everfresh_CycleDays($poolTotal, $dailyCount);
    $startTs = everfresh_StartDate($cfg);
    $endTs = everfresh_EndDate($cfg);

    return array(
        'total' => $poolTotal,
        'daily' => $dailyCount,
        'cycle' => $cycleDays,
        'start' => date('Y-m-d', $startTs),
        'end' => $endTs === null ? '' : date('Y-m-d', $endTs),
        'loop' => $endTs === null,
    );
}
