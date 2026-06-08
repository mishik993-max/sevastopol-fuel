<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function subscribe(string $endpoint, string $publicKey, string $authToken): PushSubscription
    {
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
        $publicKey = config('notifications.vapid.public_key');
        $privateKey = config('notifications.vapid.private_key');

        if (empty($publicKey) || empty($privateKey)) {
            Log::warning('VAPID keys not configured, skipping push broadcast');

            return 0;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('notifications.vapid.subject'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE);

        $sent = 0;

        foreach (PushSubscription::query()->cursor() as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => $sub->public_key,
                'authToken' => $sub->auth_token,
            ]);

            $webPush->queueNotification($subscription, $payload);
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
