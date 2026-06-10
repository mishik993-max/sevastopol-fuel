<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use App\Support\VapidKeys;
use Illuminate\Console\Command;

class SendTestPush extends Command
{
    protected $signature = 'webpush:test {--title= : Заголовок} {--body= : Текст}';

    protected $description = 'Send a test push to all subscriptions and show delivery results';

    public function handle(WebPushService $webPush): int
    {
        try {
            VapidKeys::config();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $total = PushSubscription::query()->count();

        if ($total === 0) {
            $this->warn('Подписок в БД нет. Включите уведомления на сайте (кнопка «Включить»).');

            return self::FAILURE;
        }

        $title = $this->option('title') ?: 'Тест sevazs.ru';
        $body = $this->option('body') ?: 'Если видите это - push работает';

        $this->line("Подписок: {$total}");
        $this->line('Отправка…');

        $delivered = $webPush->broadcast(
            $title,
            $body,
            rtrim((string) config('app.url'), '/').'/',
        );

        if ($delivered === 0) {
            $this->error("Доставлено 0 из {$total}.");
            $this->line('Смотрите storage/logs/laravel.log (строки «Push failed» или «VAPID»).');
            $this->line('Частые причины:');
            $this->line('  - VAPID ключи в .env не совпадают с подпиской (перевключите push на телефоне)');
            $this->line('  - подписка устарела (410 Gone) - удалите и включите заново');
            $this->line('  - после смены .env: php artisan config:cache');

            return self::FAILURE;
        }

        $this->info("Доставлено {$delivered} из {$total}.");

        if ($delivered < $total) {
            $this->warn('Не всем дошло - проверьте laravel.log');
        }

        return self::SUCCESS;
    }
}
