<?php

namespace App\Services\Reminders;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemindTaskRepository
{
    public function rescueSending(\DateTimeInterface $now, int $rescueMinutes, int $maxRetries): array
    {
        if ($now instanceof CarbonInterface) {
            $staleBefore = $now->copy()->subMinutes($rescueMinutes);
        } else {
            $staleBefore = \Carbon\Carbon::instance(\DateTimeImmutable::createFromInterface($now))
                ->subMinutes($rescueMinutes);
        }

        $toError = DB::table('remind_tasks')
            ->where('status', 'sending')
            ->whereNotNull('claim_token')
            ->where('updated_at', '<', $staleBefore)
            ->where('attempts', '>=', $maxRetries)
            ->update([
                'status' => 'error',
                'last_error' => 'rescued_sending_exceeded_retries',
                'claim_token' => null,
                'updated_at' => $now,
            ]);

        $toPending = DB::table('remind_tasks')
            ->where('status', 'sending')
            ->whereNotNull('claim_token')
            ->where('updated_at', '<', $staleBefore)
            ->where('attempts', '<', $maxRetries)
            ->update([
                'status' => 'pending',
                'attempts' => DB::raw('attempts + 1'),
                'claim_token' => null,
                'updated_at' => $now,
            ]);

        return [(int) $toPending, (int) $toError];
    }

    public function pickDueIds(\DateTimeInterface $now, int $limit): array
    {
        return DB::table('remind_tasks')
            ->where('status', 'pending')
            ->where('remind_at', '<=', $now)
            ->orderBy('remind_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    public function claim(array $ids, \DateTimeInterface $now): array
    {
        $token = (string) Str::uuid();

        $claimed = DB::table('remind_tasks')
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->update([
                'status' => 'sending',
                'claim_token' => $token,
                'updated_at' => $now,
            ]);

        return [$token, (int) $claimed];
    }

    public function fetchClaimedTasks(string $token)
    {
        return DB::table('remind_tasks as rt')
            ->leftJoin('habit_times as ht', 'ht.id', '=', 'rt.habit_time_id')
            ->leftJoin('habits as h', 'h.id', '=', 'ht.habit_id')
            ->select([
                'rt.*',
                'ht.habit_id as habit_id',
                'ht.time_slot as time_slot',
                'h.user_id as user_id',
                'h.title as habit_title',
                'h.evaluation_type as evaluation_type',
            ])
            ->where('rt.status', 'sending')
            ->where('rt.claim_token', $token)
            ->orderBy('rt.remind_at')
            ->get();
    }

    public function heartbeat(string $token, \DateTimeInterface $now): void
    {
        DB::table('remind_tasks')
            ->where('status', 'sending')
            ->where('claim_token', $token)
            ->update(['updated_at' => $now]);
    }

    public function touch(int $taskId, string $token, \DateTimeInterface $now): void
    {
        DB::table('remind_tasks')
            ->where('id', $taskId)
            ->where('claim_token', $token)
            ->update(['updated_at' => $now]);
    }

    /**
     * ★重要: send直前の所有確認
     * ただし MySQL は "値が変わらないUPDATE" を affected_rows=0 にすることがあるため、
     * updateが0でも「所有権が本当に無い」と断定せず exists() で再確認する。
     */
    public function touchOwned(int $taskId, string $token, \DateTimeInterface $now): bool
    {
        $affected = (int) DB::table('remind_tasks')
            ->where('id', $taskId)
            ->where('status', 'sending')
            ->where('claim_token', $token)
            ->update(['updated_at' => $now]);

        if ($affected === 1) {
            return true;
        }

        // 0件でも、updated_atが同値で "変更なし" と判定されただけの可能性がある。
        return DB::table('remind_tasks')
            ->where('id', $taskId)
            ->where('status', 'sending')
            ->where('claim_token', $token)
            ->exists();
    }

    public function mark(int $taskId, string $token, array $updates): void
    {
        DB::table('remind_tasks')
            ->where('id', $taskId)
            ->where('claim_token', $token)
            ->update($updates);
    }

    public function markManySent(array $taskIds, string $token, \DateTimeInterface $now, ?string $lastError = null): int
    {
        return (int) DB::table('remind_tasks')
            ->whereIn('id', $taskIds)
            ->where('claim_token', $token)
            ->update([
                'status' => 'sent',
                'sent_at' => $now,
                'last_error' => $lastError,
                'claim_token' => null,
                'updated_at' => $now,
            ]);
    }

    public function cancelPendingByRoot(int $rootId, \DateTimeInterface $now): int
    {
        return (int) DB::table('remind_tasks')
            ->where('root_task_id', $rootId)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'claim_token' => null,
                'updated_at' => $now,
            ]);
    }

    public function fetchDoneSet(string $todayYmd, array $userIds, array $habitTimeIds): array
    {
        if (empty($userIds) || empty($habitTimeIds)) return [];

        $rows = DB::table('habit_logs')
            ->select(['user_id', 'habit_time_id'])
            ->where('date', $todayYmd)
            ->where('status', 'done')
            ->whereIn('user_id', $userIds)
            ->whereIn('habit_time_id', $habitTimeIds)
            ->get();

        $set = [];
        foreach ($rows as $r) {
            $set[(int) $r->user_id . ':' . (int) $r->habit_time_id] = true;
        }

        return $set;
    }
}
