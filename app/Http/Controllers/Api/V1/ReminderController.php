<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReminderController extends Controller
{
    private const TZ = 'Asia/Tokyo';

    private function nowJst(): Carbon
    {
        return Carbon::now(self::TZ);
    }

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

        // done/cancelled はシリーズ終了扱い（snooze不可）
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

        // ★Snooze は “次の分境界(tick)” を基準にする（everyMinute dispatch と相性が良い）
        $nowJst = $this->nowJst();
        $base = $nowJst->copy()->addMinute()->startOfMinute();
        $remindAt = $base->copy()->addMinutes(max(0, $minutes - 1));

        $newId = DB::transaction(function () use ($parent, $rootId, $remindAt, $minutes, $nowJst) {
            // root_task_id 未設定なら親に付与（後続処理の基準を固定）
            if (!$parent->root_task_id) {
                DB::table('remind_tasks')->where('id', (int)$parent->id)->update([
                    'root_task_id' => $rootId,
                    'updated_at' => $nowJst,
                ]);
            }

            // ★重要: "pending だけ" を止める。sending は絶対に触らない（dispatch と競合させない）
            DB::table('remind_tasks')
                ->where('root_task_id', $rootId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'claim_token' => null,
                    'updated_at' => $nowJst,
                ]);

            // 親が pending なら親自身も cancelled にしておく（sent/sendingは残す）
            if ((string)$parent->status === 'pending') {
                DB::table('remind_tasks')->where('id', (int)$parent->id)->update([
                    'status' => 'cancelled',
                    'claim_token' => null,
                    'updated_at' => $nowJst,
                ]);
            }

            $reschedule = [[
                'type' => 'snooze',
                'minutes' => (int)$minutes,
                'at' => $nowJst->toIso8601String(),
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
                'created_at'     => $nowJst,
                'updated_at'     => $nowJst,
            ]);
        });

        // ★統一レスポンス
        $dateYmd = $nowJst->toDateString();
        $timeSlot = (int)($ht->time_slot ?? 0);
        $evalType = (string)($habit->evaluation_type ?? 'simple');

        return response()->json([
            'ok' => true,
            'self_status' => 'snooze',

            'task_id' => (int)$parent->id,
            'new_task_id' => (int)$newId,
            'root_task_id' => (int)$rootId,
            'parent_task_id' => (int)$parent->id,

            'habit_id' => (int)$habit->id,
            'habit_time_id' => (int)$parent->habit_time_id,
            'habit_log_id' => null,

            'date' => $dateYmd,
            'evaluation_type' => $evalType,
            'time_slot' => $timeSlot,

            'remind_at' => $remindAt->toIso8601String(),
        ]);
    }

    public function done(Request $request, int $task)
    {
        return $this->finishDoneSeries($request, $task);
    }

    public function cancel(Request $request, int $task)
    {
        // ★キャンセルは「この通知だけ閉じる」：シリーズは終わらせない
        return $this->dismissSingle($request, $task);
    }

    /**
     * ★単体キャンセル：この task 1件だけ cancelled にする（root配下は触らない）
     */
    private function dismissSingle(Request $request, int $task)
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

        // すでに終了しているなら409（二度押し防止）
        if (in_array((string)$t->status, ['done', 'cancelled'], true)) {
            return response()->json(['message' => 'already finished'], 409);
        }

        // ★in-flight は触らない（dispatch と競合させない）
        if ((string)$t->status === 'sending') {
            return response()->json(['message' => 'task is sending'], 409);
        }

        $nowJst = $this->nowJst();
        $dateYmd = $nowJst->toDateString();
        $timeSlot = (int)($ht->time_slot ?? 0);
        $evalType = (string)($habit->evaluation_type ?? 'simple');

        DB::table('remind_tasks')
            ->where('id', (int)$t->id)
            ->update([
                'status' => 'cancelled',
                'claim_token' => null,
                'updated_at' => $nowJst,
            ]);

        return response()->json([
            'ok' => true,
            'self_status' => 'cancelled',

            'task_id' => (int)$t->id,
            'root_task_id' => (int)($t->root_task_id ? $t->root_task_id : $t->id),

            'habit_id' => (int)$habit->id,
            'habit_time_id' => (int)$t->habit_time_id,
            'habit_log_id' => null,

            'date' => $dateYmd,
            'evaluation_type' => $evalType,
            'time_slot' => $timeSlot,
        ]);
    }

    /**
     * ★done はシリーズ終了：この通知を done にし、root配下の pending を止める（sending は触らない）
     */
    private function finishDoneSeries(Request $request, int $task)
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

        // ★in-flight は触らない（dispatch と競合させない）
        if ((string)$t->status === 'sending') {
            return response()->json(['message' => 'task is sending'], 409);
        }

        $nowJst = $this->nowJst();
        $dateYmd = $nowJst->toDateString();
        $timeSlot = (int)($ht->time_slot ?? 0);
        $evalType = (string)($habit->evaluation_type ?? 'simple');

        $logId = null;

        DB::transaction(function () use ($t, $rootId, $habit, $userId, $dateYmd, $timeSlot, $evalType, $nowJst, &$logId) {
            // root_task_id を固定
            if (!$t->root_task_id) {
                DB::table('remind_tasks')
                    ->where('id', (int)$t->id)
                    ->update(['root_task_id' => $rootId, 'updated_at' => $nowJst]);
            }

            // doneログを冪等に作る
            $logId = $this->ensureHabitDoneLog(
                habitId: (int)$habit->id,
                habitTimeId: (int)$t->habit_time_id,
                userId: (int)$userId,
                dateYmd: $dateYmd,
                timeSlot: $timeSlot,
                evaluationType: $evalType,
                nowJst: $nowJst,
                tz: self::TZ
            );

            $update = [
                'status' => 'done',
                'claim_token' => null,
                'updated_at' => $nowJst,
            ];

            if ($logId && Schema::hasColumn('remind_tasks', 'habit_log_id')) {
                $update['habit_log_id'] = (int)$logId;
            }

            DB::table('remind_tasks')->where('id', (int)$t->id)->update($update);

            // ★シリーズ終了：pending だけを止める（sending は触らない）
            DB::table('remind_tasks')
                ->where('root_task_id', $rootId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'claim_token' => null,
                    'updated_at' => $nowJst,
                ]);
        });

        return response()->json([
            'ok' => true,
            'self_status' => 'done',

            'task_id' => (int)$t->id,
            'root_task_id' => (int)$rootId,

            'habit_id' => (int)$habit->id,
            'habit_time_id' => (int)$t->habit_time_id,
            'habit_log_id' => $logId ? (int)$logId : null,

            'date' => $dateYmd,
            'evaluation_type' => $evalType,
            'time_slot' => $timeSlot,
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
        Carbon $nowJst,
        string $tz = self::TZ
    ): ?int {
        if (!Schema::hasTable('habit_logs')) return null;

        $existing = DB::table('habit_logs')
            ->where('habit_time_id', $habitTimeId)
            ->where('user_id', $userId)
            ->where('date', $dateYmd)
            ->first();

        // checked_at はJSTの日時文字列
        $checkedAt = $nowJst->copy()->setTimezone($tz)->toDateTimeString();

        $patch = [];
        if (Schema::hasColumn('habit_logs', 'habit_id')) $patch['habit_id'] = $habitId;
        if (Schema::hasColumn('habit_logs', 'time_slot')) $patch['time_slot'] = $timeSlot;
        if (Schema::hasColumn('habit_logs', 'status')) $patch['status'] = 'done';
        if (Schema::hasColumn('habit_logs', 'checked_at')) $patch['checked_at'] = $checkedAt;

        if (Schema::hasColumn('habit_logs', 'rating')) {
            $patch['rating'] = ($evaluationType === 'self') ? 4 : null;
        }

        if (Schema::hasColumn('habit_logs', 'updated_at')) $patch['updated_at'] = $nowJst;

        if ($existing) {
            DB::table('habit_logs')->where('id', (int)$existing->id)->update($patch);
            return (int)$existing->id;
        }

        $data = [];
        if (Schema::hasColumn('habit_logs', 'habit_id')) $data['habit_id'] = $habitId;
        if (Schema::hasColumn('habit_logs', 'habit_time_id')) $data['habit_time_id'] = $habitTimeId;
        if (Schema::hasColumn('habit_logs', 'user_id')) $data['user_id'] = $userId;
        if (Schema::hasColumn('habit_logs', 'date')) $data['date'] = $dateYmd;
        if (Schema::hasColumn('habit_logs', 'time_slot')) $data['time_slot'] = $timeSlot;
        if (Schema::hasColumn('habit_logs', 'status')) $data['status'] = 'done';
        if (Schema::hasColumn('habit_logs', 'rating')) $data['rating'] = ($evaluationType === 'self') ? 4 : null;
        if (Schema::hasColumn('habit_logs', 'checked_at')) $data['checked_at'] = $checkedAt;
        if (Schema::hasColumn('habit_logs', 'created_at')) $data['created_at'] = $nowJst;
        if (Schema::hasColumn('habit_logs', 'updated_at')) $data['updated_at'] = $nowJst;

        return (int)DB::table('habit_logs')->insertGetId($data);
    }
}
