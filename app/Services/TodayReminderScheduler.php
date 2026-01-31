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
     * - habit_time ごとに「次回の remind_at」を計算（今日の notify_time - offset、過去なら翌日に繰越）
     * - 既存の pending ルートがあれば remind_at を更新（必要なときだけ）
     * - なければ新規作成し、root_task_id を自分自身の id に揃える
     *
     * @return int 新規作成した件数
     */
    public function ensureFromTodayPayload(int $userId, array $payload, string $tz = 'Asia/Tokyo'): int
    {
        // payload から habit_time_id を集める（TodayController と同じ）
        $habitTimeIds = [];
        foreach (($payload['habits'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $htId = (int)($it['habit_time_id'] ?? 0);
            if ($htId > 0) $habitTimeIds[$htId] = true;
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

        $created = 0;
        $updated = 0;
        $deduped = 0;

        DB::transaction(function () use ($rows, $now, $todayStart, $tz, &$created, &$updated, &$deduped) {

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

                // ★同一 habit_time の並行実行を直列化（E対策の本体）
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

                // 既存の pending ルート（parent_task_id NULL）をロックして取得
                $existingTasks = DB::table('remind_tasks')
                    ->where('habit_time_id', $habitTimeId)
                    ->whereNull('parent_task_id')
                    ->where('status', 'pending')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                if ($existingTasks->isNotEmpty()) {
                    $existing = $existingTasks->first();

                    // ★重複 pending root の安全な解消（D/G対策）
                    // - 子が無い extra root → delete
                    // - 子がある extra root → delete せず cancelled に落とす（参照整合性を壊さない）
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
                                        'last_error' => 'deduped by scheduler (extra pending root had children)',
                                        'updated_at' => $now->format('Y-m-d H:i:s'),
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

                    // ★TZを明示して比較（B対策の本体）
                    try {
                        $existingAt = Carbon::createFromFormat('Y-m-d H:i:s', (string)$existing->remind_at, $tz);
                    } catch (\Throwable $e) {
                        $existingAt = Carbon::parse((string)$existing->remind_at, $tz);
                    }

                    $needUpdate = !$existingAt->equalTo($remindAt);

                    // root_task_id が未設定/不整合なら揃える（保険）
                    $needFixRoot = (empty($existing->root_task_id) || (int)$existing->root_task_id !== (int)$existing->id);

                    if ($needUpdate || $needFixRoot) {
                        DB::table('remind_tasks')
                            ->where('id', (int)$existing->id)
                            ->update([
                                'remind_at'    => $remindAt->format('Y-m-d H:i:s'),
                                'root_task_id' => (int)$existing->id,
                                'updated_at'   => $now->format('Y-m-d H:i:s'),
                            ]);
                        $updated++;
                    }

                    continue;
                }

                // 無ければ新規作成（root_task_id は insert 後に自分自身へ）
                $newId = DB::table('remind_tasks')->insertGetId([
                    'habit_log_id'   => null,
                    'habit_time_id'  => $habitTimeId,
                    'parent_task_id' => null,
                    'root_task_id'   => null,
                    'remind_at'      => $remindAt->format('Y-m-d H:i:s'),
                    'sent_at'        => null,
                    'reschedule'     => null,
                    'status'         => 'pending',
                    'claim_token'    => null,
                    'last_error'     => null,
                    'created_at'     => $now->format('Y-m-d H:i:s'),
                    'updated_at'     => $now->format('Y-m-d H:i:s'),
                ]);

                DB::table('remind_tasks')
                    ->where('id', (int)$newId)
                    ->update([
                        'root_task_id' => (int)$newId,
                        'updated_at'   => $now->format('Y-m-d H:i:s'),
                    ]);

                $created++;
            }
        });

        // ログは「変化があったときだけ」出す（運用ログを殺さない）
        if ($created > 0 || $updated > 0 || $deduped > 0) {
            Log::info('TodayReminderScheduler ensureFromTodayPayload summary', [
                'user_id'  => $userId,
                'created'  => $created,
                'updated'  => $updated,
                'deduped'  => $deduped,
                'timezone' => $tz,
            ]);
        }

        // 既存仕様：新規作成件数だけ返す
        return (int)$created;
    }
}
