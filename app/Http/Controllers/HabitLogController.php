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
    public function toggle(Request $request, Habit $habit)
    {
        $userId = Auth::id();

        if ($habit->user_id !== $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'date'   => 'required|date',
            'status' => 'nullable|in:none,done',
            'rating' => 'nullable|integer|min:0|max:4',
        ]);

        $date   = Carbon::parse($validated['date'])->toDateString();
        $status = $validated['status'] ?? null;
        $rating = array_key_exists('rating', $validated)
                    ? $validated['rating']
                    : null;

        $slot   = $habit->time_slot ?? 0;
        $type   = $habit->evaluation_type;    // ← ★ simple / self

        $log = HabitLog::where('habit_id', $habit->id)
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('time_slot', $slot)
            ->first();

        /* ===========================================================
         * EVALUATION TYPE: SIMPLE（単純評価）
         * ===========================================================*/
        if ($type === 'simple') {

            if (!$log) {
                // 新規（status が来れば作る）
                $log = HabitLog::create([
                    'habit_id'   => $habit->id,
                    'user_id'    => $userId,
                    'date'       => $date,
                    'time_slot'  => $slot,
                    'status'     => $status ?? 'none',
                    'rating'     => null,         // ★常に null
                    'checked_at' => now(),
                ]);
            } else {

                // status のみ変更
                if (!is_null($status)) {
                    $log->status = $status;
                }

                // rating は使わない
                $log->rating = null;

                $log->checked_at = now();
                $log->save();
            }

            return $this->jsonResponse($habit, $log);
        }

        /* ===========================================================
         * EVALUATION TYPE: SELF（自己評価 0〜4）
         * ===========================================================*/
        if ($type === 'self') {

            if (!$log) {
                // 初回ログ
                $log = HabitLog::create([
                    'habit_id'   => $habit->id,
                    'user_id'    => $userId,
                    'date'       => $date,
                    'time_slot'  => $slot,
                    'rating'     => $rating,           // ★送られてきた rating
                    'status'     => ($rating === 4 ? 'done' : 'none'),
                    'checked_at' => now(),
                ]);
            } else {

                // ⭐ rating の更新
                if (!is_null($rating)) {

                    $log->rating = $rating;

                    if ($rating === 4) {
                        $log->status = 'done';
                    } else {
                        $log->status = 'none';
                    }
                }

                // ⭐ 完了 → 未完に戻すボタン用処理
                if ($status === 'none' && $log->status === 'done') {
                    $log->status = 'none';
                    $log->rating = 0; // ★自己評価 → 0 に戻す
                }

                $log->checked_at = now();
                $log->save();
            }

            return $this->jsonResponse($habit, $log);
        }

        // 念のため
        return response()->json(['message' => 'invalid evaluation_type'], 400);
    }

    private function jsonResponse(Habit $habit, HabitLog $log)
    {
        return response()->json([
            'habit' => [
                'id'  => $habit->id,
                'log' => [
                    'id'         => $log->id,
                    'status'     => $log->status,
                    'rating'     => $log->rating,
                    'checked_at' => $log->checked_at,
                ]
            ]
        ]);
    }
}