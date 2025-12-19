<?php
// app/Http/Controllers/HabitLogController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;

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

        // ------------------------------
        // validate
        // ------------------------------
        $validated = $request->validate([
            'status' => 'nullable|in:none,done',
            'rating' => 'nullable|integer|min:0|max:4',
            'scope'  => 'required|in:morning,day,evening,night,all',
        ]);

        // ------------------------------
        // 今日の定義は backend が持つ
        // ------------------------------
        $date = now()->timezone('Asia/Tokyo')->toDateString();

        $status = $validated['status'] ?? null;
        $rating = array_key_exists('rating', $validated) ? $validated['rating'] : null;

        $slot = $habit->time_slot ?? 0;
        $type = $habit->evaluation_type;

        // ------------------------------
        // 競合に強い「取得 or 作成」
        //   - DB に unique がある前提で、1062 を吸収して 500 を避ける
        // ------------------------------
        $log = $this->findOrCreateLogSafely(
            habitId: $habit->id,
            userId: $userId,
            date: $date,
            slot: $slot
        );

        /* ===========================================================
         * SIMPLE（status が真実）
         * ===========================================================*/
        if ($type === 'simple') {

            if (!is_null($status)) {
                $log->status = $status;
            }
            $log->rating = null;
            $log->checked_at = now();
            $log->save();

            return $this->jsonResponse($habit, $log, $validated['scope'], $date);
        }

        /* ===========================================================
         * SELF（rating が唯一の真実）
         * ===========================================================*/
        if ($type === 'self') {

            // ★ rating が来たときだけ rating/status を更新（SELF の真実は rating）
            if (!is_null($rating)) {
                $log->rating = $rating;
                $log->status = ($rating === 4 ? 'done' : 'none');
            }

            // ★ SELF では status 操作で rating を壊さない（未完了に戻すなら rating を送る）
            if (!is_null($status) && $status === 'none' && is_null($rating)) {
                $log->status = 'none';
                // rating は触らない
            }

            $log->checked_at = now();
            $log->save();

            return $this->jsonResponse($habit, $log, $validated['scope'], $date);
        }

        return response()->json(['message' => 'invalid evaluation_type'], 400);
    }

    /**
     * habit_id + user_id + date + time_slot の1行を必ず返す
     * - 無ければ作る
     * - 競合で duplicate key(1062) が出たら、作れた側を再取得して返す（500回避）
     */
    private function findOrCreateLogSafely(int $habitId, int $userId, string $date, int $slot): HabitLog
    {
        $query = HabitLog::where('habit_id', $habitId)
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('time_slot', $slot);

        $log = $query->first();
        if ($log) {
            return $log;
        }

        // 無いので作る（ここが同時実行で競合する可能性がある）
        try {
            return HabitLog::create([
                'habit_id'   => $habitId,
                'user_id'    => $userId,
                'date'       => $date,
                'time_slot'  => $slot,

                // 初期値（後段の type ロジックで確定させる）
                'status'     => 'none',
                'rating'     => null,
                'checked_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                // 競合相手が先に作った：再取得して続行（500にしない）
                $log = $query->first();
                if ($log) {
                    return $log;
                }
            }
            throw $e;
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        // MySQL/MariaDB duplicate key: SQLSTATE[23000], errorInfo[1]=1062
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[1]) && (int) $errorInfo[1] === 1062) {
            return true;
        }
        $msg = $e->getMessage();
        return str_contains($msg, 'Duplicate entry') || str_contains($msg, 'SQLSTATE[23000]');
    }

    /* ===========================================================
     * JSON Response
     * ===========================================================*/
    private function jsonResponse(Habit $habit, HabitLog $log, string $scope, string $date)
    {
        return response()->json([
            'habit' => [
                'id'              => $habit->id,
                'title'           => $habit->title,
                'description'     => $habit->description,
                'time_slot'       => $habit->time_slot,
                'evaluation_type' => $habit->evaluation_type,
                'target_times'    => $habit->target_times,
                'days_of_week'    => $habit->days_of_week,
                'archived'        => $habit->archived,
                'log' => [
                    'id'         => $log->id,
                    'habit_id'   => $habit->id,
                    'time_slot'  => $log->time_slot,
                    'status'     => $log->status,
                    'rating'     => $log->rating,
                    'checked_at' => $log->checked_at,
                ],
            ],
            'progress' => $this->calculateProgressByScope(
                $habit->user_id,
                $date,
                $scope
            ),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /* ===========================================================
     * ★ scope 別 progress（高速化：get/whereIn を排除）
     *   - all: 全スロット（anytime含む）
     *   - 通常タブ: そのスロットのみ（anytime除外）
     * ===========================================================*/
    private function calculateProgressByScope(int $userId, string $date, string $scope): array
    {
        $slot = ($scope === 'all') ? null : $this->scopeToSlot($scope);

        // total: habits を DB で count（コレクション化しない）
        $total = Habit::where('user_id', $userId)
            ->where('archived', false)
            ->when($slot !== null, fn ($q) => $q->where('time_slot', $slot))
            ->count();

        // done: habit_logs を habits に JOIN して count（whereIn 排除）
        $done = HabitLog::query()
            ->join('habits', 'habit_logs.habit_id', '=', 'habits.id')
            ->where('habits.user_id', $userId)
            ->where('habits.archived', false)
            ->when($slot !== null, fn ($q) => $q->where('habits.time_slot', $slot))
            ->where('habit_logs.date', $date)
            ->where('habit_logs.status', 'done')
            ->count();

        return [
            'scope'   => $scope,
            'done'    => $done,
            'total'   => $total,
            'percent' => $total === 0 ? 0 : (int) round($done / $total * 100),
        ];
    }

    private function scopeToSlot(string $scope): int
    {
        return match ($scope) {
            'morning' => 1,
            'day'     => 2,
            'evening' => 3,
            'night'   => 4,
            default   => 0,
        };
    }
}
