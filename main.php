<?php
require '../../../zb_system/function/c_system_base.php';
require '../../../zb_system/function/c_system_admin.php';

$zbp->Load();

$action = 'root';
if (!$zbp->CheckRights($action)) {
    $zbp->ShowError(6);
    die();
}

if (!$zbp->CheckPlugin('everfresh')) {
    // 未启用时 include.php 不在引导列表里，语言包要自己加载
    require dirname(__FILE__) . '/include.php';
    $zbp->LoadLanguage('plugin', 'everfresh');
    $zbp->ShowError(48);
    die();
}

$zbp->LoadLanguage('plugin', 'everfresh');
$everfresh_lang = $zbp->lang['everfresh'];

// 每页篇数：取值顺序 ZC_DISPLAY_COUNT（列表页文章数）→ ZC_PAGEBAR_COUNT → 10，
// 只用来给「翻新 + 保留」定总和，前台实际以当页文章数为准。
$everfresh_page_count = 0;
foreach (array('ZC_DISPLAY_COUNT', 'ZC_PAGEBAR_COUNT') as $everfresh_key) {
    if (isset($zbp->option[$everfresh_key])) {
        $everfresh_value = (int) $zbp->option[$everfresh_key];
        if ($everfresh_value > 0) {
            $everfresh_page_count = $everfresh_value;
            break;
        }
    }
}
if ($everfresh_page_count < 1) {
    $everfresh_page_count = 10;
}

/** @param mixed $value */
function everfresh_PostDate($value)
{
    if (!is_scalar($value)) {
        return '';
    }

    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    return $value;
}

/**
 * 取 POST 里的整数并钳制范围。
 * 直接 `(int) GetVars()` 在收到 `?daily_count[]=1` 这类数组参数时会得到 1，静默写错配置，
 * 所以这里先做标量判断再转换。
 *
 * @param string $key
 * @param int    $min
 * @param int    $max
 */
function everfresh_PostInt($key, $min, $max)
{
    $value = GetVars($key, 'POST');
    if (!is_scalar($value)) {
        $value = $min;
    }

    $value = (int) $value;
    if ($value < $min) {
        $value = $min;
    }
    if ($value > $max) {
        $value = $max;
    }

    return $value;
}

require_once dirname(__FILE__) . '/inc/updater.php';

/** 仓库地址：允许直接粘 GitHub 链接，自动抽出 owner/repo */
function everfresh_PostRepo($value)
{
    if (!is_scalar($value)) {
        return 'hexoblog-web-site/Everfresh';
    }

    $value = trim((string) $value);
    if (preg_match('#github\.com/([A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100})#i', $value, $m)) {
        $value = $m[1];
    }
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', $value)) {
        return 'hexoblog-web-site/Everfresh';
    }

    return $value;
}

/** 分支名 */
function everfresh_PostBranch($value)
{
    if (!is_scalar($value)) {
        return 'main';
    }

    $value = trim((string) $value);
    if ($value === '' || strlen($value) > 100 || strpos($value, '..') !== false || !preg_match('#^[A-Za-z0-9._/-]+$#', $value)) {
        return 'main';
    }

    return $value;
}

if (count($_POST) > 0) {
    CheckIsRefererValid();

    $action = (string) GetVars('everfresh_action', 'POST');
    $cfg = $zbp->Config('everfresh');

    // 检查 / 更新 / 回滚：只处理这三件事，不碰其它设置
    if (in_array($action, array('check', 'update', 'rollback'), true)) {
        everfresh_UpdateHandleAction($cfg, $action, (string) GetVars('backup', 'POST'));
        Redirect('./main.php');
    }

    $cfg->enable = isset($_POST['enable']) ? 1 : 0;

    $mode = GetVars('mode', 'POST');
    $mode = is_string($mode) ? $mode : '';
    $cfg->mode = in_array($mode, array('pool', 'order'), true) ? $mode : 'pool';

    $cfg->start_id = everfresh_PostInt('start_id', 1, 9999999);

    // 只做范围钳制，不再按每页篇数压缩：每页篇数由主题/站点设置决定，
    // 与后台读到的值不一定一致，硬压缩会让「填了不生效」。
    $cfg->daily_count = everfresh_PostInt('daily_count', 1, 50);
    $cfg->keep_latest = everfresh_PostInt('keep_latest', 0, 50);

    $dateMode = GetVars('date_mode', 'POST');
    $dateMode = is_string($dateMode) ? $dateMode : '';
    $cfg->date_mode = in_array($dateMode, array('shift', 'keep'), true) ? $dateMode : 'shift';

    $cfg->day_hours = everfresh_PostInt('day_hours', 1, 24);

    $cfg->start_date = everfresh_PostDate(GetVars('start_date', 'POST'));
    $cfg->end_date = everfresh_PostDate(GetVars('end_date', 'POST'));

    $cfg->github_repo = everfresh_PostRepo(GetVars('github_repo', 'POST'));
    $cfg->github_branch = everfresh_PostBranch(GetVars('github_branch', 'POST'));
    $cfg->update_auto_check = isset($_POST['update_auto_check']) ? 1 : 0;
    $cfg->update_interval = everfresh_PostInt('update_interval', 1, 168);

    $zbp->SaveConfig('everfresh');
    $zbp->SetHint('good');
    Redirect('./main.php');
}

$cfg = $zbp->Config('everfresh');

// 到点就静默查一次（只查询，不自动安装），最多 6 小时一次
everfresh_UpdateMaybeAuto($cfg);

$everfresh_update_state = everfresh_UpdateStateRead();
$everfresh_local_ver = everfresh_UpdateVersion();
$everfresh_has_new = everfresh_UpdateHasNew($everfresh_update_state);
$everfresh_backups = everfresh_UpdateBackups();
$everfresh_remote = isset($everfresh_update_state['remote']) && is_array($everfresh_update_state['remote'])
    ? $everfresh_update_state['remote']
    : array();
$everfresh_remote_ver = isset($everfresh_remote['version']) ? (string) $everfresh_remote['version'] : '';

$everfresh_stat = everfresh_Stat($cfg);
$blogtitle = $everfresh_lang['settings'];

require $blogpath . 'zb_system/admin/admin_header.php';
require $blogpath . 'zb_system/admin/admin_top.php';
?>
<link rel="stylesheet" type="text/css" href="<?php echo htmlspecialchars($bloghost . 'zb_users/plugin/everfresh/style/style.css', ENT_QUOTES, 'UTF-8'); ?>" />
<div id="divMain">
  <div class="divHeader"><?php echo htmlspecialchars($blogtitle, ENT_QUOTES, 'UTF-8'); ?></div>
  <div class="SubMenu"></div>
  <div id="divMain2" class="everfresh-settings">
    <form method="post" action="./main.php" autocomplete="off">
      <input type="hidden" name="csrfToken" value="<?php echo $zbp->GetCSRFToken(); ?>" />
      <table class="tableFull tableBorder table_hover table_striped everfresh-table">
        <tbody>
          <tr>
            <th><?php echo htmlspecialchars($everfresh_lang['enable'], ENT_QUOTES, 'UTF-8'); ?></th>
            <td>
              <label for="everfresh-enable">
                <input type="checkbox" id="everfresh-enable" name="enable" value="1"<?php echo !empty($cfg->enable) ? ' checked="checked"' : ''; ?> />
                <?php echo htmlspecialchars($everfresh_lang['enable_note'], ENT_QUOTES, 'UTF-8'); ?>
              </label>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-mode"><?php echo htmlspecialchars($everfresh_lang['mode'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <select id="everfresh-mode" name="mode" class="edit everfresh-select">
                <option value="pool"<?php echo $cfg->mode === 'pool' ? ' selected="selected"' : ''; ?>><?php echo htmlspecialchars($everfresh_lang['mode_pool'], ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="order"<?php echo $cfg->mode === 'order' ? ' selected="selected"' : ''; ?>><?php echo htmlspecialchars($everfresh_lang['mode_order'], ENT_QUOTES, 'UTF-8'); ?></option>
              </select>
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['mode_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-start-id"><?php echo htmlspecialchars($everfresh_lang['start_id'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <input type="number" id="everfresh-start-id" name="start_id" class="text everfresh-num-md" min="1" max="9999999" step="1" value="<?php echo (int) $cfg->start_id; ?>" />
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['start_id_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-daily"><?php echo htmlspecialchars($everfresh_lang['daily_count'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <span class="everfresh-inline">
                <input type="number" id="everfresh-daily" name="daily_count" class="text everfresh-num-sm" min="1" max="50" step="1" value="<?php echo (int) $cfg->daily_count; ?>" />
                <span class="everfresh-sep">+</span>
                <label for="everfresh-keep"><?php echo htmlspecialchars($everfresh_lang['keep_latest'], ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="number" id="everfresh-keep" name="keep_latest" class="text everfresh-num-sm" min="0" max="50" step="1" value="<?php echo (int) $cfg->keep_latest; ?>" />
                <span class="everfresh-sep">=</span>
                <strong id="everfresh-page-count"><?php echo (int) $everfresh_page_count; ?></strong>
                <span class="everfresh-hint"><?php echo htmlspecialchars($everfresh_lang['page_count_hint'], ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['daily_count_note'], ENT_QUOTES, 'UTF-8'); ?></p>
              <p class="everfresh-note everfresh-note-tight"><?php echo htmlspecialchars($everfresh_lang['keep_latest_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-date"><?php echo htmlspecialchars($everfresh_lang['date_mode'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <select id="everfresh-date" name="date_mode" class="edit everfresh-select">
                <option value="shift"<?php echo $cfg->date_mode === 'shift' ? ' selected="selected"' : ''; ?>><?php echo htmlspecialchars($everfresh_lang['date_mode_shift'], ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="keep"<?php echo $cfg->date_mode === 'keep' ? ' selected="selected"' : ''; ?>><?php echo htmlspecialchars($everfresh_lang['date_mode_keep'], ENT_QUOTES, 'UTF-8'); ?></option>
              </select>
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['date_mode_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-hours"><?php echo htmlspecialchars($everfresh_lang['day_hours'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <span class="everfresh-inline">
                <input type="number" id="everfresh-hours" name="day_hours" class="text everfresh-num-sm" min="1" max="24" step="1" value="<?php echo (int) $cfg->day_hours; ?>" />
                <span class="everfresh-hint"><?php echo htmlspecialchars($everfresh_lang['day_hours_unit'], ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['day_hours_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-start-date"><?php echo htmlspecialchars($everfresh_lang['cycle_range'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <span class="everfresh-inline">
                <input type="date" id="everfresh-start-date" name="start_date" class="text everfresh-date" value="<?php echo htmlspecialchars((string) $cfg->start_date, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($everfresh_stat['start'], ENT_QUOTES, 'UTF-8'); ?>" />
                <span class="everfresh-sep">→</span>
                <input type="date" id="everfresh-end-date" name="end_date" class="text everfresh-date" value="<?php echo htmlspecialchars((string) $cfg->end_date, ENT_QUOTES, 'UTF-8'); ?>" />
              </span>
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['cycle_range_note'], ENT_QUOTES, 'UTF-8'); ?></p>
              <p id="everfresh-stat" class="everfresh-stat">
                <?php
                echo htmlspecialchars(sprintf(
                    $everfresh_lang['cycle_stat'],
                    (int) $everfresh_stat['total'],
                    (int) $everfresh_stat['daily'],
                    (int) $everfresh_stat['cycle'],
                    $everfresh_stat['start'],
                    $everfresh_stat['end'] !== '' ? $everfresh_stat['end'] : $everfresh_lang['cycle_loop']
                ), ENT_QUOTES, 'UTF-8');
                ?>
              </p>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-repo"><?php echo htmlspecialchars($everfresh_lang['update_repo'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <span class="everfresh-inline">
                <input type="text" id="everfresh-repo" name="github_repo" class="text everfresh-repo" value="<?php echo htmlspecialchars((string) $cfg->github_repo, ENT_QUOTES, 'UTF-8'); ?>" spellcheck="false" />
                <label for="everfresh-branch"><?php echo htmlspecialchars($everfresh_lang['update_branch'], ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" id="everfresh-branch" name="github_branch" class="text everfresh-num-sm" value="<?php echo htmlspecialchars((string) $cfg->github_branch, ENT_QUOTES, 'UTF-8'); ?>" spellcheck="false" />
              </span>
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['update_repo_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            </td>
          </tr>
          <tr>
            <th>
              <label for="everfresh-auto"><?php echo htmlspecialchars($everfresh_lang['update_auto'], ENT_QUOTES, 'UTF-8'); ?></label>
            </th>
            <td>
              <span class="everfresh-inline">
                <label for="everfresh-auto">
                  <input type="checkbox" id="everfresh-auto" name="update_auto_check" value="1"<?php echo !empty($cfg->update_auto_check) ? ' checked="checked"' : ''; ?> />
                  <?php echo htmlspecialchars($everfresh_lang['update_auto_note'], ENT_QUOTES, 'UTF-8'); ?>
                </label>
                <input type="number" id="everfresh-interval" name="update_interval" class="text everfresh-num-sm" min="1" max="168" step="1" value="<?php echo (int) $cfg->update_interval; ?>" />
                <span class="everfresh-hint"><?php echo htmlspecialchars($everfresh_lang['update_interval_unit'], ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
              <p class="everfresh-note"><?php echo htmlspecialchars($everfresh_lang['update_auto_tip'], ENT_QUOTES, 'UTF-8'); ?></p>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="everfresh-actions">
        <button type="submit" class="everfresh-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          <span><?php echo htmlspecialchars($everfresh_lang['save'], ENT_QUOTES, 'UTF-8'); ?></span>
        </button>
      </p>
    </form>

    <div class="everfresh-update">
      <div class="everfresh-update-head">
        <strong><?php echo htmlspecialchars($everfresh_lang['update_section'], ENT_QUOTES, 'UTF-8'); ?></strong>
        <span class="everfresh-badge">v<?php echo htmlspecialchars($everfresh_local_ver, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php if ($everfresh_has_new) { ?>
          <span class="everfresh-badge is-new">
            <?php
            echo htmlspecialchars(sprintf(
                $everfresh_lang['update_have_new'],
                $everfresh_remote_ver !== '' ? $everfresh_remote_ver : '?'
            ), ENT_QUOTES, 'UTF-8');
            ?>
          </span>
        <?php } elseif (!empty($everfresh_remote['sha'])) { ?>
          <span class="everfresh-badge is-ok"><?php echo htmlspecialchars($everfresh_lang['update_is_latest'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php } ?>
      </div>

      <p class="everfresh-update-meta">
        <?php
        $everfresh_checked = !empty($everfresh_update_state['checked_at']) ? (int) $everfresh_update_state['checked_at'] : 0;
        $everfresh_checked_txt = $everfresh_checked > 0
            ? date('Y-m-d H:i', $everfresh_checked)
            : $everfresh_lang['update_never_checked'];
        echo htmlspecialchars($everfresh_lang['update_last_check'], ENT_QUOTES, 'UTF-8') . '：' . htmlspecialchars($everfresh_checked_txt, ENT_QUOTES, 'UTF-8');

        if (!empty($everfresh_remote['sha'])) {
            $everfresh_remote_date = !empty($everfresh_remote['date']) ? strtotime((string) $everfresh_remote['date']) : false;
            echo ' ｜ ' . htmlspecialchars($everfresh_lang['update_remote_commit'], ENT_QUOTES, 'UTF-8') . '：'
                . '<code>' . htmlspecialchars(substr((string) $everfresh_remote['sha'], 0, 7), ENT_QUOTES, 'UTF-8') . '</code>';
            if ($everfresh_remote_date !== false) {
                echo '（' . htmlspecialchars(date('Y-m-d', $everfresh_remote_date), ENT_QUOTES, 'UTF-8') . '）';
            }
            if (!empty($everfresh_remote['msg'])) {
                echo ' ' . htmlspecialchars((string) $everfresh_remote['msg'], ENT_QUOTES, 'UTF-8');
            }
        }
        ?>
      </p>

      <?php if (!empty($everfresh_update_state['error'])) { ?>
        <p class="everfresh-update-alert is-bad"><?php echo htmlspecialchars((string) $everfresh_update_state['error'], ENT_QUOTES, 'UTF-8'); ?></p>
      <?php } ?>

      <?php if (!empty($everfresh_update_state['last_message'])) { ?>
        <p class="everfresh-update-alert <?php echo !empty($everfresh_update_state['last_message_ok']) ? 'is-ok' : 'is-bad'; ?>">
          <?php echo htmlspecialchars((string) $everfresh_update_state['last_message'], ENT_QUOTES, 'UTF-8'); ?>
          <span class="everfresh-hint">
            <?php echo htmlspecialchars(!empty($everfresh_update_state['last_message_at']) ? date('Y-m-d H:i', (int) $everfresh_update_state['last_message_at']) : '', ENT_QUOTES, 'UTF-8'); ?>
          </span>
        </p>
      <?php } ?>

      <form method="post" action="./main.php" class="everfresh-update-actions" id="everfresh-update-form">
        <input type="hidden" name="csrfToken" value="<?php echo $zbp->GetCSRFToken(); ?>" />
        <button type="submit" name="everfresh_action" value="check" class="everfresh-btn"><?php echo htmlspecialchars($everfresh_lang['update_btn_check'], ENT_QUOTES, 'UTF-8'); ?></button>
        <button type="submit" name="everfresh_action" value="update" class="everfresh-btn is-primary"><?php echo htmlspecialchars($everfresh_lang['update_btn_apply'], ENT_QUOTES, 'UTF-8'); ?></button>
        <span class="everfresh-hint"><?php echo htmlspecialchars($everfresh_lang['update_tip'], ENT_QUOTES, 'UTF-8'); ?></span>
      </form>

      <?php if (count($everfresh_backups) > 0) { ?>
        <div class="everfresh-backups">
          <span class="everfresh-hint"><?php echo htmlspecialchars($everfresh_lang['update_backups'], ENT_QUOTES, 'UTF-8'); ?></span>
          <?php foreach ($everfresh_backups as $everfresh_bk) { ?>
            <form method="post" action="./main.php" class="everfresh-backup-item">
              <input type="hidden" name="csrfToken" value="<?php echo $zbp->GetCSRFToken(); ?>" />
              <input type="hidden" name="backup" value="<?php echo htmlspecialchars($everfresh_bk['name'], ENT_QUOTES, 'UTF-8'); ?>" />
              <button type="submit" name="everfresh_action" value="rollback" class="everfresh-btn is-ghost"><?php echo htmlspecialchars($everfresh_bk['name'], ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
          <?php } ?>
        </div>
      <?php } ?>
    </div>

    <p class="everfresh-footer-note"><?php echo htmlspecialchars($everfresh_lang['home_note'], ENT_QUOTES, 'UTF-8'); ?></p>
    <script type="text/javascript">
      (function () {
        var pageCount = <?php echo (int) $everfresh_page_count; ?>;
        var poolTotal = <?php echo (int) $everfresh_stat['total']; ?>;
        var statTpl = <?php echo json_encode($everfresh_lang['cycle_stat'], JSON_UNESCAPED_UNICODE); ?>;
        var loopText = <?php echo json_encode($everfresh_lang['cycle_loop'], JSON_UNESCAPED_UNICODE); ?>;
        var defaultStart = <?php echo json_encode($everfresh_stat['start']); ?>;

        var daily = document.getElementById('everfresh-daily');
        var keep = document.getElementById('everfresh-keep');
        var startInput = document.getElementById('everfresh-start-date');
        var endInput = document.getElementById('everfresh-end-date');
        var statBox = document.getElementById('everfresh-stat');
        if (!daily || !keep) { return; }

        function pad(n) {
          return (n < 10 ? '0' : '') + n;
        }

        function fmt(d) {
          return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        }

        function parseDate(str) {
          var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(str || '');
          if (!m) { return null; }
          var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
          return isNaN(d.getTime()) ? null : d;
        }

        function intVal(el, def, min, max) {
          var v = parseInt(el.value, 10);
          if (isNaN(v)) { return def; }
          if (v < min) { v = min; }
          if (v > max) { v = max; }
          return v;
        }

        function refresh() {
          var d = intVal(daily, 1, 1, 50);
          var total = poolTotal > 0 ? poolTotal : 0;
          var cycle = total > 0 ? Math.max(1, Math.ceil(total / d)) : 0;
          var start = parseDate(startInput ? startInput.value : '') || parseDate(defaultStart);

          var startStr = start ? fmt(start) : '';
          // 结束日期留空 = 一直循环，不再自动推算终点
          var endStr = (endInput && endInput.value) ? endInput.value : loopText;
          if (endInput && !endInput.value) {
            endInput.placeholder = loopText;
          }
          if (statBox && cycle > 0) {
            var i = 0;
            var args = [total, d, cycle, startStr, endStr];
            statBox.textContent = statTpl.replace(/%[ds]/g, function () {
              return args[i++];
            });
          }
        }

        daily.addEventListener('input', function () {
          var v = intVal(daily, 1, 1, 50);
          keep.value = Math.max(0, pageCount - v);
          refresh();
        });

        keep.addEventListener('input', function () {
          var v = intVal(keep, 0, 0, 50);
          daily.value = Math.max(1, pageCount - v);
          refresh();
        });

        if (startInput) {
          startInput.addEventListener('change', refresh);
        }

        refresh();
      })();
    </script>
    <script type="text/javascript">
      (function () {
        var form = document.getElementById('everfresh-update-form');
        if (!form) { return; }
        form.addEventListener('submit', function (e) {
          var btn = e.submitter || null;
          var all = form.querySelectorAll('button');
          for (var i = 0; i < all.length; i++) {
            /* 不能禁用当前按钮：它的 name/value 就是这次要提交的动作 */
            if (all[i] !== btn) { all[i].disabled = true; }
          }
          if (btn) {
            btn.classList.add('is-loading');
            btn.textContent = <?php echo json_encode($everfresh_lang['update_running'], JSON_UNESCAPED_UNICODE); ?>;
          }
        });
      })();
    </script>
    <script type="text/javascript">ActiveLeftMenu("aPluginMng");</script>
    <script type="text/javascript">AddHeaderIcon("<?php echo $bloghost . 'zb_users/plugin/everfresh/logo.png'; ?>");</script>
  </div>
</div>
<?php
require $blogpath . 'zb_system/admin/admin_footer.php';
RunTime();
