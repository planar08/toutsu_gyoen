<?php
// 此資料夾必須放在網站公開目錄外，與 public_html 同一層。
return [
    'db_path' => __DIR__ . '/storage/booking.sqlite',
    'session_path' => __DIR__ . '/storage/sessions',
    'require_https' => true,
    'max_nights' => 30,
    'session_idle_seconds' => 1800,
    'session_max_seconds' => 28800,
];
