<?php
// app/Services/TodayReminderScheduler.php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($rows, $now, $todayStart, &$created) {
            foreach ($rows as $r) {
                // archived はスケジューリング対象外（必要ならここを外す）
                if ((bool)($r->archived ?? false) === true) {
                    continue;
                }

                $notifyTime = (string)($r->notify_time ?? '');
                if ($notifyTime === '') {
                    continue;
                }

                $offsetMin = (int)($r->remind_offset ?? 0);
                $habitTimeId = (int)$r->habit_time_id;

                // 今日の notify_time をベースに remind_at 作成
                // notify_time は "HH:MM:SS" の想定
                $remindAt = $todayStart->copy()->setTimeFromTimeString($notifyTime);

                if ($offsetMin !== 0) {
                    $remindAt = $remindAt->copy()->subMinutes($offsetMin);
                }

                // ★ここが肝：過去なら必ず「次回（翌日）」に繰り越す
                if ($remindAt->lte($now)) {
                    $remindAt = $remindAt->addDay();
                }

                // 既存の pending ルート（parent_task_id NULL）を探す
                // ※ snooze/派生は parent_task_id が入る想定なので除外できる
                $existing = DB::table('remind_tasks')
                    ->where('habit_time_id', $habitTimeId)
                    ->whereNull('parent_task_id')
                    ->where('status', 'pending')
                    ->orderByDesc('id')
                    ->first();

                if ($existing) {
                    // remind_at が違う or 過去に残ってるなら更新
                    $needUpdate = false;

                    $existingAt = Carbon::parse($existing->remind_at);
                    if (!$existingAt->equalTo($remindAt)) {
                        $needUpdate = true;
                    }
                    // root_task_id が未設定なら揃える（保険）
                    $needFixRoot = (empty($existing->root_task_id) || (int)$existing->root_task_id !== (int)$existing->id);

                    if ($needUpdate || $needFixRoot) {
                        DB::table('remind_tasks')
                            ->where('id', (int)$existing->id)
                            ->update([
                                'remind_at'    => $remindAt->format('Y-m-d H:i:s'),
                                'root_task_id' => (int)$existing->id,
                                'updated_at'   => $now->format('Y-m-d H:i:s'),
                            ]);
                    }

                    continue;
                }

                // 無ければ新規作成（root_task_id は insert 後に自分自身へ）
                $newId = DB::table('remind_tasks')->insertGetId([
                    'habit_log_id'   => null,
                    'habit_time_id'  => $habitTimeId,
                    'parent_task_id' => null,
                    'root_task_id'   => null, // 後で自分のIDを入れる
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

        return (int)$created;
    }
}
