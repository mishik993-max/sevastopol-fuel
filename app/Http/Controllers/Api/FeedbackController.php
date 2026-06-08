<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\FeedbackMessage;
use App\Services\FeedbackService;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function __construct(private FeedbackService $feedbackService) {}

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $data = $request->validated();

        FeedbackMessage::query()->create([
            'type' => $data['type'],
            'message' => trim($data['message']),
            'contact' => isset($data['contact']) ? trim($data['contact']) : null,
            'status' => FeedbackStatus::New,
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Спасибо! Сообщение отправлено.',
        ], 201);
    }
}
