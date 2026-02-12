<?php

namespace App\Services\Reminders;

use App\Services\WebPushService;
use Carbon\Carbon;

// 明示import（拡張の警告回避用）
use App\Services\Reminders\RemindTaskRepository;
use App\Services\Reminders\ReminderPayloadFactory;
use App\Services\Reminders\ReminderDispatchPolicy;

class ReminderTaskSender
{
    public function __construct(
        private readonly WebPushService $push,
        private readonly RemindTaskRepository $repo,
        private readonly ReminderPayloadFactory $payloads,
        private readonly ReminderDispatchPolicy $policy,
    ) {}

    /**
     * @param array<string,bool> $doneSet  key: "{user_id}:{habit_time_id}"
     * @return array{sent:int, skipped:int, error:int}
     */
    public function processUser(
        int $uid,
        array $tasks,
        string $token,
        string $todayYmd,
        array $doneSet,
        Carbon $runNowJst,
        bool $debug,
        $console = null
    ): array {
        $sent = 0;
        $skipped = 0;
        $error = 0;

        $appUrl = (string) config('app.url');

        if ($uid <= 0) {
            foreach ($tasks as $t) {
                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'error',
                    'last_error' => 'habit_time/habit/user not resolved',
                    'claim_token' => null,
                    'updated_at' => $this->policy->now(),
                ]);
                $error++;
            }
            return compact('sent', 'skipped', 'error');
        }

        // 送信候補を抽出
        $notify = [];
        foreach ($tasks as $t) {
            $now = $this->policy->now();

            // claim保持のまま更新（stuck sending 対策の延長）
            $this->repo->touch((int)$t->id, $token, $now);

            // remind_at を JST で読む（★TZ固定）
            $remindAtJst = $this->policy->parseRemindAtToJst($t->remind_at ?? null);
            $taskDate = $remindAtJst ? $remindAtJst->toDateString() : null;

            // 今日以外は捨てる
            if ($taskDate !== $todayYmd) {
                $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'skipped',
                    'last_error' => 'stale_not_today',
                    'claim_token' => null,
                    'updated_at' => $now,
                ]);
                $this->repo->cancelPendingByRoot($rootId, $now);

                $skipped++;
                if ($debug && $console) {
                    $console->line("task {$t->id} skipped: not today (taskDate=" . ($taskDate ?? 'null') . ")");
                }
                continue;
            }

            // ★grace window: 古すぎるものは “溜めない”
            if ($remindAtJst && $this->policy->isExpired($remindAtJst, $runNowJst)) {
                $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'skipped',
                    'last_error' => 'expired_grace',
                    'claim_token' => null,
                    'updated_at' => $now,
                ]);
                $this->repo->cancelPendingByRoot($rootId, $now);

                $skipped++;
                if ($debug && $console) {
                    $console->line("task {$t->id} skipped: expired_grace (remind_at=" . ($remindAtJst?->toDateTimeString() ?? 'null') . ")");
                }
                continue;
            }

            // alreadyDone を除外
            $htId = (int)($t->habit_time_id ?? 0);
            if ($htId > 0 && isset($doneSet[$uid . ':' . $htId])) {
                $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'skipped',
                    'last_error' => null,
                    'claim_token' => null,
                    'updated_at' => $now,
                ]);
                $this->repo->cancelPendingByRoot($rootId, $now);

                $skipped++;
                if ($debug && $console) $console->line("task {$t->id} skipped: already done today");
                continue;
            }

            $notify[] = $t;
        }

        if (empty($notify)) {
            return compact('sent', 'skipped', 'error');
        }

        // digest（3件以上）
        if (count($notify) > $this->policy->digestThreshold) {
            [$s, $k, $e] = $this->sendDigest($uid, $notify, $token, $todayYmd, $appUrl, $debug, $console);
            $sent += $s; $skipped += $k; $error += $e;
            return compact('sent', 'skipped', 'error');
        }

        // individual
        foreach ($notify as $t) {
            [$s, $k, $e] = $this->sendIndividual($uid, $t, $token, $todayYmd, $appUrl, $debug, $console);
            $sent += $s; $skipped += $k; $error += $e;
        }

        return compact('sent', 'skipped', 'error');
    }

    /**
     * @return array{0:int,1:int,2:int} [sent, skipped, error]
     */
    private function sendDigest(int $uid, array $notify, string $token, string $todayYmd, string $appUrl, bool $debug, $console): array
    {
        $sent = 0; $skipped = 0; $error = 0;

        $owned = [];
        foreach ($notify as $t) {
            if ($this->repo->touchOwned((int)$t->id, $token, $this->policy->now())) {
                $owned[] = $t;
            } else {
                $skipped++;
                if ($debug && $console) $console->line("task {$t->id} skip: ownership lost before digest");
            }
        }
        if (empty($owned)) return [$sent, $skipped, $error];

        $topTitles = [];
        foreach (array_slice($owned, 0, $this->policy->digestTopTitles) as $t) {
            $name = (string)($t->habit_title ?? '');
            if ($name !== '') $topTitles[] = $name;
        }

        $payload = $this->payloads->digestPayload([
            'app_url' => $appUrl,
            'count' => count($owned),
            'date_ymd' => $todayYmd,
            'top_titles' => $topTitles,
        ]);

        try {
            $res = $this->push->sendToUser((int)$uid, $payload);
        } catch (\Throwable $e) {
            $res = [
                'ok' => false,
                'queued' => 0,
                'sent' => 0,
                'failed' => count($owned),
                'skipped' => 0,
                'removed' => 0,
                'message' => 'digest_exception: ' . $this->payloads->safeErrorMessage($e),
            ];
        }

        if (!empty($res['ok'])) {
            $now = $this->policy->now();
            $taskIds = array_map(fn($t) => (int)$t->id, $owned);

            $this->repo->markManySent($taskIds, $token, $now, 'digest');
            $sent += count($taskIds);

            // 成功時：rootごとに pending を掃除（二重送信保険）
            $rootIds = [];
            foreach ($owned as $t) {
                $rootIds[] = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;
            }
            $rootIds = array_values(array_unique($rootIds));
            foreach ($rootIds as $rid) {
                $this->repo->cancelPendingByRoot($rid, $now);
            }

            if ($debug && $console) $console->line("digest sent user={$uid} count=" . count($taskIds));
            return [$sent, $skipped, $error];
        }

        // digest failed → 個別 requeue/error
        $errStr = $this->payloads->errStr($res);
        foreach ($owned as $t) {
            $attempts = (int)($t->attempts ?? 0);
            $nextDelay = $this->policy->retryDelaysMin[min($attempts, count($this->policy->retryDelaysMin) - 1)];

            if ($attempts < $this->policy->maxRetries) {
                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'pending',
                    'remind_at' => $this->policy->now()->addMinutes($nextDelay),
                    'attempts' => $attempts + 1,
                    'last_error' => 'digest_failed_requeued: ' . $errStr,
                    'claim_token' => null,
                    'updated_at' => $this->policy->now(),
                ]);
            } else {
                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'error',
                    'last_error' => 'digest_failed: ' . $errStr,
                    'claim_token' => null,
                    'updated_at' => $this->policy->now(),
                ]);
            }
            $error++;
        }

        if ($debug && $console) $console->line("digest failed user={$uid} tasks=" . count($owned));
        return [$sent, $skipped, $error];
    }

    /**
     * @return array{0:int,1:int,2:int} [sent, skipped, error]
     */
    private function sendIndividual(int $uid, object $t, string $token, string $todayYmd, string $appUrl, bool $debug, $console): array
    {
        $sent = 0; $skipped = 0; $error = 0;

        if (!$this->repo->touchOwned((int)$t->id, $token, $this->policy->now())) {
            $skipped++;
            if ($debug && $console) $console->line("task {$t->id} skip: ownership lost before send");
            return [$sent, $skipped, $error];
        }

        try {
            if (empty($t->habit_time_id) || empty($t->habit_id) || empty($t->user_id)) {
                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'error',
                    'last_error' => 'missing habit_time_id/habit_id/user_id',
                    'claim_token' => null,
                    'updated_at' => $this->policy->now(),
                ]);
                $error++;
                return [$sent, $skipped, $error];
            }

            // ★payloadは必要最小限（root/parentは送らない）
            $payload = $this->payloads->individualPayload([
                'app_url' => $appUrl,
                'task_id' => (int)$t->id,
                'habit_time_id' => (int)$t->habit_time_id,
                'habit_id' => (int)$t->habit_id,
                'habit_title' => (string)($t->habit_title ?? ''),
                'time_slot' => (int)($t->time_slot ?? 0),
                'evaluation_type' => (string)($t->evaluation_type ?? 'simple'),
                'date_ymd' => $todayYmd,
            ]);

            $res = $this->push->sendToUser((int)$uid, $payload);

            if (!empty($res['ok'])) {
                $now = $this->policy->now();

                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'sent',
                    'sent_at' => $now,
                    'last_error' => null,
                    'claim_token' => null,
                    'updated_at' => $now,
                ]);
                $sent++;

                // 成功時：同rootのpending掃除（二重送信保険）
                $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;
                $this->repo->cancelPendingByRoot($rootId, $now);

                return [$sent, $skipped, $error];
            }

            $errStr = $this->payloads->errStr($res);
            $attempts = (int)($t->attempts ?? 0);
            $nextDelay = $this->policy->retryDelaysMin[min($attempts, count($this->policy->retryDelaysMin) - 1)];

            if ($attempts < $this->policy->maxRetries) {
                $this->repo->mark((int)$t->id, $token, [
                    'status' => 'pending',
                    'remind_at' => $this->policy->now()->addMinutes($nextDelay),
                    'attempts' => $attempts + 1,
                    'last_error' => 'requeued: ' . $errStr,
                    'claim_token' => null,
                    'updated_at' => $this->policy->now(),
                ]);
                $error++;
                if ($debug && $console) {
                    $console->line("task {$t->id} requeued (attempts=" . ($attempts + 1) . " delay={$nextDelay}m)");
                }
                return [$sent, $skipped, $error];
            }

            $this->repo->mark((int)$t->id, $token, [
                'status' => 'error',
                'last_error' => $errStr,
                'claim_token' => null,
                'updated_at' => $this->policy->now(),
            ]);
            $error++;
            if ($debug && $console) $console->line("task {$t->id} failed (attempts={$attempts}): {$errStr}");
            return [$sent, $skipped, $error];

        } catch (\Throwable $e) {
            $msg = $this->payloads->safeErrorMessage($e);
            $this->repo->mark((int)$t->id, $token, [
                'status' => 'error',
                'last_error' => $msg,
                'claim_token' => null,
                'updated_at' => $this->policy->now(),
            ]);
            $error++;
            if ($debug && $console) $console->line("task {$t->id} exception: " . $msg);
            return [$sent, $skipped, $error];
        }
    }
}