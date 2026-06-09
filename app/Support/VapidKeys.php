<?php

namespace App\Support;

use Base64Url\Base64Url;
use InvalidArgumentException;

final class VapidKeys
{
    public static function normalize(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $normalized = trim($key, " \t\n\r\0\x0B\"'");

        return $normalized === '' ? null : $normalized;
    }

    /** @return array{publicKey: string, privateKey: string, subject: string} */
    public static function config(): array
    {
        $publicKey = self::normalize(config('notifications.vapid.public_key'));
        $privateKey = self::normalize(config('notifications.vapid.private_key'));
        $subject = trim((string) config('notifications.vapid.subject'));

        if ($publicKey === null || $privateKey === null) {
            throw new InvalidArgumentException(
                'VAPID ключи не заданы. Выполните php artisan webpush:vapid и добавьте ключи в .env без кавычек в конце строки.',
            );
        }

        self::assertValidBase64Url($publicKey, 'VAPID_PUBLIC_KEY');
        self::assertValidBase64Url($privateKey, 'VAPID_PRIVATE_KEY');

        if ($subject === '') {
            throw new InvalidArgumentException('VAPID_SUBJECT не задан (например mailto:admin@sevazs.ru).');
        }

        return [
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
            'subject' => $subject,
        ];
    }

    public static function assertValidBase64Url(string $value, string $label): void
    {
        try {
            Base64Url::decode($value);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                "{$label} содержит некорректные символы base64url. Проверьте .env: без пробелов, переносов и лишних кавычек в конце строки.",
                previous: $e,
            );
        }
    }

    public static function assertSubscriptionKeys(string $publicKey, string $authToken, int $id): void
    {
        try {
            self::assertValidBase64Url($publicKey, "push_subscriptions#{$id}.public_key");
            self::assertValidBase64Url($authToken, "push_subscriptions#{$id}.auth_token");
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                "Подписка #{$id} содержит битые ключи. Удалите её и попросите пользователя включить push заново.",
                previous: $e,
            );
        }
    }
}
