<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Support\VapidKeys;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function subscribe(string $endpoint, string $publicKey, string $authToken): PushSubscription
    {
        VapidKeys::assertValidBase64Url($publicKey, 'keys.p256dh');
        VapidKeys::assertValidBase64Url($authToken, 'keys.auth');

        return PushSubscription::query()->updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'public_key' => $publicKey,
                'auth_token' => $authToken,
            ]
        );
    }

    public function unsubscribe(string $endpoint): void
    {
        PushSubscription::query()->where('endpoint', $endpoint)->delete();
    }

    public function broadcast(string $title, string $body): int
    {
        try {
            $vapid = VapidKeys::config();
        } catch (InvalidArgumentException $e) {
            Log::warning('VAPID keys invalid, skipping push broadcast', ['reason' => $e->getMessage()]);

            return 0;
        }

        $webPush = new WebPush([
            'VAPID' => $vapid,
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE);

        $sent = 0;

        foreach (PushSubscription::query()->cursor() as $sub) {
            try {
                VapidKeys::assertSubscriptionKeys($sub->public_key, $sub->auth_token, $sub->id);

                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                ]);

                $webPush->queueNotification($subscription, $payload);
            } catch (InvalidArgumentException $e) {
                Log::warning('Skipping invalid push subscription', [
                    'id' => $sub->id,
                    'reason' => $e->getMessage(),
                ]);
                PushSubscription::query()->whereKey($sub->id)->delete();
            }
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            $endpoint = $report->getRequest()?->getUri()?->__toString() ?? '';

            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $endpoint)->delete();
            }

            Log::warning('Push failed', [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }
}
