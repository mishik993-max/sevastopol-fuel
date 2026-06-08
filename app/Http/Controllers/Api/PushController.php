<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PushSubscribeRequest;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function __construct(private WebPushService $webPush) {}

    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => config('notifications.vapid.public_key'),
        ]);
    }

    public function subscribe(PushSubscribeRequest $request): JsonResponse
    {
        $this->webPush->subscribe(
            $request->validated('endpoint'),
            $request->input('keys.p256dh'),
            $request->input('keys.auth'),
        );

        return response()->json(['message' => 'Подписка сохранена']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $this->webPush->unsubscribe($validated['endpoint']);

        return response()->json(['message' => 'Отписка выполнена']);
    }
}
