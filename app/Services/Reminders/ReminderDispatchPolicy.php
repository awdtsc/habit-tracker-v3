<?php

namespace App\Services\Reminders;

use Carbon\Carbon;

class ReminderDispatchPolicy
{
    public function __construct(
        public readonly string $tz = 'Asia/Tokyo',
        public readonly int $graceMinutes = 10,
        public readonly int $digestThreshold = 2, // 3件以上でdigest
        public readonly int $digestTopTitles = 3,
        public readonly int $maxRetries = 2,
        /** @var int[] */
        public readonly array $retryDelaysMin = [2, 5],
        public readonly int $heartbeatEverySec = 20,
    ) {}

    public function now(): Carbon
    {
        return now($this->tz);
    }

    /**
     * @param mixed $remindAt
     */
    public function parseRemindAtToJst($remindAt): ?Carbon
    {
        try {
            if ($remindAt instanceof \Carbon\CarbonInterface) {
                return $remindAt->copy()->setTimezone($this->tz);
            }
            if (!empty($remindAt)) {
                // ★TZを明示
                return Carbon::parse((string)$remindAt, $this->tz)->setTimezone($this->tz);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * @param mixed $remindAt
     */
    public function taskDateYmd($remindAt): ?string
    {
        $dt = $this->parseRemindAtToJst($remindAt);
        return $dt ? $dt->toDateString() : null;
    }

    public function isExpired(Carbon $remindAtJst, Carbon $runNowJst): bool
    {
        // remind_at が「今 - grace」より古ければ expired
        return $remindAtJst->lt($runNowJst->copy()->subMinutes($this->graceMinutes));
    }
}
