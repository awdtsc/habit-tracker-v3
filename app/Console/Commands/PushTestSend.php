<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;

class PushTestSend extends Command
{
    protected $signature = 'push:test
        {userId : target user_id (required)}
        {--title=Test : notification title}
        {--body=Hello : notification body}
        {--url=/today : click url (SPA path)}
        {--debug : show failure summary}
        {--force : allow execution in production}';

    protected $description = 'Send a test web push notification to a user (safe-guarded)';

    public function handle(WebPushService $svc): int
    {
        // Safety gate: block accidental production usage unless explicitly forced
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('Blocked in production. Re-run with --force if you REALLY intend to send.');
            return self::FAILURE;
        }

        $userId = (int) $this->argument('userId');
        if ($userId <= 0) {
            $this->error('Invalid userId.');
            return self::FAILURE;
        }

        $payload = [
            'title' => (string) $this->option('title'),
            'body'  => (string) $this->option('body'),
            'url'   => (string) $this->option('url'),
            'ts'    => now()->toIso8601String(),
        ];

        $res = $svc->sendToUser($userId, $payload);

        $this->info(sprintf(
            'ok=%s queued=%d sent=%d failed=%d skipped=%d removed=%d',
            !empty($res['ok']) ? 'true' : 'false',
            (int) ($res['queued'] ?? 0),
            (int) ($res['sent'] ?? 0),
            (int) ($res['failed'] ?? 0),
            (int) ($res['skipped'] ?? 0),
            (int) ($res['removed'] ?? 0)
        ));

        // Debug: do NOT dump raw failures (may include endpoint-ish data)
        if ($this->option('debug')) {
            $failures = $res['failures'] ?? [];
            $this->line('failures_count=' . (is_array($failures) ? count($failures) : 0));

            if (is_array($failures)) {
                foreach (array_slice($failures, 0, 3) as $i => $f) {
                    $msg = is_array($f) ? ($f['message'] ?? ($f['reason'] ?? 'failure')) : 'failure';
                    $code = is_array($f) ? ($f['code'] ?? '') : '';
                    $this->line(sprintf('#%d code=%s message=%s', $i + 1, (string) $code, (string) $msg));
                }
            }
        }

        return !empty($res['ok']) ? self::SUCCESS : self::FAILURE;
    }
}
