<?php

namespace App\Services\Reminders;

use App\Services\Habits\HabitLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReminderActionService
{
    private const TZ = 'Asia/Tokyo';

    public function __construct(
        private readonly ReminderTaskResolver $resolver,
        private readonly HabitLogService $habitLogs,
    ) {}

    private function nowJst(): Carbon
    {
        return Carbon::now(self::TZ);
    }

    /**
     * Snooze:
     * - done/cancelled は不可
     * - sending は触らない（dispatch と競合させない）
     * - pending だけ cancelled にして、新しい pending task を作る
     *
     * @return array<string,mixed>
     */
    public function snooze(int $userId, int $taskId, int $minutes): array
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw new \RuntimeException('minutes must be 1..1440', 422);
        }

        $parent = $this->resolver->getTaskOrFail($taskId);

        if (in_array((string)$parent->status, ['done', 'cancelled'], true)) {
            throw new \RuntimeException('series already finished', 409);
        }

        if (empty($parent->habit_time_id)) {
            throw new \RuntimeException('habit_time_id missing', 500);
        }

        $ht = $this->resolver->getHabitTimeOrFail((int)$parent->habit_time_id);
        $habit = $this->resolver->getHabitOrFail((int)$ht->habit_id);
        $this->resolver->assertOwnershipOrFail($habit, $userId);

        $rootId = $parent->root_task_id ? (int)$parent->root_task_id : (int)$parent->id;

        $nowJst = $this->nowJst();

        // “次の分境界(tick)” 基準（everyMinute dispatch と相性が良い）
        $base = $nowJst->copy()->addMinute()->startOfMinute();
        $remindAt = $base->copy()->addMinutes(max(0, $minutes - 1));

        $newId = DB::transaction(function () use ($parent, $rootId, $remindAt, $minutes, $nowJst) {
            // root_task_id 未設定なら親に付与
            if (!$parent->root_task_id) {
                DB::table('remind_tasks')->where('id', (int)$parent->id)->update([
                    'root_task_id' => $rootId,
                    'updated_at' => $nowJst,
                ]);
            }

            // pending だけ止める（sending は触らない）
            DB::table('remind_tasks')
                ->where('root_task_id', $rootId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'claim_token' => null,
                    'updated_at' => $nowJst,
                ]);

            // 親が pending なら親自身も cancelled にしておく（sent/sending は残す）
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

        $dateYmd = $nowJst->toDateString();
        $timeSlot = (int)($ht->time_slot ?? 0);
        $evalType = (string)($habit->evaluation_type ?? 'simple');

        return [
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
        ];
    }

    /**
     * 単体キャンセル：この task 1件だけ cancelled にする（root配下は触らない）
     * - done/cancelled は 409
     * - sending は 409（dispatchと競合させない）
     * - レース対策：lockForUpdate + 条件付きUPDATE
     *
     * @return array<string,mixed>
     */
    public function cancelSingle(int $userId, int $taskId): array
    {
        // ownership + 表示用情報（ただし状態はロック下で再チェック）
        $r = $this->resolver->resolveTaskForUser($userId, $taskId);
        $t = $r['task'];
        $ht = $r['habitTime'];
        $habit = $r['habit'];

        $nowJst = $this->nowJst();
        $dateYmd = $nowJst->toDateString();
        $timeSlot = (int)($ht->time_slot ?? 0);
        $evalType = (string)($habit->evaluation_type ?? 'simple');

        try {
            DB::transaction(function () use ($taskId, $userId, $nowJst) {
                $locked = DB::table('remind_tasks')->where('id', $taskId)->lockForUpdate()->first();
                if (!$locked) {
                    throw new \RuntimeException('task not found', 404);
                }
                if (empty($locked->habit_time_id)) {
                    throw new \RuntimeException('habit_time_id missing', 500);
                }

                // ownership をロック下でも確認（habit_time_id が変なデータでも安全）
                $ht = DB::table('habit_times')->where('id', (int)$locked->habit_time_id)->first();
                if (!$ht) throw new \RuntimeException('habit_time not found', 404);
                $habit = DB::table('habits')->where('id', (int)$ht->habit_id)->first();
                if (!$habit || (int)$habit->user_id !== (int)$userId) throw new \RuntimeException('forbidden', 403);

                $st = (string)($locked->status ?? '');
                if (in_array($st, ['done', 'cancelled'], true)) {
                    throw new \RuntimeException('already finished', 409);
                }
                if ($st === 'sending') {
                    throw new \RuntimeException('task is sending', 409);
                }

                $affected = DB::table('remind_tasks')
                    ->where('id', (int)$locked->id)
                    ->whereNotIn('status', ['done', 'cancelled', 'sending'])
                    ->update([
                        'status' => 'cancelled',
                        'claim_token' => null,
                        'updated_at' => $nowJst,
                    ]);

                if ($affected !== 1) {
                    throw new \RuntimeException('conflict', 409);
                }
            });
        } catch (\RuntimeException $e) {
            $code = (int)$e->getCode();
            if (in_array($code, [401, 403, 404, 409, 422], true)) {
                throw $e;
            }
            throw new \RuntimeException('internal error', 500);
        } catch (\Throwable $e) {
            throw new \RuntimeException('internal error', 500);
        }

        return [
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
        ];
    }

    /**
     * done（シリーズ終了）：
     * - この task を done
     * - root配下の pending を止める（sending は触らない）
     * - cancelled/done は 409
     * - sending は 409
     * - レース対策：lockForUpdate + 条件付きUPDATE
     *
     * @return array<string,mixed>
     */
    public function doneSeries(int $userId, int $taskId): array
    {
        // ownership + 表示用情報（ただし状態はロック下で再チェック）
        $r = $this->resolver->resolveTaskForUser($userId, $taskId);
        $t = $r['task'];
        $ht = $r['habitTime'];
        $habit = $r['habit'];

        $nowJst = $this->nowJst();
        $dateYmd = $nowJst->toDateString();
        $timeSlot = (int)($ht->time_slot ?? 0);
        $evalType = (string)($habit->evaluation_type ?? 'simple');

        $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;
        $logId = null;

        try {
            DB::transaction(function () use ($taskId, $userId, $habit, $dateYmd, $timeSlot, $evalType, $nowJst, &$rootId, &$logId) {
                $locked = DB::table('remind_tasks')->where('id', $taskId)->lockForUpdate()->first();
                if (!$locked) throw new \RuntimeException('task not found', 404);
                if (empty($locked->habit_time_id)) throw new \RuntimeException('habit_time_id missing', 500);

                $ht = DB::table('habit_times')->where('id', (int)$locked->habit_time_id)->first();
                if (!$ht) throw new \RuntimeException('habit_time not found', 404);

                $habitRow = DB::table('habits')->where('id', (int)$ht->habit_id)->first();
                if (!$habitRow || (int)$habitRow->user_id !== (int)$userId) throw new \RuntimeException('forbidden', 403);

                $st = (string)($locked->status ?? '');
                if (in_array($st, ['done', 'cancelled'], true)) {
                    throw new \RuntimeException('already finished', 409);
                }
                if ($st === 'sending') {
                    throw new \RuntimeException('task is sending', 409);
                }

                $rootId = $locked->root_task_id ? (int)$locked->root_task_id : (int)$locked->id;

                // root_task_id を固定
                if (!$locked->root_task_id) {
                    DB::table('remind_tasks')
                        ->where('id', (int)$locked->id)
                        ->update(['root_task_id' => $rootId, 'updated_at' => $nowJst]);
                }

                // doneログを冪等に作る（既存更新 or insert）
                $logId = $this->habitLogs->ensureDoneLog(
                    habitId: (int)$habit->id,
                    habitTimeId: (int)$locked->habit_time_id,
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

                $affected = DB::table('remind_tasks')
                    ->where('id', (int)$locked->id)
                    ->whereNotIn('status', ['done', 'cancelled', 'sending'])
                    ->update($update);

                if ($affected !== 1) {
                    throw new \RuntimeException('conflict', 409);
                }

                // シリーズ終了：pending だけ止める
                DB::table('remind_tasks')
                    ->where('root_task_id', $rootId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'claim_token' => null,
                        'updated_at' => $nowJst,
                    ]);
            });
        } catch (\RuntimeException $e) {
            $code = (int)$e->getCode();
            if (in_array($code, [401, 403, 404, 409, 422], true)) {
                throw $e;
            }
            throw new \RuntimeException('internal error', 500);
        } catch (\Throwable $e) {
            throw new \RuntimeException('internal error', 500);
        }

        return [
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
        ];
    }
}
