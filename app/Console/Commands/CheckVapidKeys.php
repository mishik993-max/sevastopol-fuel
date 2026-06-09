<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\PushSubscriptionWatch;
use App\Support\VapidKeys;
use Illuminate\Console\Command;

class CheckVapidKeys extends Command
{
    protected $signature = 'webpush:check';

    protected $description = 'Validate VAPID keys and push subscription records';

    public function handle(): int
    {
        try {
            $vapid = VapidKeys::config();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('VAPID ключи в .env корректны.');
        $this->line('  public:  '.self::mask($vapid['publicKey']));
        $this->line('  private: '.self::mask($vapid['privateKey']));
        $this->line('  subject: '.$vapid['subject']);

        $broken = 0;
        $total = PushSubscription::query()->count();

        foreach (PushSubscription::query()->cursor() as $sub) {
            try {
                VapidKeys::assertSubscriptionKeys($sub->public_key, $sub->auth_token, $sub->id);
            } catch (\InvalidArgumentException $e) {
                $broken++;
                $this->warn($e->getMessage());
            }
        }

        if ($total === 0) {
            $this->line('Подписок в БД: 0');
        } elseif ($broken === 0) {
            $this->info("Подписок в БД: {$total}, все ключи корректны.");
        } else {
            $this->warn("Подписок в БД: {$total}, битых: {$broken}");
        }

        $watches = PushSubscriptionWatch::query()->count();
        $this->line("Watches (избранные АЗС): {$watches}");

        if ($watches === 0 && $total > 0) {
            $this->warn('Watches пусто — см. php artisan fuel-push:check');
        }

        return $broken > 0 ? self::FAILURE : self::SUCCESS;
    }

    private static function mask(string $key): string
    {
        if (strlen($key) <= 12) {
            return $key;
        }

        return substr($key, 0, 6).'…'.substr($key, -6).' ('.strlen($key).' симв.)';
    }
}
