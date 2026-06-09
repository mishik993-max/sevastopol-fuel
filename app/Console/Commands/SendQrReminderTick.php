<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\AppSettingsService;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendQrReminderTick extends Command
{
    protected $signature = 'notifications:qr-reminder-tick';

    protected $description = 'Check app settings and send QR reminders at configured times';

    public function handle(AppSettingsService $settings, WebPushService $webPush): int
    {
        $timezone = config('notifications.timezone');
        $now = now()->timezone($timezone)->format('H:i');
        $reminders = $settings->get('qr_reminders', []);
        $sent = 0;

        foreach ($reminders as $reminder) {
            if (($reminder['time'] ?? '') !== $now) {
                continue;
            }

            $cacheKey = 'qr_reminder_sent_'.now()->timezone($timezone)->format('Y-m-d').'_'.str_replace(':', '', $reminder['time']);

            if (Cache::has($cacheKey)) {
                continue;
            }

            $total = PushSubscription::query()->count();

            if ($total === 0) {
                $this->warn("{$reminder['time']}: нет подписок на push");

                continue;
            }

            $delivered = $webPush->broadcast(
                $reminder['title'],
                $reminder['body'],
                $reminder['url'] ?? null,
            );

            if ($delivered === 0) {
                $this->warn("{$reminder['time']}: доставлено 0 из {$total} — проверьте VAPID в .env и storage/logs/laravel.log");

                continue;
            }

            Cache::put($cacheKey, true, now()->timezone($timezone)->endOfDay());
            $sent += $delivered;

            $this->info("{$reminder['time']}: доставлено {$delivered} из {$total}");
        }

        return $sent > 0 ? self::SUCCESS : self::SUCCESS;
    }
}
