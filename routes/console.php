<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:qr-reminder-tick')
    ->everyMinute()
    ->timezone(config('notifications.timezone'));

if ((int) config('sevtech.schedule_minutes') > 0) {
    Schedule::command('stations:sync-sevtech')
        ->cron('*/'.(int) config('sevtech.schedule_minutes').' * * * *')
        ->timezone(config('app.timezone'));
}
