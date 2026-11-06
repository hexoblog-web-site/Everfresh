<?php
if (!defined('ZBP_PATH')) {exit('Access denied');}
return array(
    'settings' => '常新設定',
    'save' => '儲存設定',

    'enable' => '啟用首頁迴廊',
    'enable_note' => '取消勾選後首頁列表恢復真實順序與真實日期，外掛維持啟用狀態。',

    'mode' => '重排方式',
    'mode_pool' => '按天輪換池子裡的文章',
    'mode_order' => '只重排當前這一頁',
    'mode_note' => '輪換會每天從池子裡換一批文章上來；只重排則文章不變，僅順序每日變化。',

    'start_id' => '起始文章 ID',
    'start_id_note' => '預設 1，從最早的一篇開始排隊；填 100 就從 ID ≥ 100 的第一篇開始。刪過文章會留下 ID 空檔，實際池子大小以下方統計為準。',

    'daily_count' => '每天翻新篇數',
    'daily_count_note' => '每天往首頁換上來幾篇新面孔。翻新篇數 + 固定保留篇數 = 每頁篇數。',

    'keep_latest' => '固定保留最新',
    'keep_latest_note' => '這幾篇每天常駐首頁並顯示為最新；填 0 表示名額全部用於輪換。',
    'page_count_hint' => '（目前每頁篇數）',

    'date_mode' => '列表日期',
    'date_mode_shift' => '改寫為當天',
    'date_mode_keep' => '保持真實發佈日期',
    'date_mode_note' => '改寫只影響首頁列表的顯示，資料庫、文章頁、RSS 和 sitemap 仍是真實時間。',

    'day_hours' => '每日發佈時段',
    'day_hours_unit' => '小時（從 0 點算起）',
    'day_hours_note' => '當天這批文章的時刻散落在 0 點到這個小時之間，看起來像當天陸續發出的；填 12 就是 0:00 - 12:00。',

    'cycle_range' => '輪換起止',
    'cycle_range_note' => '起始日期留空按今天算；結束日期留空就一直循環——走完一圈自動從頭再來，不用管它。想在某天收工就填上結束日期，那天之後首頁恢復真實列表。',
    'cycle_loop' => '自動循環，不設終點',

    'cycle_stat' => '候選池 %d 篇，每天翻新 %d 篇，一輪約 %d 天（%s 起，%s）。',

    'home_note' => '只處理首頁第一頁；分類頁、標籤頁、文章頁、搜尋結果和後台列表都不受影響。若站點開了整頁靜態快取，需清一次快取才生效。',
);
