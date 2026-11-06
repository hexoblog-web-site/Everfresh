<?php
if (!defined('ZBP_PATH')) {exit('Access denied');}
return array(
    'settings' => '常新设置',
    'save' => '保存设置',

    'enable' => '启用首页回廊',
    'enable_note' => '取消勾选后首页列表恢复真实顺序和真实日期，插件保持启用状态。',

    'mode' => '重排方式',
    'mode_pool' => '按天轮换池子里的文章',
    'mode_order' => '只重排当前这一页',
    'mode_note' => '轮换会每天从池子里换一批文章上来；只重排则文章不变，仅顺序每日变化。',

    'start_id' => '起始文章 ID',
    'start_id_note' => '默认 1，从最早的一篇开始排队；填 100 就从 ID ≥ 100 的第一篇开始。删过文章会留下 ID 空档，实际池子大小以下方统计为准。',

    'daily_count' => '每天翻新篇数',
    'daily_count_note' => '每天往首页换上来几篇新面孔。翻新篇数 + 固定保留篇数 = 每页篇数。',

    'keep_latest' => '固定保留最新',
    'keep_latest_note' => '这几篇每天常驻首页并显示为最新；填 0 表示名额全部用于轮换。',
    'page_count_hint' => '（当前每页篇数）',

    'date_mode' => '列表日期',
    'date_mode_shift' => '改写为当天',
    'date_mode_keep' => '保持真实发布日期',
    'date_mode_note' => '改写只影响首页列表的显示，数据库、文章页、RSS 和 sitemap 仍是真实时间。',

    'day_hours' => '每日发布时段',
    'day_hours_unit' => '小时（从 0 点算起）',
    'day_hours_note' => '当天这批文章的时刻散落在 0 点到这个小时之间，看起来像当天陆续发出的；填 12 就是 0:00 - 12:00。',

    'cycle_range' => '轮换起止',
    'cycle_range_note' => '起始日期留空按今天算；结束日期留空就一直循环——走完一圈自动从头再来，不用管它。想在某天收工就填上结束日期，那天之后首页恢复真实列表。',
    'cycle_loop' => '自动循环，不设终点',

    'cycle_stat' => '候选池 %d 篇，每天翻新 %d 篇，一轮约 %d 天（%s 起，%s）。',

    'update_section' => 'GitHub 自动更新',
    'update_repo' => 'GitHub 仓库',
    'update_repo_note' => '填 owner/repo，也可以直接粘仓库链接，例如 hexoblog-web-site/Everfresh。格式不对会退回默认仓库。',
    'update_branch' => '分支',
    'update_auto' => '自动检查更新',
    'update_auto_note' => '后台打开时自动检查，每',
    'update_interval_unit' => '小时一次',
    'update_auto_tip' => '只查询有没有新版并提示，不会自动安装。要真正升级请点下面的「立即更新」。',
    'update_have_new' => '有新版 %s',
    'update_is_latest' => '已是最新',
    'update_last_check' => '上次检查',
    'update_never_checked' => '尚未检查',
    'update_remote_commit' => '最新提交',
    'update_btn_check' => '检查更新',
    'update_btn_apply' => '立即更新',
    'update_tip' => '更新前自动备份、失败自动回滚；只覆盖插件自身的程序文件，设置存在数据库里不受影响。',
    'update_backups' => '可用备份（点击回滚）：',
    'update_running' => '处理中…',

    'home_note' => '只处理首页第一页；分类页、标签页、文章页、搜索结果和后台列表都不受影响。若站点开了整页静态缓存，需清一次缓存才生效。',
);
