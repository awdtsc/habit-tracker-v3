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
     * scope（morning / day / evening / night / all）に応じて
     * progress を切り替える。
     */
    public function show(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // ---------------------------------------------
        // ★ scope（未指定なら all）
        // ---------------------------------------------
        $scope = $request->query('scope', 'all');

        try {
            $payload = $this->service->buildTodayPayload(
                $user->id,
                $scope
            );

            return response()->json(
                $payload,
                200,
                [],
                JSON_UNESCAPED_UNICODE
            );

        } catch (\Throwable $e) {

            // ログに残したい場合はここで
            // logger()->error('[TodayController] load failed', [
            //     'user_id' => $user->id,
            //     'scope'   => $scope,
            //     'error'   => $e->getMessage(),
            // ]);

            return response()->json([
                'error'   => 'today_load_failed',
                'message' => 'Failed to load Today data.',
            ], 500);
        }
    }
}
