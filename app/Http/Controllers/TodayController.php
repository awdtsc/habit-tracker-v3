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
            // 👇 ここが rating を含む TodayPayload を生成する
            $payload = $this->service->buildTodayPayload($user->id);

            return response()->json(
                $payload,
                200,
                [],
                JSON_UNESCAPED_UNICODE
            );

        } catch (\Throwable $e) {

            // rating 取得含む Today の生成に失敗した場合
            return response()->json([
                'error'   => 'today_load_failed',
                'message' => 'Failed to load Today data.',
            ], 500);
        }
    }
}