<?php
// app/Services/TodayReminderScheduler.php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TodayReminderScheduler
{
    /**
     * TodayPayload から「次回の remind_tasks（pending のルート）」を確実に用意する。
     *
     * 重要（Planと衝突しないため）:
     * - habit_time_id だけで pending root を潰さない（未来日の予定を壊さない）
     * - この関数が計算した remind_at（=次回分）に対してのみ存在保証する
     * - dedupe は「同じ remind_at の pending root が複数ある場合」のみに限定する
     *
     * @return int 新規作成した件数
     */
    public function ensureFromTodayPayload(int $userId, array $payload, string $tz = 'Asia/Tokyo'): int
    {
        // payload から habit_time_id を集める
        $habitTimeIds = [];
        foreach (($payload['habits'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $htId = (int)($it['habit_time_id'] ?? 0);
            if ($htId > 0) {
                $habitTimeIds[$htId] = true;
            }
        }
        $habitTimeIds = array_keys($habitTimeIds);
        sort($habitTimeIds);

        if (empty($habitTimeIds)) {
            return 0;
        }

        // 対象 habit_times を DB から取得（ユーザー所有 + notify_time 必須）
        $rows = DB::table('habit_times as ht')
            ->join('habits as h', 'h.id', '=', 'ht.habit_id')
            ->where('h.user_id', $userId)
            ->whereIn('ht.id', $habitTimeIds)
            ->whereNotNull('ht.notify_time')
            ->select([
                'ht.id as habit_time_id',
                'ht.notify_time',
                'ht.remind_offset',
                'h.archived',
            ])
            ->get()
            ->all();

        if (empty($rows)) {
            return 0;
        }

        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay();
        $nowStr = $now->format('Y-m-d H:i:s');

        $created = 0;
        $updated = 0;
        $deduped = 0;

        DB::transaction(function () use ($rows, $now, $todayStart, $nowStr, &$created, &$updated, &$deduped) {

            foreach ($rows as $r) {
                // archived はスケジューリング対象外
                if ((bool)($r->archived ?? false) === true) {
                    continue;
                }

                $habitTimeId = (int)($r->habit_time_id ?? 0);
                if ($habitTimeId <= 0) {
                    continue;
                }

                $notifyTime = (string)($r->notify_time ?? '');
                if ($notifyTime === '') {
                    continue;
                }

                $offsetMin = (int)($r->remind_offset ?? 0);

                // ★同一 habit_time の並行実行を直列化（Today API 多重叩き対策）
                DB::table('habit_times')
                    ->where('id', $habitTimeId)
                    ->lockForUpdate()
                    ->first();

                // 今日の notify_time をベースに remind_at 作成（notify_time は "HH:MM:SS" 想定）
                $remindAt = $todayStart->copy()->setTimeFromTimeString($notifyTime);

                if ($offsetMin !== 0) {
                    $remindAt = $remindAt->copy()->subMinutes($offsetMin);
                }

                // 過去なら翌日に繰り越す（= 必ず未来）
                if ($remindAt->lte($now)) {
                    $remindAt = $remindAt->addDay();
                }

                $remindAtStr = $remindAt->format('Y-m-d H:i:s');

                // ★同じ remind_at の pending root のみを見る（未来日の予定は破壊しない）
                $existingTasks = DB::table('remind_tasks')
                    ->where('habit_time_id', $habitTimeId)
                    ->whereNull('parent_task_id')
                    ->where('status', 'pending')
                    ->where('remind_at', $remindAtStr)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                if ($existingTasks->isNotEmpty()) {
                    $existing = $existingTasks->first();

                    // 同一 remind_at の重複のみ解消
                    if ($existingTasks->count() > 1) {
                        $extraIds = $existingTasks->slice(1)->pluck('id')->map(fn($v) => (int)$v)->all();

                        if (!empty($extraIds)) {
                            $childCounts = DB::table('remind_tasks')
                                ->select('parent_task_id', DB::raw('COUNT(*) as c'))
                                ->whereIn('parent_task_id', $extraIds)
                                ->groupBy('parent_task_id')
                                ->pluck('c', 'parent_task_id')
                                ->all();

                            $deleteIds = [];
                            $cancelIds = [];

                            foreach ($extraIds as $eid) {
                                $hasChild = isset($childCounts[$eid]) && (int)$childCounts[$eid] > 0;
                                if ($hasChild) {
                                    $cancelIds[] = $eid;
                                } else {
                                    $deleteIds[] = $eid;
                                }
                            }

                            if (!empty($cancelIds)) {
                                DB::table('remind_tasks')
                                    ->whereIn('id', $cancelIds)
                                    ->update([
                                        'status'     => 'cancelled',
                                        'last_error' => 'deduped by today scheduler (same remind_at)',
                                        'updated_at' => $nowStr,
                                    ]);
                                $deduped += count($cancelIds);
                            }

                            if (!empty($deleteIds)) {
                                DB::table('remind_tasks')
                                    ->whereIn('id', $deleteIds)
                                    ->delete();
                                $deduped += count($deleteIds);
                            }
                        }
                    }

                    // root_task_id が未設定/不整合なら揃える
                    $needFixRoot = (empty($existing->root_task_id) || (int)$existing->root_task_id !== (int)$existing->id);
                    if ($needFixRoot) {
                        DB::table('remind_tasks')
                            ->where('id', (int)$existing->id)
                            ->update([
                                'root_task_id' => (int)$existing->id,
                                'updated_at'   => $nowStr,
                            ]);
                        $updated++;
                    }

                    continue;
                }

                // 無ければ新規作成（競合したら取り直して root を揃える）
                try {
                    $newId = DB::table('remind_tasks')->insertGetId([
                        'habit_log_id'   => null,
                        'habit_time_id'  => $habitTimeId,
                        'parent_task_id' => null,
                        'root_task_id'   => null,
                        'remind_at'      => $remindAtStr,
                        'sent_at'        => null,
                        'reschedule'     => null,
                        'status'         => 'pending',
                        'claim_token'    => null,
                        'last_error'     => null,
                        'created_at'     => $nowStr,
                        'updated_at'     => $nowStr,
                    ]);

                    DB::table('remind_tasks')
                        ->where('id', (int)$newId)
                        ->update([
                            'root_task_id' => (int)$newId,
                            'updated_at'   => $nowStr,
                        ]);

                    $created++;
                } catch (\Throwable $e) {
                    // 競合で同じ remind_at が作られた可能性 → 取り直して root を揃える
                    $again = DB::table('remind_tasks')
                        ->where('habit_time_id', $habitTimeId)
                        ->whereNull('parent_task_id')
                        ->where('status', 'pending')
                        ->where('remind_at', $remindAtStr)
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();

                    if ($again) {
                        $needFixRoot = (empty($again->root_task_id) || (int)$again->root_task_id !== (int)$again->id);
                        if ($needFixRoot) {
                            DB::table('remind_tasks')
                                ->where('id', (int)$again->id)
                                ->update([
                                    'root_task_id' => (int)$again->id,
                                    'updated_at'   => $nowStr,
                                ]);
                            $updated++;
                        }
                    }
                }
            }
        });

        // ログは「変化があったときだけ」出す
        if ($created > 0 || $updated > 0 || $deduped > 0) {
            Log::info('TodayReminderScheduler ensureFromTodayPayload summary', [
                'user_id'  => $userId,
                'created'  => $created,
                'updated'  => $updated,
                'deduped'  => $deduped,
                'timezone' => $tz,
            ]);
        }

        return (int)$created;
    }
}
