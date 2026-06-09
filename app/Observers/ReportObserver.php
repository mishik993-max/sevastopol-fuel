<?php

namespace App\Observers;

use App\Models\Report;
use App\Services\FavoriteFuelPushService;

class ReportObserver
{
    public function __construct(private FavoriteFuelPushService $favoriteFuelPush) {}

    public function created(Report $report): void
    {
        $this->favoriteFuelPush->handleReport($report);
    }
}
