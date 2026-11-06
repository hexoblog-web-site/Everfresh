<?php
if (!defined('ZBP_PATH')) {exit('Access denied');}
return array(
    'settings' => 'Everfresh Settings',
    'save' => 'Save settings',

    'enable' => 'Enable homepage gallery',
    'enable_note' => 'Uncheck to restore the original order and real dates; the plugin stays enabled.',

    'mode' => 'Rebuild mode',
    'mode_pool' => 'Rotate the pool daily',
    'mode_order' => 'Reshuffle this page only',
    'mode_note' => 'Rotating brings a different batch of articles to the top each day; reshuffling keeps the same articles and only changes their order.',

    'start_id' => 'Start post ID',
    'start_id_note' => 'Defaults to 1, queuing from the oldest post. Enter 100 to start at the first post with ID >= 100. Deleted posts leave ID gaps, so trust the pool size shown below.',

    'daily_count' => 'Posts rotated per day',
    'daily_count_note' => 'How many new articles surface on the homepage each day. Rotated + always-kept equals the posts per page.',

    'keep_latest' => 'Always keep latest',
    'keep_latest_note' => 'This many newest articles stay on the homepage every day; 0 gives all slots to rotation.',
    'page_count_hint' => '(current posts per page)',

    'date_mode' => 'List dates',
    'date_mode_shift' => 'Shift to today',
    'date_mode_keep' => 'Keep real publish dates',
    'date_mode_note' => 'Shifting only affects the homepage list; the database, article pages, RSS and sitemap keep the real timestamps.',

    'day_hours' => 'Daily publish window',
    'day_hours_unit' => 'hours after midnight',
    'day_hours_note' => 'Times of the day batch scatter between 00:00 and this hour, as if posted through the morning. Use 12 for 00:00 - 12:00.',

    'cycle_range' => 'Rotation period',
    'cycle_range_note' => 'Leave the start date empty to use today. Leave the end date empty to loop forever: one round finishes and the next starts automatically. Fill the end date to stop on that day and restore real dates.',
    'cycle_loop' => 'auto loop, no end date',

    'cycle_stat' => 'Pool has %d posts, %d rotated per day, one round takes about %d days (from %s; %s).',

    'home_note' => 'Only the first homepage page is affected; category, tag, article, search and admin lists are untouched. If the site uses full-page static cache, clear it once.',
);
