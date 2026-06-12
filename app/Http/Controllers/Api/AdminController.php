<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminAiChatApplyRequest;
use App\Http\Requests\AdminAiChatParseRequest;
use App\Http\Requests\AdminSendPushRequest;
use App\Http\Requests\ReorderFaqItemsRequest;
use App\Http\Requests\StoreFaqItemRequest;
use App\Http\Requests\UpdateAppSettingsRequest;
use App\Http\Requests\UpdateFaqItemRequest;
use App\Http\Requests\UpdateFeedbackRequest;
use App\Models\FaqItem;
use App\Models\FeedbackMessage;
use App\Models\FuelImportQueueItem;
use App\Models\PushSubscription;
use App\Models\Report;
use App\Models\StationCorrection;
use App\Services\AdminFuelAiService;
use App\Services\AdminReportService;
use App\Services\AppSettingsService;
use App\Services\FaqService;
use App\Services\FeedbackService;
use App\Services\SystemMetricsService;
use App\Services\VisitorStatsService;
use App\Services\StationCorrectionService;
use App\Services\SevtechFuelSyncService;
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
        private FaqService $faqService,
        private AppSettingsService $appSettings,
        private AdminReportService $reportService,
        private StationImportService $importService,
        private VisitorStatsService $visitorStats,
        private SystemMetricsService $systemMetrics,
        private AdminFuelAiService $fuelAi,
        private SevtechFuelSyncService $sevtechFuel,
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
        $visitors = $this->visitorStats->headlineCounts();

        return response()->json([
            'data' => [
                'pending_corrections' => count($this->correctionService->allPending()),
                'new_feedback' => $this->feedbackService->newCount(),
                'visible_reports' => $this->reportService->visibleCount(),
                'hidden_reports' => $this->reportService->hiddenCount(),
                'push_subscriptions' => PushSubscription::query()->count(),
                'visitors_today' => $visitors['today'],
                'visitors_yesterday' => $visitors['yesterday'],
            ],
        ]);
    }

    public function analytics(): JsonResponse
    {
        $visitors = $this->visitorStats->headlineCounts();

        return response()->json([
            'data' => [
                'visitors_today' => $visitors['today'],
                'visitors_yesterday' => $visitors['yesterday'],
                'visitors_daily' => $this->visitorStats->dailyBreakdown(30),
                'summary' => $this->visitorStats->periodSummary(30),
            ],
        ]);
    }

    public function system(): JsonResponse
    {
        return response()->json([
            'data' => $this->systemMetrics->snapshot(),
        ]);
    }

    public function aiChatStatus(): JsonResponse
    {
        $configured = is_string(config('ai.api_key')) && config('ai.api_key') !== '';

        return response()->json([
            'data' => [
                'configured' => $configured,
                'model' => config('ai.model'),
            ],
        ]);
    }

    public function aiChatParse(AdminAiChatParseRequest $request): JsonResponse
    {
        try {
            $preview = $this->fuelAi->parseAndMatch($request->validated('message'));

            return response()->json(['data' => $preview]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function aiChatApply(AdminAiChatApplyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->fuelAi->apply(
            $validated['items'],
            $validated['queue_ids'] ?? [],
        );

        return response()->json([
            'message' => "Создано отчётов: {$result['created']}",
            'data' => $result,
        ]);
    }

    public function aiChatQueue(): JsonResponse
    {
        return response()->json([
            'data' => $this->fuelAi->listQueue(),
        ]);
    }

    public function aiChatQueueDestroy(FuelImportQueueItem $queueItem): JsonResponse
    {
        $this->fuelAi->removeFromQueue($queueItem->id);

        return response()->json(['message' => 'Удалено из очереди']);
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

    public function sevtechPreview(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->sevtechFuel->preview()]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function sevtechSync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_ids' => ['sometimes', 'array'],
            'station_ids.*' => ['integer', 'exists:stations,id'],
            'items' => ['sometimes', 'array'],
            'items.*.station_id' => ['required', 'integer', 'exists:stations,id'],
            'items.*.external_id' => ['sometimes', 'string'],
            'items.*.name' => ['sometimes', 'string'],
            'items.*.address' => ['sometimes', 'nullable', 'string'],
            'items.*.network' => ['sometimes', 'string'],
            'items.*.fuels' => ['required', 'array'],
            'items.*.fuels.*.fuel_type' => ['required', 'string'],
            'items.*.fuels.*.new_status' => ['sometimes', 'string'],
            'items.*.fuels.*.status' => ['sometimes', 'string'],
            'items.*.fuels.*.changed' => ['sometimes', 'boolean'],
            'items.*.fuels.*.sale_types' => ['sometimes', 'array'],
        ]);

        try {
            $result = isset($validated['items'])
                ? $this->sevtechFuel->sync([], $validated['items'])
                : $this->sevtechFuel->sync($validated['station_ids'] ?? []);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => "Создано отчётов: {$result['created']}"
                .(($result['updated_stations'] ?? []) !== [] ? ', обновлено АЗС: '.count($result['updated_stations']) : ''),
            'data' => $result,
        ]);
    }

    public function sevtechRebind(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'external_id' => ['sometimes', 'string'],
            'name' => ['sometimes', 'string'],
            'address' => ['sometimes', 'nullable', 'string'],
            'network' => ['sometimes', 'string'],
            'fuels' => ['required', 'array', 'min:1'],
            'fuels.*.fuel_type' => ['required', 'string'],
            'fuels.*.status' => ['sometimes', 'string'],
            'fuels.*.new_status' => ['sometimes', 'string'],
            'fuels.*.sale_types' => ['sometimes', 'array'],
        ]);

        try {
            return response()->json([
                'data' => $this->sevtechFuel->resolveFuels(
                    $validated['station_id'],
                    $validated['fuels'],
                    collect($validated)->only(['external_id', 'name', 'address', 'network'])->filter()->all(),
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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

    public function faq(): JsonResponse
    {
        return response()->json([
            'data' => $this->faqService->allForAdmin(),
        ]);
    }

    public function storeFaq(StoreFaqItemRequest $request): JsonResponse
    {
        FaqItem::query()->create([
            'question' => $request->validated('question'),
            'answer' => $request->validated('answer'),
            'is_published' => $request->boolean('is_published', true),
            'sort_order' => $this->faqService->nextSortOrder(),
        ]);

        return response()->json([
            'message' => 'Вопрос добавлен',
            'data' => $this->faqService->allForAdmin(),
        ], 201);
    }

    public function updateFaq(UpdateFaqItemRequest $request, FaqItem $faq): JsonResponse
    {
        $faq->update($request->validated());

        return response()->json([
            'message' => 'Вопрос обновлён',
            'data' => $this->faqService->allForAdmin(),
        ]);
    }

    public function destroyFaq(FaqItem $faq): JsonResponse
    {
        $faq->delete();

        return response()->json([
            'message' => 'Вопрос удалён',
            'data' => $this->faqService->allForAdmin(),
        ]);
    }

    public function reorderFaq(ReorderFaqItemsRequest $request): JsonResponse
    {
        $this->faqService->reorder($request->validated('ids'));

        return response()->json([
            'message' => 'Порядок сохранён',
            'data' => $this->faqService->allForAdmin(),
        ]);
    }
}
