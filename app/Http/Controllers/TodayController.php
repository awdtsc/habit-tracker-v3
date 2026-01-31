<?php
// app/Http/Controllers/TodayController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TodayService;
use App\Services\TodayReminderScheduler;

class TodayController extends Controller
{
    public function __construct(
        private readonly TodayService $service,
        private readonly TodayReminderScheduler $reminderScheduler,
    ) {}

    /**
     * GET /api/v1/today
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
                (int)$user->id,
                (string)$scope
            );

            // ---------------------------------------------------------
            // ★ Planなしでも「次回のpendingルート」を補完する（補助機能）
            // - 失敗しても Today は落とさない
            // ---------------------------------------------------------
            $created = 0;
            try {
                $created = $this->reminderScheduler->ensureFromTodayPayload(
                    (int)$user->id,
                    is_array($payload) ? $payload : [],
                    'Asia/Tokyo'
                );
            } catch (\Throwable $e) {
                $created = 0;
            }

            if (is_array($payload)) {
                $payload['_debug_reminders_created'] = (int)$created;
            }

            return response()->json(
                $payload,
                200,
                [],
                JSON_UNESCAPED_UNICODE
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => 'today_load_failed',
                'message' => 'Failed to load Today data.',
            ], 500);
        }
    }
}
