<?php
// app/Http/Controllers/HabitLogController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Habit;
use App\Models\HabitLog;

class HabitLogController extends Controller
{
    /**
     * v3：TodayTab の完了トグル / 自己評価を一元処理する。
     *
     * 期待する入力（TodayTab.vue より）:
     * POST /api/habits/{habit}/toggle
     * {
     *   "date": "2025-12-10",
     *   "status": "done" | "none",
     *   "rating": null | 0-4
     * }
     */
    public function toggle(Request $request, Habit $habit)
    {
        $userId = Auth::id();

        // -----------------------------------------------
        // validate
        // -----------------------------------------------
        $dateIso = (string)$request->input('date');
        $date = Carbon::parse($dateIso)->startOfDay();
        abort_if($date->gt(Carbon::today()), 422, '未来日は記録できません。');

        $status = $request->input('status');   // 'done' or 'none'
        $rating = $request->input('rating');   // null or int

        if (!in_array($status, ['done', 'none'])) {
            abort(422, 'status は done または none です。');
        }

        // -----------------------------------------------
        // 今日の habit_log を取得（v3 は time_slot を使わない）
        // -----------------------------------------------
        $log = HabitLog::where('user_id', $userId)
            ->where('habit_id', $habit->id)
            ->where('date', $date->toDateString())
            ->first();

        // -----------------------------------------------
        // 未作成なら作成（v3 の思想と一致）
        // -----------------------------------------------
        if (!$log) {
            $log = new HabitLog();
            $log->user_id  = $userId;
            $log->habit_id = $habit->id;
            $log->date     = $date->toDateString();
        }

        // -----------------------------------------------
        // simple / self 共通の最小仕様
        // -----------------------------------------------
        if ($habit->evaluation_type === 'simple') {
            // トグル or 指定状態
            $log->status = $status;
            $log->rating = 0;
            $log->checked_at = now();

        } else {
            // self 評価型
            $ratingVal = is_numeric($rating) ? (int)$rating : 0;
            $log->rating = $ratingVal;
            $log->checked_at = now();

            // v3 では status は 2種類に統一（UIが壊れないように）
            $log->status = ($ratingVal >= 4) ? 'done' : 'none';
        }

        $log->save();

        // -----------------------------------------------
        // 返却（TodayTab.vue が期待する JSON）
        // -----------------------------------------------
        return response()->json([
            'habit' => [
                'id'        => $habit->id,
                'title'     => $habit->title,
                'time_slot' => $habit->time_slot,
                'log' => [
                    'id'         => $log->id,
                    'status'     => $log->status,
                    'rating'     => $log->rating,
                    'checked_at' => optional($log->checked_at)->toDateTimeString(),
                ],
            ]
        ]);
    }
}