<?php

namespace App\Services\Reminders;

use App\Services\WebPushService;
use Carbon\Carbon;

class ReminderDispatchRunner
{
    public function __construct(
        private readonly WebPushService $push,
        private readonly RemindTaskRepository $repo,
        private readonly ReminderPayloadFactory $payloads,
    ) {}

    /**
     * @param array $opts
     *  - limit:int
     *  - debug:bool
     *  - rescue_minutes:int
     *  - delay_before_claim:int
     * @param \Illuminate\Console\Command|null $console
     */
    public function run(array $opts, $console = null): array
    {
        $limit = (int)($opts['limit'] ?? 50);
        $debug = (bool)($opts['debug'] ?? false);
        $rescueMinutes = (int)($opts['rescue_minutes'] ?? 15);
        $delay = (int)($opts['delay_before_claim'] ?? 0);

        // 既存仕様を保ちつつ：retry / heartbeat
        $MAX_RETRIES = 2;
        $retryDelaysMin = [2, 5];
        $HEARTBEAT_EVERY_SEC = 20;

        // ★仕様: 個別は最大2件、3件以上はdigestにまとめる
        $DIGEST_THRESHOLD = 2; // count($notify) > 2 => digest（=3件以上）
        $DIGEST_TOP_TITLES = 3;

        $tz = 'Asia/Tokyo';

        // ★JSTで統一（DBがUTCでも、ここで統一しないと混在しやすい）
        $nowJst = fn() => now($tz);

        $appUrl = (string) config('app.url');
        $today = $this->payloads->todayJstYmd(); // YYYY-MM-DD (JST)

        // rescue（JST基準で統一）
        $now = $nowJst();
        [$rescuedPending, $rescuedError] = $this->repo->rescueSending($now, $rescueMinutes, $MAX_RETRIES);
        if ($debug && ($rescuedPending > 0 || $rescuedError > 0) && $console) {
            $console->line("rescued_sending_pending={$rescuedPending} rescued_sending_error={$rescuedError}");
        }

        // pick due（JST基準）
        $now = $nowJst();
        $ids = $this->repo->pickDueIds($now, $limit);
        if (empty($ids)) {
            if ($console) $console->info('no due tasks');
            return ['sent' => 0, 'skipped' => 0, 'error' => 0];
        }

        if ($delay > 0) {
            if ($debug && $console) $console->line("sleep_before_claim={$delay}s");
            sleep($delay);
        }

        // claim（JST基準）
        $now = $nowJst();
        [$token, $claimed] = $this->repo->claim($ids, $now);
        if ($debug && $console) {
            $console->line("picked=" . count($ids) . " claimed=" . $claimed . " token=" . $token);
        }

        $tasks = $this->repo->fetchClaimedTasks($token);
        if ($tasks->isEmpty()) {
            if ($console) $console->info('nothing claimed');
            return ['sent' => 0, 'skipped' => 0, 'error' => 0];
        }

        // group by user + collect for doneSet
        $byUser = [];
        $userIds = [];
        $habitTimeIds = [];
        foreach ($tasks as $t) {
            $uid = (int)($t->user_id ?? 0);
            $byUser[$uid] ??= [];
            $byUser[$uid][] = $t;

            if ($uid > 0) $userIds[$uid] = true;
            if (!empty($t->habit_time_id)) $habitTimeIds[(int)$t->habit_time_id] = true;
        }

        // 今日done集合（JST日付キー）
        $doneSet = $this->repo->fetchDoneSet($today, array_keys($userIds), array_keys($habitTimeIds));

        $sent = 0;
        $skipped = 0;
        $error = 0;

        $lastHeartbeatTs = 0;

        foreach ($byUser as $uid => $list) {
            // heartbeat（JST統一）
            $nowTs = time();
            if ($nowTs - $lastHeartbeatTs >= $HEARTBEAT_EVERY_SEC) {
                $this->repo->heartbeat($token, $nowJst());
                $lastHeartbeatTs = $nowTs;
            }

            if ($uid <= 0) {
                foreach ($list as $t) {
                    $this->repo->mark((int)$t->id, $token, [
                        'status' => 'error',
                        'last_error' => 'habit_time/habit/user not resolved',
                        'claim_token' => null,
                        'updated_at' => $nowJst(),
                    ]);
                    $error++;
                }
                continue;
            }

            // notify候補（今日以外・alreadyDone を除外）
            $notify = [];
            foreach ($list as $t) {
                // ★所有権を保ったままtouch（ここは軽い更新）
                $this->repo->touch((int)$t->id, $token, $nowJst());

                // ★仕様: 今日以外のタスクは捨てる（PC閉じて溜まった過去日など）
                $taskDate = null;
                try {
                    if (!empty($t->remind_at)) {
                        $taskDate = Carbon::parse($t->remind_at)->setTimezone($tz)->toDateString();
                    }
                } catch (\Throwable $e) {
                    $taskDate = null;
                }

                if ($taskDate !== $today) {
                    $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

                    $this->repo->mark((int)$t->id, $token, [
                        'status' => 'skipped',
                        'last_error' => 'stale_not_today',
                        'claim_token' => null,
                        'updated_at' => $nowJst(),
                    ]);

                    $this->repo->cancelPendingByRoot($rootId, $nowJst());

                    $skipped++;
                    if ($debug && $console) {
                        $console->line("task {$t->id} skipped: not today (taskDate=" . ($taskDate ?? 'null') . ")");
                    }
                    continue;
                }

                // alreadyDone を除外（今日のみ）
                $htId = (int)($t->habit_time_id ?? 0);
                if ($htId > 0 && isset($doneSet[$uid . ':' . $htId])) {
                    $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

                    $this->repo->mark((int)$t->id, $token, [
                        'status' => 'skipped',
                        'last_error' => null,
                        'claim_token' => null,
                        'updated_at' => $nowJst(),
                    ]);

                    $this->repo->cancelPendingByRoot($rootId, $nowJst());

                    $skipped++;
                    if ($debug && $console) $console->line("task {$t->id} skipped: already done today");
                    continue;
                }

                $notify[] = $t;
            }

            if (empty($notify)) continue;

            // digest（3件以上）
            if (count($notify) > $DIGEST_THRESHOLD) {
                // ★まず「自分がまだ所有しているtaskだけ」に絞る（double-send/競合対策）
                $owned = [];
                foreach ($notify as $t) {
                    if ($this->repo->touchOwned((int)$t->id, $token, $nowJst())) {
                        $owned[] = $t;
                    } else {
                        // 他プロセスに奪われた/キャンセル済み等 → 送らない
                        $skipped++;
                        if ($debug && $console) $console->line("task {$t->id} skip: ownership lost before digest");
                    }
                }
                if (empty($owned)) {
                    continue;
                }

                $topTitles = [];
                foreach (array_slice($owned, 0, $DIGEST_TOP_TITLES) as $t) {
                    $name = (string)($t->habit_title ?? '');
                    if ($name !== '') $topTitles[] = $name;
                }

                $payload = $this->payloads->digestPayload([
                    'app_url' => $appUrl,
                    'count' => count($owned),
                    'date_ymd' => $today,
                    'top_titles' => $topTitles,
                ]);

                $res = $this->push->sendToUser((int)$uid, $payload);

                if (!empty($res['ok'])) {
                    $taskIds = array_map(fn($t) => (int)$t->id, $owned);
                    $this->repo->markManySent($taskIds, $token, $nowJst(), 'digest');
                    $sent += count($taskIds);

                    if ($debug && $console) $console->line("digest sent user={$uid} count=" . count($taskIds));
                    continue;
                }

                // digest failed → 個別requeue/error
                $errStr = $this->payloads->errStr($res);

                foreach ($owned as $t) {
                    $attempts = (int)($t->attempts ?? 0);
                    $nextDelay = $retryDelaysMin[min($attempts, count($retryDelaysMin) - 1)];

                    if ($attempts < $MAX_RETRIES) {
                        $this->repo->mark((int)$t->id, $token, [
                            'status' => 'pending',
                            'remind_at' => $nowJst()->addMinutes($nextDelay),
                            'attempts' => $attempts + 1,
                            'last_error' => 'digest_failed_requeued: ' . $errStr,
                            'claim_token' => null,
                            'updated_at' => $nowJst(),
                        ]);
                    } else {
                        $this->repo->mark((int)$t->id, $token, [
                            'status' => 'error',
                            'last_error' => 'digest_failed: ' . $errStr,
                            'claim_token' => null,
                            'updated_at' => $nowJst(),
                        ]);
                    }
                    $error++;
                }

                if ($debug && $console) $console->line("digest failed user={$uid} tasks=" . count($owned));
                continue;
            }

            // individual（最大2件）
            foreach ($notify as $t) {
                // ★送信直前：所有権を原子的に再確認（これがdouble-send対策の核）
                if (!$this->repo->touchOwned((int)$t->id, $token, $nowJst())) {
                    $skipped++;
                    if ($debug && $console) $console->line("task {$t->id} skip: ownership lost before send");
                    continue;
                }

                try {
                    if (empty($t->habit_time_id) || empty($t->habit_id) || empty($t->user_id)) {
                        $this->repo->mark((int)$t->id, $token, [
                            'status' => 'error',
                            'last_error' => 'missing habit_time_id/habit_id/user_id',
                            'claim_token' => null,
                            'updated_at' => $nowJst(),
                        ]);
                        $error++;
                        continue;
                    }

                    $payload = $this->payloads->individualPayload([
                        'app_url' => $appUrl,
                        'task_id' => (int)$t->id,
                        'habit_time_id' => (int)$t->habit_time_id,
                        'habit_id' => (int)$t->habit_id,
                        'habit_title' => (string)($t->habit_title ?? ''),
                        'time_slot' => (int)($t->time_slot ?? 0),
                        'evaluation_type' => (string)($t->evaluation_type ?? 'simple'),
                        'date_ymd' => $today,
                        'root_task_id' => $t->root_task_id ? (int)$t->root_task_id : null,
                        'parent_task_id' => $t->parent_task_id ? (int)$t->parent_task_id : null,
                    ]);

                    $res = $this->push->sendToUser((int)$uid, $payload);

                    if (!empty($res['ok'])) {
                        $this->repo->mark((int)$t->id, $token, [
                            'status' => 'sent',
                            'sent_at' => $nowJst(),
                            'last_error' => null,
                            'claim_token' => null,
                            'updated_at' => $nowJst(),
                        ]);
                        $sent++;
                        continue;
                    }

                    $errStr = $this->payloads->errStr($res);
                    $attempts = (int)($t->attempts ?? 0);
                    $nextDelay = $retryDelaysMin[min($attempts, count($retryDelaysMin) - 1)];

                    if ($attempts < $MAX_RETRIES) {
                        $this->repo->mark((int)$t->id, $token, [
                            'status' => 'pending',
                            'remind_at' => $nowJst()->addMinutes($nextDelay),
                            'attempts' => $attempts + 1,
                            'last_error' => 'requeued: ' . $errStr,
                            'claim_token' => null,
                            'updated_at' => $nowJst(),
                        ]);
                        $error++;
                        if ($debug && $console) {
                            $console->line("task {$t->id} requeued (attempts=" . ($attempts + 1) . " delay={$nextDelay}m)");
                        }
                    } else {
                        $this->repo->mark((int)$t->id, $token, [
                            'status' => 'error',
                            'last_error' => $errStr,
                            'claim_token' => null,
                            'updated_at' => $nowJst(),
                        ]);
                        $error++;
                        if ($debug && $console) $console->line("task {$t->id} failed (attempts={$attempts}): {$errStr}");
                    }
                } catch (\Throwable $e) {
                    $msg = mb_strimwidth((string)$e->getMessage(), 0, 1000, '…', 'UTF-8');
                    $this->repo->mark((int)$t->id, $token, [
                        'status' => 'error',
                        'last_error' => $msg,
                        'claim_token' => null,
                        'updated_at' => $nowJst(),
                    ]);
                    $error++;
                    if ($debug && $console) $console->line("task {$t->id} exception: " . $msg);
                }
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'error' => $error];
    }
}
