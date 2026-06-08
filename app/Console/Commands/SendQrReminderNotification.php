<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\AppSettingsService;
use App\Services\WebPushService;
use Illuminate\Console\Command;

class SendQrReminderNotification extends Command
{
    protected $signature = 'notifications:qr-reminder {slot : 21_30, 21_45, 21_55 or 22_00}';

    protected $description = 'Send QR fuel reminder push for a slot (manual / testing)';

    /** @var array<string, string> */
    private const SLOT_TIMES = [
        '21_30' => '21:30',
        '21_45' => '21:45',
        '21_55' => '21:55',
        '22_00' => '22:00',
    ];

    public function handle(AppSettingsService $settings, WebPushService $webPush): int
    {
        $slot = $this->argument('slot');
        $time = self::SLOT_TIMES[$slot] ?? null;

        if ($time === null) {
            $this->error("Unknown slot: {$slot}");

            return self::FAILURE;
        }

        $reminders = $settings->get('qr_reminders', []);
        $reminder = collect($reminders)->firstWhere('time', $time);

        if ($reminder === null) {
            $this->error("No reminder configured for {$time} in app settings");

            return self::FAILURE;
        }

        $total = PushSubscription::query()->count();

        if ($total === 0) {
            $this->error('Нет подписок на push. Откройте сайт по HTTPS и нажмите «Включить» уведомления.');

            return self::FAILURE;
        }

        $this->line("Подписок: {$total}");
        $this->line("«{$reminder['title']}» — {$reminder['body']}");

        $sent = $webPush->broadcast($reminder['title'], $reminder['body']);

        if ($sent === 0) {
            $this->warn("Доставлено 0 из {$total}. Смотрите storage/logs/laravel.log (Push failed).");

            return self::FAILURE;
        }

        $this->info("Доставлено {$sent} из {$total} для слота {$slot}");

        return self::SUCCESS;
    }
}
