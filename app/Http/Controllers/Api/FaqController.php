<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FaqService;
use Illuminate\Http\JsonResponse;

class FaqController extends Controller
{
    public function __construct(private FaqService $faqService) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->faqService->published(),
        ]);
    }
}
