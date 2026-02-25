<?php
// app/Http/Controllers/HabitLogController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HabitTime;
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

        if ((int) $habit->user_id !== (int) $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'habit_time_id' => 'required|integer',
            'date'   => 'required|date_format:Y-m-d',
            'status' => 'nullable|in:none,done',
            'rating' => 'nullable|integer|min:0|max:4',
            'scope'  => 'required|in:morning,day,evening,night,all',
        ]);

        $date = $validated['date'];

        $status = $validated['status'] ?? null;
        $rating = array_key_exists('rating', $validated) ? $validated['rating'] : null;

        $habitTimeId = (int) $validated['habit_time_id'];

        // habit_id に紐づく habit_time かどうかで絞る（挙動は同じ）
        $habitTime = HabitTime::query()
            ->where('habit_id', (int) $habit->id)
            ->whereKey($habitTimeId)
            ->first();

        if (!$habitTime) {
            return response()->json(['message' => 'Habit time not found'], 404);
        }

        $type = (string) $habit->evaluation_type;
        $slot = (int) ($habitTime->time_slot ?? 0);

        $log = $this->findOrCreateLogSafely(
            habitTimeId: $habitTimeId,
            habitId: (int) $habit->id,
            userId: (int) $userId,
            date: $date,
            slot: $slot
        );

        // 互換・安全のため、毎回属性を揃える（旧データ混在対策）
        $log->habit_id = (int) $habit->id;
        $log->habit_time_id = $habitTimeId;
        $log->time_slot = $slot;

        // SIMPLE（statusが真実）
        if ($type === 'simple') {
            if (!is_null($status)) {
                $log->status = $status;
            }
            $log->rating = null;
            $log->checked_at = now();
            $log->save();

            return $this->jsonResponse($habit, $habitTime, $log, $validated['scope'], $date);
        }

        // SELF（ratingが唯一の真実）
        if ($type === 'self') {
            if (is_null($rating)) {
                $rating = $status === 'done' ? 4 : 0;
            }

            $log->rating = (int) $rating;
            $log->status = ((int) $rating === 4 ? 'done' : 'none');
            $log->checked_at = now();
            $log->save();

            return $this->jsonResponse($habit, $habitTime, $log, $validated['scope'], $date);
        }

        return response()->json(['message' => 'invalid evaluation_type'], 400);
    }

    /**
     * ★同一性は (habit_time_id, user_id, date)
     * time_slot は属性（表示補助）
     */
    private function findOrCreateLogSafely(
        int $habitTimeId,
        int $habitId,
        int $userId,
        string $date,
        int $slot
    ): HabitLog {
        $query = HabitLog::where('habit_time_id', $habitTimeId)
            ->where('user_id', $userId)
            ->where('date', $date);

        $log = $query->first();
        if ($log) {
            return $log;
        }

        try {
            return HabitLog::create([
                'habit_id'      => $habitId,
                'habit_time_id' => $habitTimeId,
                'user_id'       => $userId,
                'date'          => $date,
                'time_slot'     => $slot,
                'status'        => 'none',
                'rating'        => null,
                'checked_at'    => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
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
        $errorInfo = $e->errorInfo ?? null;
        if (is_array($errorInfo) && isset($errorInfo[1]) && (int) $errorInfo[1] === 1062) {
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

    private function jsonResponse(Habit $habit, HabitTime $habitTime, HabitLog $log, string $scope, string $date)
    {
        $progressByScope = $this->buildProgressByScope((int) $habit->user_id, $date);

        $logPayload = [
            'id'            => $log->id,
            'habit_id'      => (int) $habit->id,
            'habit_time_id' => (int) $habitTime->id,
            'time_slot'     => (int) ($log->time_slot ?? $habitTime->time_slot ?? 0),
            'status'        => $log->status,
            'rating'        => $log->rating,
            'checked_at'    => $log->checked_at,
        ];

        return response()->json([
            'habit' => [
                'id'              => (int) $habit->id,
                'habit_time_id'   => (int) $habitTime->id,
                'title'           => $habit->title,
                'description'     => $habit->description,
                'time_slot'       => (int) ($habitTime->time_slot ?? 0),
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
                (int) $habit->user_id,
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
