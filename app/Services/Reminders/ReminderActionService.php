<?php

namespace App\Services\Reminders;

use App\Services\Habits\HabitLogService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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
   * - done/cancelled/skipped は不可（終端）
   * - sending は不可（dispatch と競合させない）
   * - sent/pending の task を cancelled にし、root配下の pending も止めて、新しい pending task を作る
   *
   * 追加ガード（運用事故防止）:
   * - unique(habit_time_id, remind_at) 衝突は 500 にせず冪等化（既存taskを返す）
   *
   * @return array<string,mixed>
   */
  public function snooze(int $userId, int $taskId, int $minutes): array
  {
    if ($minutes < 1 || $minutes > 1440) {
      throw new \RuntimeException('minutes must be 1..1440', 422);
    }

    $nowJst = $this->nowJst();

    // “次の分境界(tick)” 基準（everyMinute dispatch と相性が良い）
    $base = $nowJst->copy()->addMinute()->startOfMinute();
    $remindAt = $base->copy()->addMinutes(max(0, $minutes - 1));

    try {
      $result = DB::transaction(function () use ($userId, $taskId, $minutes, $nowJst, $remindAt) {

        // 1) 対象 task をロックして取り直す（codex E 対策）
        $locked = DB::table('remind_tasks')->where('id', $taskId)->lockForUpdate()->first();
        if (!$locked) {
          throw new \RuntimeException('task not found', 404);
        }
        if (empty($locked->habit_time_id)) {
          throw new \RuntimeException('habit_time_id missing', 500);
        }

        // 2) ownership をロック下でも確認（データ破損でも安全）
        $ht = $this->resolver->getHabitTimeOrFail((int)$locked->habit_time_id);
        $habit = $this->resolver->getHabitOrFail((int)$ht->habit_id);
        $this->resolver->assertOwnershipOrFail($habit, $userId);

        $st = (string)($locked->status ?? '');

        // 3) 終端は操作不可（skipped も終端）
        if (in_array($st, ['done', 'cancelled', 'skipped'], true)) {
          throw new \RuntimeException('series already finished', 409);
        }

        // 4) sending は操作不可（dispatch競合回避：codex D）
        if ($st === 'sending') {
          throw new \RuntimeException('task is sending', 409);
        }

        // 5) 実運用は sent が主。pending も許可してOK（今の仕様踏襲）
        $allowedFrom = ['sent', 'pending'];
        if (!in_array($st, $allowedFrom, true)) {
          throw new \RuntimeException('task is not snoozeable', 409);
        }

        $rootId = $locked->root_task_id ? (int)$locked->root_task_id : (int)$locked->id;

        // root_task_id 未設定なら親に付与（ロック下）
        if (!$locked->root_task_id) {
          DB::table('remind_tasks')->where('id', (int)$locked->id)->update([
            'root_task_id' => $rootId,
            'updated_at' => $nowJst,
          ]);
        }

        // root配下の pending だけ止める（sending は触らない）
        DB::table('remind_tasks')
          ->where('root_task_id', $rootId)
          ->where('status', 'pending')
          ->update([
            'status' => 'cancelled',
            'claim_token' => null,
            'updated_at' => $nowJst,
          ]);

        // 6) 親 task を cancelled に確定（条件付きUPDATE：codex E 対策）
        $affected = DB::table('remind_tasks')
          ->where('id', (int)$locked->id)
          ->whereIn('status', $allowedFrom) // sent/pending のときだけ
          ->update([
            'status' => 'cancelled',
            'claim_token' => null,
            'updated_at' => $nowJst,
          ]);

        if ($affected !== 1) {
          throw new \RuntimeException('conflict', 409);
        }

        $reschedule = [[
          'type' => 'snooze',
          'minutes' => (int)$minutes,
          'at' => $nowJst->toIso8601String(),
        ]];

        // 7) 新しい pending task を作る
        //    ★ unique(habit_time_id, remind_at) 衝突は冪等に扱い、既存行を返す
        try {
          $newId = DB::table('remind_tasks')->insertGetId([
            'habit_time_id'  => (int)$locked->habit_time_id,
            'habit_log_id'   => null,
            'parent_task_id' => (int)$locked->id,
            'root_task_id'   => $rootId,
            'remind_at'      => $remindAt, // Carbon をそのまま渡してOK（Laravelが整形）
            'sent_at'        => null,
            'reschedule'     => json_encode($reschedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'         => 'pending',
            'claim_token'    => null,
            'last_error'     => null,
            'created_at'     => $nowJst,
            'updated_at'     => $nowJst,
          ]);

          return [
            'new_id' => (int)$newId,
            'created' => true,
            'root_id' => (int)$rootId,
            'habit' => $habit,
            'ht' => $ht,
            'parent_id' => (int)$locked->id,
          ];
        } catch (QueryException $e) {
          if ($this->isDuplicateKey($e)) {
            // unique(habit_time_id, remind_at) の既存行を採用（冪等）
            $existingId = DB::table('remind_tasks')
              ->where('habit_time_id', (int)$locked->habit_time_id)
              ->where('remind_at', $remindAt->toDateTimeString())
              ->value('id');

            if ($existingId) {
              return [
                'new_id' => (int)$existingId,
                'created' => false,
                'root_id' => (int)$rootId,
                'habit' => $habit,
                'ht' => $ht,
                'parent_id' => (int)$locked->id,
              ];
            }
          }
          throw $e;
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

    /** @var object $habit */
    $habit = $result['habit'];
    /** @var object $ht */
    $ht = $result['ht'];

    $dateYmd = $nowJst->toDateString();
    $timeSlot = (int)($ht->time_slot ?? 0);
    $evalType = (string)($habit->evaluation_type ?? 'simple');

    return [
      'ok' => true,
      'self_status' => 'snooze',

      'task_id' => (int)$result['parent_id'],
      'new_task_id' => (int)$result['new_id'],
      'root_task_id' => (int)$result['root_id'],
      'parent_task_id' => (int)$result['parent_id'],

      'habit_id' => (int)$habit->id,
      'habit_time_id' => (int)$ht->id,
      'habit_log_id' => null,

      'date' => $dateYmd,
      'evaluation_type' => $evalType,
      'time_slot' => $timeSlot,

      'remind_at' => $remindAt->toIso8601String(),
      'created' => (bool)($result['created'] ?? true),
    ];
  }

  /**
   * 単体キャンセル：この task 1件だけ cancelled にする（root配下は触らない）
   * - done/cancelled/skipped は 409
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

        // ownership をロック下でも確認
        $ht = DB::table('habit_times')->where('id', (int)$locked->habit_time_id)->first();
        if (!$ht) throw new \RuntimeException('habit_time not found', 404);
        $habit = DB::table('habits')->where('id', (int)$ht->habit_id)->first();
        if (!$habit || (int)$habit->user_id !== (int)$userId) throw new \RuntimeException('forbidden', 403);

        $st = (string)($locked->status ?? '');
        if (in_array($st, ['done', 'cancelled', 'skipped'], true)) {
          throw new \RuntimeException('already finished', 409);
        }
        if ($st === 'sending') {
          throw new \RuntimeException('task is sending', 409);
        }

        $affected = DB::table('remind_tasks')
          ->where('id', (int)$locked->id)
          ->whereNotIn('status', ['done', 'cancelled', 'skipped', 'sending'])
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
   * - cancelled/done/skipped は 409
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
        if (in_array($st, ['done', 'cancelled', 'skipped'], true)) {
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
          ->whereNotIn('status', ['done', 'cancelled', 'skipped', 'sending'])
          ->update($update);

        if ($affected !== 1) {
          throw new \RuntimeException('conflict', 409);
        }

        // シリーズ終了：pending だけ止める（sending は触らない）
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

  private function isDuplicateKey(QueryException $e): bool
  {
    // SQLSTATE 23000: integrity constraint violation
    $sqlState = $e->errorInfo[0] ?? null;
    $driverCode = $e->errorInfo[1] ?? null; // MySQL duplicate key = 1062
    return $sqlState === '23000' && (int)$driverCode === 1062;
  }
}
