<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function __construct(private AppSettingsService $settings) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->settings->public()]);
    }
}
