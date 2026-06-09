<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminSendPushRequest;
use App\Http\Requests\UpdateAppSettingsRequest;
use App\Http\Requests\UpdateFeedbackRequest;
use App\Models\FeedbackMessage;
use App\Models\PushSubscription;
use App\Models\Report;
use App\Models\StationCorrection;
use App\Services\AdminReportService;
use App\Services\AppSettingsService;
use App\Services\FeedbackService;
use App\Services\StationCorrectionService;
use App\Services\StationImportService;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(
        private StationCorrectionService $correctionService,
        private FeedbackService $feedbackService,
        private AppSettingsService $appSettings,
        private AdminReportService $reportService,
        private StationImportService $importService,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $password = (string) config('admin.password');

        if ($password === '') {
            return response()->json(['message' => 'ADMIN_PASSWORD не задан в .env'], 503);
        }

        $limiterKey = 'admin-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, 15)) {
            $seconds = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'message' => "Слишком много неудачных попыток. Подождите {$seconds} сек.",
            ], 429)->header('Retry-After', (string) $seconds);
        }

        $request->validate(['password' => ['required', 'string']]);

        if (! hash_equals($password, $request->input('password'))) {
            RateLimiter::hit($limiterKey, 900);

            return response()->json(['message' => 'Неверный пароль'], 401);
        }

        RateLimiter::clear($limiterKey);

        return response()->json(['message' => 'Вход выполнен']);
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'pending_corrections' => count($this->correctionService->allPending()),
                'new_feedback' => $this->feedbackService->newCount(),
                'visible_reports' => $this->reportService->visibleCount(),
                'hidden_reports' => $this->reportService->hiddenCount(),
                'push_subscriptions' => PushSubscription::query()->count(),
            ],
        ]);
    }

    public function corrections(): JsonResponse
    {
        return response()->json([
            'data' => $this->correctionService->allPending(),
        ]);
    }

    public function applyCorrection(StationCorrection $correction): JsonResponse
    {
        $this->correctionService->forceApply($correction);

        return response()->json([
            'message' => 'Исправление применено',
            'data' => $this->correctionService->allPending(),
        ]);
    }

    public function rejectCorrection(StationCorrection $correction): JsonResponse
    {
        $this->correctionService->reject($correction);

        return response()->json([
            'message' => 'Исправление отклонено',
            'data' => $this->correctionService->allPending(),
        ]);
    }

    public function feedback(): JsonResponse
    {
        return response()->json([
            'data' => $this->feedbackService->allForAdmin(),
        ]);
    }

    public function updateFeedback(UpdateFeedbackRequest $request, FeedbackMessage $feedback): JsonResponse
    {
        $data = $request->validated();

        $feedback->update([
            'status' => $data['status'],
            'admin_note' => $data['admin_note'] ?? $feedback->admin_note,
        ]);

        return response()->json([
            'message' => 'Обновлено',
            'data' => $this->feedbackService->allForAdmin(),
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->appSettings->all()]);
    }

    public function updateSettings(UpdateAppSettingsRequest $request): JsonResponse
    {
        $this->appSettings->update($request->validated());

        return response()->json([
            'message' => 'Настройки сохранены',
            'data' => $this->appSettings->all(),
        ]);
    }

    public function reports(): JsonResponse
    {
        return response()->json([
            'data' => $this->reportService->list(),
        ]);
    }

    public function hideReport(Report $report): JsonResponse
    {
        $this->reportService->hide($report);

        return response()->json([
            'message' => 'Отчёт скрыт с карты',
            'data' => $this->reportService->list(),
        ]);
    }

    public function unhideReport(Report $report): JsonResponse
    {
        $this->reportService->unhide($report);

        return response()->json([
            'message' => 'Отчёт снова виден',
            'data' => $this->reportService->list(),
        ]);
    }

    public function destroyReport(Report $report): JsonResponse
    {
        $this->reportService->delete($report);

        return response()->json([
            'message' => 'Отчёт удалён',
            'data' => $this->reportService->list(),
        ]);
    }

    public function osmImportPreview(): JsonResponse
    {
        set_time_limit(300);

        $collected = $this->importService->collectElements();
        $preview = $this->importService->previewElements($collected['elements']);

        $token = Str::random(40);
        Cache::put('osm_import:'.$token, $collected['elements'], now()->addMinutes(30));

        return response()->json([
            'data' => array_merge($preview, [
                'source' => $collected['source'],
                'apply_token' => $token,
            ]),
        ]);
    }

    public function osmImportRun(Request $request): JsonResponse
    {
        set_time_limit(300);

        $validated = $request->validate([
            'apply_token' => ['required', 'string', 'size:40'],
            'run_sync' => ['nullable', 'boolean'],
        ]);

        $elements = Cache::pull('osm_import:'.$validated['apply_token']);

        if (! is_array($elements)) {
            return response()->json([
                'message' => 'Превью устарело - сначала загрузите превью импорта снова',
            ], 422);
        }

        $result = $this->importService->runImport($elements, (bool) ($validated['run_sync'] ?? false));

        return response()->json([
            'message' => 'Импорт выполнен',
            'data' => $result,
        ]);
    }

    public function pushStatus(): JsonResponse
    {
        return response()->json([
            'data' => [
                'subscriptions' => PushSubscription::query()->count(),
            ],
        ]);
    }

    public function sendPush(AdminSendPushRequest $request, WebPushService $webPush): JsonResponse
    {
        $total = PushSubscription::query()->count();

        if ($total === 0) {
            return response()->json([
                'message' => 'Нет подписок на push. Пользователи должны включить уведомления на сайте.',
            ], 422);
        }

        $validated = $request->validated();
        $url = isset($validated['url']) && $validated['url'] !== ''
            ? $validated['url']
            : null;

        $delivered = $webPush->broadcast(
            $validated['title'],
            $validated['body'],
            $url,
        );

        if ($delivered === 0) {
            return response()->json([
                'message' => 'Не удалось доставить ни одному подписчику. Выполните php artisan webpush:check на сервере.',
                'data' => [
                    'delivered' => 0,
                    'total' => $total,
                ],
            ], 422);
        }

        return response()->json([
            'message' => "Доставлено {$delivered} из {$total}",
            'data' => [
                'delivered' => $delivered,
                'total' => $total,
            ],
        ]);
    }
}
