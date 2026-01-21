<?php

namespace App\Services\Reminders;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemindTaskRepository
{
    public function rescueSending(\DateTimeInterface $now, int $rescueMinutes, int $maxRetries): array
    {
        // ★now() は使わず、渡された $now を基準に計算（timezone混在を防ぐ）
        if ($now instanceof CarbonInterface) {
            $staleBefore = $now->copy()->subMinutes($rescueMinutes);
        } else {
            // DateTimeInterface から Carbon に寄せて引き算（安全）
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

        return [(int)$toPending, (int)$toError];
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

        return [$token, (int)$claimed];
    }

    /**
     * claim済み tasks を JOIN で一発取得（N+1回避）
     * 返り値は StdClass の配列（->id などでアクセス）
     */
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
     * ★double-send対策の核
     * 送信直前に「まだ status=sending かつ claim_token が自分」を原子的に確認しつつ touch する
     * 1件更新できたときだけ true（＝所有権あり）
     */
    public function touchOwned(int $taskId, string $token, \DateTimeInterface $now): bool
    {
        $n = DB::table('remind_tasks')
            ->where('id', $taskId)
            ->where('status', 'sending')
            ->where('claim_token', $token)
            ->update(['updated_at' => $now]);

        return ((int)$n === 1);
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
        // ★in-flight を巻き込まない：pending のみ
        return (int) DB::table('remind_tasks')
            ->where('root_task_id', $rootId)
            ->whereIn('status', ['pending'])
            ->update([
                'status' => 'cancelled',
                'claim_token' => null,
                'updated_at' => $now,
            ]);
    }

    /**
     * 今日done集合を (user_id:habit_time_id) のキーで返す
     */
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
            $set[(int)$r->user_id . ':' . (int)$r->habit_time_id] = true;
        }
        return $set;
    }
}
