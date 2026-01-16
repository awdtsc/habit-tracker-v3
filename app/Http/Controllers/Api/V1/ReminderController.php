<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReminderController extends Controller
{
    public function snooze(Request $request, int $task)
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $minutes = (int)($request->input('minutes', 10));
        if ($minutes < 1 || $minutes > 1440) {
            return response()->json(['message' => 'minutes must be 1..1440'], 422);
        }

        $parent = DB::table('remind_tasks')->where('id', $task)->first();
        if (!$parent) {
            return response()->json(['message' => 'task not found'], 404);
        }

        if (in_array((string)$parent->status, ['done', 'cancelled'], true)) {
            return response()->json(['message' => 'series already finished'], 409);
        }

        if (empty($parent->habit_time_id)) {
            return response()->json(['message' => 'habit_time_id missing'], 500);
        }

        $ht = DB::table('habit_times')->where('id', (int)$parent->habit_time_id)->first();
        if (!$ht) {
            return response()->json(['message' => 'habit_time not found'], 404);
        }

        $habit = DB::table('habits')->where('id', (int)$ht->habit_id)->first();
        if (!$habit || (int)$habit->user_id !== (int)$userId) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $rootId = $parent->root_task_id ? (int)$parent->root_task_id : (int)$parent->id;
        $remindAt = now()->addMinutes($minutes);

        $newId = DB::transaction(function () use ($parent, $rootId, $remindAt, $minutes) {
            if (!$parent->root_task_id) {
                DB::table('remind_tasks')->where('id', $parent->id)->update([
                    'root_task_id' => $rootId,
                    'updated_at' => now(),
                ]);
            }

            DB::table('remind_tasks')
                ->where('root_task_id', $rootId)
                ->whereIn('status', ['pending', 'sending'])
                ->update([
                    'status' => 'cancelled',
                    'claim_token' => null,
                    'updated_at' => now(),
                ]);

            $reschedule = [[
                'type' => 'snooze',
                'minutes' => (int)$minutes,
                'at' => now()->toIso8601String(),
            ]];

            return DB::table('remind_tasks')->insertGetId([
                'habit_time_id'  => (int)$parent->habit_time_id,
                'habit_log_id'   => null,
                'parent_task_id' => (int)$parent->id,
                'root_task_id'   => $rootId,
                'remind_at'      => $remindAt,
                'sent_at'        => null,
                'reschedule'     => json_encode($reschedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'         => 'pending',
                'claim_token'    => null,
                'last_error'     => null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        });

        return response()->json([
            'ok' => true,
            'new_task_id' => $newId,
            'root_task_id' => $rootId,
            'parent_task_id' => (int)$parent->id,
            'remind_at' => $remindAt->toIso8601String(),
        ]);
    }

    public function done(Request $request, int $task)
    {
        return $this->finishSeries($request, $task, 'done');
    }

    public function cancel(Request $request, int $task)
    {
        return $this->finishSeries($request, $task, 'cancelled');
    }

    private function finishSeries(Request $request, int $task, string $selfStatus)
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $t = DB::table('remind_tasks')->where('id', $task)->first();
        if (!$t) {
            return response()->json(['message' => 'task not found'], 404);
        }

        if (empty($t->habit_time_id)) {
            return response()->json(['message' => 'habit_time_id missing'], 500);
        }

        $ht = DB::table('habit_times')->where('id', (int)$t->habit_time_id)->first();
        if (!$ht) {
            return response()->json(['message' => 'habit_time not found'], 404);
        }

        $habit = DB::table('habits')->where('id', (int)$ht->habit_id)->first();
        if (!$habit || (int)$habit->user_id !== (int)$userId) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

        $logId = null;

        DB::transaction(function () use ($t, $rootId, $selfStatus, $habit, $ht, $userId, &$logId) {
            if (!$t->root_task_id) {
                DB::table('remind_tasks')
                    ->where('id', $t->id)
                    ->update(['root_task_id' => $rootId, 'updated_at' => now()]);
            }

            if ($selfStatus === 'done') {
                $dateYmd = Carbon::now('Asia/Tokyo')->toDateString();
                $timeSlot = (int)($ht->time_slot ?? 0);

                $logId = $this->ensureHabitDoneLog(
                    habitId: (int)$habit->id,
                    habitTimeId: (int)$t->habit_time_id,
                    userId: (int)$userId,
                    dateYmd: $dateYmd,
                    timeSlot: $timeSlot,
                    evaluationType: (string)($habit->evaluation_type ?? 'simple'),
                    tz: 'Asia/Tokyo'
                );
            }

            $update = [
                'status' => $selfStatus,
                'claim_token' => null,
                'updated_at' => now(),
            ];

            if ($selfStatus === 'done' && $logId && Schema::hasColumn('remind_tasks', 'habit_log_id')) {
                $update['habit_log_id'] = (int)$logId;
            }

            DB::table('remind_tasks')
                ->where('id', $t->id)
                ->update($update);

            DB::table('remind_tasks')
                ->where('root_task_id', $rootId)
                ->whereIn('status', ['pending', 'sending'])
                ->update([
                    'status' => 'cancelled',
                    'claim_token' => null,
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'ok' => true,
            'root_task_id' => $rootId,
            'self_status' => $selfStatus,
            'habit_id' => (int)$habit->id,
            'habit_time_id' => (int)$t->habit_time_id,
            'habit_log_id' => $logId ? (int)$logId : null,
        ]);
    }

    /**
     * ★ユニーク (user_id, habit_time_id, date) 前提で冪等に done 化する
     * - 既に行があれば UPDATE して done にする（insertしない）
     * - 無ければ INSERT
     */
    private function ensureHabitDoneLog(
        int $habitId,
        int $habitTimeId,
        int $userId,
        string $dateYmd,
        int $timeSlot,
        string $evaluationType,
        string $tz = 'Asia/Tokyo'
    ): ?int {
        if (!Schema::hasTable('habit_logs')) return null;

        // 既存行（ユニークキーの本体）を取る
        $existing = DB::table('habit_logs')
            ->where('habit_time_id', $habitTimeId)
            ->where('user_id', $userId)
            ->where('date', $dateYmd)
            ->first();

        $nowJst = Carbon::now($tz)->toDateTimeString();

        // 共通：done に揃える更新内容（存在するカラムだけ）
        $patch = [];
        if (Schema::hasColumn('habit_logs', 'habit_id')) $patch['habit_id'] = $habitId;
        if (Schema::hasColumn('habit_logs', 'time_slot')) $patch['time_slot'] = $timeSlot;
        if (Schema::hasColumn('habit_logs', 'status')) $patch['status'] = 'done';
        if (Schema::hasColumn('habit_logs', 'checked_at')) $patch['checked_at'] = $nowJst;

        if (Schema::hasColumn('habit_logs', 'rating')) {
            $patch['rating'] = ($evaluationType === 'self') ? 4 : null;
        }

        if (Schema::hasColumn('habit_logs', 'updated_at')) $patch['updated_at'] = now();

        if ($existing) {
            // ★insert ではなく update（重複回避）
            DB::table('habit_logs')->where('id', (int)$existing->id)->update($patch);
            return (int)$existing->id;
        }

        // 無ければ insert（存在するカラムだけ）
        $data = [];
        if (Schema::hasColumn('habit_logs', 'habit_id')) $data['habit_id'] = $habitId;
        if (Schema::hasColumn('habit_logs', 'habit_time_id')) $data['habit_time_id'] = $habitTimeId;
        if (Schema::hasColumn('habit_logs', 'user_id')) $data['user_id'] = $userId;
        if (Schema::hasColumn('habit_logs', 'date')) $data['date'] = $dateYmd;
        if (Schema::hasColumn('habit_logs', 'time_slot')) $data['time_slot'] = $timeSlot;
        if (Schema::hasColumn('habit_logs', 'status')) $data['status'] = 'done';
        if (Schema::hasColumn('habit_logs', 'rating')) $data['rating'] = ($evaluationType === 'self') ? 4 : null;
        if (Schema::hasColumn('habit_logs', 'checked_at')) $data['checked_at'] = $nowJst;
        if (Schema::hasColumn('habit_logs', 'created_at')) $data['created_at'] = now();
        if (Schema::hasColumn('habit_logs', 'updated_at')) $data['updated_at'] = now();

        return (int)DB::table('habit_logs')->insertGetId($data);
    }
}
