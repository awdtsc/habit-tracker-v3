<?php

namespace App\Services\Reminders;

use App\Services\WebPushService;

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
     *  - grace_minutes:int (optional; default 10)
     * @param \Illuminate\Console\Command|null $console
     */
    public function run(array $opts, $console = null): array
    {
        $limit = (int)($opts['limit'] ?? 50);
        $debug = (bool)($opts['debug'] ?? false);
        $rescueMinutes = (int)($opts['rescue_minutes'] ?? 15);
        $delay = (int)($opts['delay_before_claim'] ?? 0);
        $graceMinutes = (int)($opts['grace_minutes'] ?? 10);

        $policy = new ReminderDispatchPolicy(
            tz: 'Asia/Tokyo',
            graceMinutes: max(1, min(60, $graceMinutes)),
        );

        $sender = new ReminderTaskSender($this->push, $this->repo, $this->payloads, $policy);

        // ★run開始時点の JST 日付で固定（深夜境界でブレない）
        $runNow = $policy->now();
        $today = $runNow->toDateString();

        // rescue
        [$rescuedPending, $rescuedError] = $this->repo->rescueSending(
            $policy->now(),
            $rescueMinutes,
            $policy->maxRetries,
            1
        );
        if ($debug && ($rescuedPending > 0 || $rescuedError > 0) && $console) {
            $console->line("rescued_sending_pending={$rescuedPending} rescued_sending_error={$rescuedError}");
        }

        // pick due
        $ids = $this->repo->pickDueIds($policy->now(), $limit);
        if (empty($ids)) {
            if ($console) $console->info('no due tasks');
            return ['sent' => 0, 'skipped' => 0, 'error' => 0];
        }

        if ($delay > 0) {
            if ($debug && $console) $console->line("sleep_before_claim={$delay}s");
            sleep($delay);
        }

        // claim
        [$token, $claimed] = $this->repo->claim($ids, $policy->now());
        if ($debug && $console) {
            // ★SECURITY: claim_token をログに出さない
            $console->line("picked=" . count($ids) . " claimed=" . $claimed);
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
            // heartbeat
            $nowTs = time();
            if ($nowTs - $lastHeartbeatTs >= $policy->heartbeatEverySec) {
                $this->repo->heartbeat($token, $policy->now());
                $lastHeartbeatTs = $nowTs;
            }

            $r = $sender->processUser(
                uid: (int)$uid,
                tasks: $list,
                token: $token,
                todayYmd: $today,
                doneSet: $doneSet,
                runNowJst: $runNow,
                debug: $debug,
                console: $console
            );

            $sent += (int)$r['sent'];
            $skipped += (int)$r['skipped'];
            $error += (int)$r['error'];
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'error' => $error];
    }
}
