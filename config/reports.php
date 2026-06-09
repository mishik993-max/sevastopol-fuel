<?php

return [
    /** Максимальный размер фото отчёта в килобайтах (Laravel `max` для файлов). */
    'photo_max_kb' => (int) env('REPORT_PHOTO_MAX_KB', 5120),
];
