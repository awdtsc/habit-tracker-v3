<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WebPushService;

class PushTestSend extends Command
{
    protected $signature = 'push:test 
        {userId=1 : target user_id}
        {--title=Test : notification title}
        {--body=Hello : notification body}
        {--url=/today : click url (SPA path)}
        {--debug : show failure details}';

    protected $description = 'Send a test web push notification to a user';

    public function handle(WebPushService $svc): int
    {
        $userId = (int)$this->argument('userId');

        $payload = [
            'title' => (string)$this->option('title'),
            'body'  => (string)$this->option('body'),
            'url'   => (string)$this->option('url'),
            'ts'    => now()->toIso8601String(),
        ];

        $res = $svc->sendToUser($userId, $payload);

        $this->info(sprintf(
            'ok=%s queued=%d sent=%d failed=%d skipped=%d',
            $res['ok'] ? 'true' : 'false',
            $res['queued'],
            $res['sent'],
            $res['failed'],
            $res['skipped']
        ));

        if ($this->option('debug')) {
            $this->line(json_encode($res['failures'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
