<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Support\VapidKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function subscribe(string $endpoint, string $publicKey, string $authToken, ?string $clientId = null): PushSubscription
    {
        VapidKeys::assertValidBase64Url($publicKey, 'keys.p256dh');
        VapidKeys::assertValidBase64Url($authToken, 'keys.auth');

        return PushSubscription::query()->updateOrCreate(
            ['endpoint' => $endpoint],
            array_filter([
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'client_id' => $clientId,
            ], static fn ($value) => $value !== null),
        );
    }

    public function attachClientId(PushSubscription $subscription, string $clientId): PushSubscription
    {
        if ($subscription->client_id !== $clientId) {
            $subscription->update(['client_id' => $clientId]);
        }

        return $subscription->refresh();
    }

    public function unsubscribe(string $endpoint): void
    {
        PushSubscription::query()->where('endpoint', $endpoint)->delete();
    }

    public function broadcast(string $title, string $body, ?string $url = null): int
    {
        return $this->sendTo(
            PushSubscription::query()->cursor(),
            $title,
            $body,
            $url,
            'sevazs-qr',
        );
    }

    /**
     * @param  iterable<int, PushSubscription>|Collection<int, PushSubscription>  $subscriptions
     */
    public function sendTo(
        iterable $subscriptions,
        string $title,
        string $body,
        ?string $url = null,
        ?string $tag = null,
    ): int {
        try {
            $vapid = VapidKeys::config();
        } catch (InvalidArgumentException $e) {
            Log::warning('VAPID keys invalid, skipping push send', ['reason' => $e->getMessage()]);

            return 0;
        }

        $webPush = new WebPush([
            'VAPID' => $vapid,
        ]);

        $payload = json_encode(array_filter([
            'title' => $title,
            'body' => $body,
            'url' => self::normalizeNotificationUrl($url),
            'tag' => $tag,
        ], static fn ($value) => $value !== null && $value !== ''), JSON_UNESCAPED_UNICODE);

        $queued = 0;

        foreach ($subscriptions as $sub) {
            if (! $sub instanceof PushSubscription) {
                continue;
            }

            try {
                VapidKeys::assertSubscriptionKeys($sub->public_key, $sub->auth_token, $sub->id);

                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                ]);

                $webPush->queueNotification($subscription, $payload);
                $queued++;
            } catch (InvalidArgumentException $e) {
                Log::warning('Skipping invalid push subscription', [
                    'id' => $sub->id,
                    'reason' => $e->getMessage(),
                ]);
                PushSubscription::query()->whereKey($sub->id)->delete();
            }
        }

        if ($queued === 0) {
            return 0;
        }

        $sent = 0;

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

    private static function normalizeNotificationUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        return $url;
    }
}
