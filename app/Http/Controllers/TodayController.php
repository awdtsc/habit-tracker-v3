<?php
// app/Http/Controllers/TodayController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TodayService;

class TodayController extends Controller
{
    protected TodayService $service;

    public function __construct(TodayService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/today
     *
     * TodayTab 用データ。
     * ロジックはすべて TodayService に集約。
     */
    public function show(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $payload = $this->service->buildTodayPayload($user->id);

            return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {

            // 本来は logger->error() で記録するべき
            return response()->json([
                'error'   => 'today_load_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}