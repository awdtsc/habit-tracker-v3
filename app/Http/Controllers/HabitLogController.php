<?php
// app/Http/Controllers/HabitLogController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Services\TodayProgressService;

class HabitLogController extends Controller
{
    public function __construct(
        private TodayProgressService $progressService
    ) {}

    public function toggle(Request $request, Habit $habit)
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($habit->user_id !== $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'date'   => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|in:none,done',
            'rating' => 'nullable|integer|min:0|max:4',
            'scope'  => 'required|in:morning,day,evening,night,all',
        ]);

        $date = $validated['date']
            ?? now()->timezone('Asia/Tokyo')->toDateString();

        $status = $validated['status'] ?? null;
        $rating = array_key_exists('rating', $validated) ? $validated['rating'] : null;

        $type = $habit->evaluation_type;

        // ★同一性は「habit + user + date」。
        // time_slot は属性として追従させる（後から習慣のslotを変更しても log は増やさない）
        $slot = (int)($habit->time_slot ?? 0);

        $log = $this->findOrCreateLogSafely(
            habitId: (int)$habit->id,
            userId: (int)$userId,
            date: $date,
            slot: $slot
        );

        // slot は毎回追従（属性）
        $log->time_slot = $slot;

        // SIMPLE（statusが真実）
        if ($type === 'simple') {
            if (!is_null($status)) {
                $log->status = $status;
            }
            $log->rating = null;
            $log->checked_at = now();
            $log->save();

            return $this->jsonResponse($habit, $log, $validated['scope'], $date);
        }

        // SELF（ratingが唯一の真実）
        if ($type === 'self') {
            if (is_null($rating)) {
                return response()->json([
                    'message' => 'SELF habits require rating (0-4).',
                    'errors' => ['rating' => ['rating is required for self evaluation.']],
                ], 422, [], JSON_UNESCAPED_UNICODE);
            }

            $log->rating = (int)$rating;
            $log->status = ((int)$rating === 4 ? 'done' : 'none');
            $log->checked_at = now();
            $log->save();

            return $this->jsonResponse($habit, $log, $validated['scope'], $date);
        }

        return response()->json(['message' => 'invalid evaluation_type'], 400);
    }

    /**
     * ★同一性は (habit_id, user_id, date)
     * time_slot は属性（変更されうる）
     */
    private function findOrCreateLogSafely(int $habitId, int $userId, string $date, int $slot): HabitLog
    {
        // 1) まずは完全一致（現slot）
        $exactQuery = HabitLog::where('habit_id', $habitId)
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('time_slot', $slot);

        $log = $exactQuery->first();
        if ($log) {
            return $log;
        }

        // 2) ないなら同日・同habitの最新ログ（slot違いでも拾う）
        $anyQuery = HabitLog::where('habit_id', $habitId)
            ->where('user_id', $userId)
            ->where('date', $date)
            ->orderBy('checked_at', 'desc');

        $any = $anyQuery->first();
        if ($any) {
            // 3) “slotは属性”として現在slotに寄せる（可能なら）
            try {
                $any->time_slot = $slot;
                $any->save();
                return $any;
            } catch (QueryException $e) {
                // 既に (user,habit,date,slot) が存在して衝突した場合は、
                // 現slotの行を取り直してそれを真実にする
                if ($this->isDuplicateKey($e)) {
                    $log = $exactQuery->first();
                    if ($log) return $log;
                }
                throw $e;
            }
        }

        // 4) どれも無いなら新規作成（従来通り）
        try {
            return HabitLog::create([
                'habit_id'   => $habitId,
                'user_id'    => $userId,
                'date'       => $date,
                'time_slot'  => $slot,
                'status'     => 'none',
                'rating'     => null,
                'checked_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                $log = $exactQuery->first();
                if ($log) return $log;
            }
            throw $e;
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[1]) && (int)$errorInfo[1] === 1062) {
            return true;
        }
        $msg = $e->getMessage();
        return str_contains($msg, 'Duplicate entry') || str_contains($msg, 'SQLSTATE[23000]');
    }

    /**
     * B-1 用：全スコープ progress をまとめて返す（toggle 1回で全タブ同期）
     */
    private function buildProgressByScope(int $userId, string $date): array
    {
        $scopes = ['all', 'morning', 'day', 'evening', 'night'];

        $by = [];
        foreach ($scopes as $s) {
            $by[$s] = $this->progressService->calculate($userId, $date, $s);
        }
        return $by;
    }

    private function jsonResponse(Habit $habit, HabitLog $log, string $scope, string $date)
    {
        $progressByScope = $this->buildProgressByScope($habit->user_id, $date);

        $logPayload = [
            'id'         => $log->id,
            'habit_id'   => $habit->id,
            'time_slot'  => (int)($log->time_slot ?? 0),
            'status'     => $log->status,
            'rating'     => $log->rating,
            'checked_at' => $log->checked_at,
        ];

        return response()->json([
            'habit' => [
                'id'              => $habit->id,
                'title'           => $habit->title,
                'description'     => $habit->description,
                'time_slot'       => (int)($habit->time_slot ?? 0),
                'evaluation_type' => $habit->evaluation_type,
                'target_times'    => $habit->target_times,
                'days_of_week'    => $habit->days_of_week,
                'archived'        => $habit->archived,
                'log'             => $logPayload,
            ],

            // ★互換：トップレベルにも log を出す（フロントが data.log を見ても死なない）
            'log' => $logPayload,

            // 互換：単体progress（現在タブ用）
            'progress' => $progressByScope[$scope] ?? $this->progressService->calculate(
                $habit->user_id,
                $date,
                $scope
            ),

            // ★B-1：全タブ同期の真実
            'progress_by_scope'  => $progressByScope,

            // ★B-1：古いレスポンスの上書き防止
            'progress_updated_at' => now()->timezone('Asia/Tokyo')->toIso8601String(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
