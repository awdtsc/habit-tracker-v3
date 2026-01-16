<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RemindDispatch extends Command
{
    protected $signature = 'remind:dispatch
        {--limit=50 : Max tasks per run}
        {--debug : Show debug output}
        {--rescue-minutes=15 : Rescue "sending" tasks older than N minutes back to pending}
        {--delay-before-claim=0 : Sleep N seconds before claim (race test)}';

    protected $description = 'Dispatch due remind_tasks as web push notifications';

    public function handle(WebPushService $push): int
    {
        $limit = max(1, (int)$this->option('limit'));
        $debug = (bool)$this->option('debug');
        $now = now();

        // 絶対URL用のベース（末尾スラッシュ除去）
        $appUrl = rtrim((string)config('app.url'), '/');

        // 「今日」判定（habit_logs.date が Y-m-d の前提）
        $todayJst = Carbon::now('Asia/Tokyo')->toDateString();

        // 0) rescue（sending -> pending）
        $rescueMinutes = max(1, (int)$this->option('rescue-minutes'));
        $rescued = DB::table('remind_tasks')
            ->where('status', '=', 'sending')
            ->where('updated_at', '<', $now->copy()->subMinutes($rescueMinutes))
            ->update([
                'status' => 'pending',
                'claim_token' => null,
                'updated_at' => $now,
            ]);

        if ($debug && $rescued > 0) {
            $this->line("rescued_sending={$rescued}");
        }

        // 1) due を拾う（pending & remind_at<=now）
        $ids = DB::table('remind_tasks')
            ->where('status', '=', 'pending')
            ->where('remind_at', '<=', $now)
            ->orderBy('remind_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            $this->info('no due tasks');
            return self::SUCCESS;
        }

        // delay（競合テスト）
        $delay = max(0, (int)$this->option('delay-before-claim'));
        if ($delay > 0) {
            if ($debug) $this->line("sleep_before_claim={$delay}s");
            sleep($delay);
        }

        // 2) claim（pending -> sending）
        $token = Str::uuid()->toString();

        $claimed = DB::table('remind_tasks')
            ->whereIn('id', $ids)
            ->where('status', '=', 'pending')
            ->update([
                'status' => 'sending',
                'claim_token' => $token,
                'updated_at' => $now,
            ]);

        if ($debug) {
            $this->line("picked=" . count($ids) . " claimed=" . $claimed . " token=" . $token);
        }

        // claim できた分だけ処理
        $tasks = DB::table('remind_tasks')
            ->where('status', '=', 'sending')
            ->where('claim_token', '=', $token)
            ->orderBy('remind_at')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('nothing claimed');
            return self::SUCCESS;
        }

        $sentCount = 0;
        $skipCount = 0;
        $errCount  = 0;

        foreach ($tasks as $t) {
            try {
                // habit_time -> habit を引いて user_id を確定（空ログ禁止）
                if (empty($t->habit_time_id)) {
                    DB::table('remind_tasks')
                        ->where('id', $t->id)
                        ->where('claim_token', $token)
                        ->update([
                            'status' => 'error',
                            'last_error' => 'habit_time_id missing',
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);
                    $errCount++;
                    continue;
                }

                $ht = DB::table('habit_times')->where('id', (int)$t->habit_time_id)->first();
                if (!$ht) {
                    DB::table('remind_tasks')
                        ->where('id', $t->id)
                        ->where('claim_token', $token)
                        ->update([
                            'status' => 'error',
                            'last_error' => 'habit_time not found',
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);
                    $errCount++;
                    continue;
                }

                $habit = DB::table('habits')->where('id', (int)$ht->habit_id)->first();
                $userId = $habit->user_id ?? null;

                if (!$habit || !$userId) {
                    DB::table('remind_tasks')
                        ->where('id', $t->id)
                        ->where('claim_token', $token)
                        ->update([
                            'status' => 'error',
                            'last_error' => 'habit not found or user_id missing',
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);
                    $errCount++;
                    continue;
                }

                // ------------------------------------------------------------
                // ★最終防衛：すでに「今日 done」なら送らない
                // ------------------------------------------------------------
                $alreadyDone = DB::table('habit_logs')
                    ->where('habit_time_id', (int)$t->habit_time_id)
                    ->where('user_id', (int)$userId)
                    ->where('date', $todayJst)
                    ->where('status', 'done')
                    ->exists();

                if ($alreadyDone) {
                    $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

                    DB::table('remind_tasks')
                        ->where('id', $t->id)
                        ->where('claim_token', $token)
                        ->update([
                            'status' => 'skipped',
                            'last_error' => null,
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);

                    // 同系列の未処理を止める（保険）
                    DB::table('remind_tasks')
                        ->where('root_task_id', $rootId)
                        ->whereIn('status', ['pending', 'sending'])
                        ->update([
                            'status' => 'cancelled',
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);

                    $skipCount++;
                    if ($debug) $this->line("task {$t->id} skipped: already done today");
                    continue;
                }

                // 通知文
                $title = 'Habit Reminder';
                $body  = '時間です。今日の習慣をチェックしよう。';
                if (!empty($habit->title)) {
                    $body = "「{$habit->title}」の時間です。";
                }

                // 絶対URL（クリック→App.vueが from=push を拾ってモーダル）
                $url = $appUrl . '/today?from=push&task_id=' . (int)$t->id;

                $payload = [
                    'title' => $title,
                    'body'  => $body,
                    'url'   => $url,
                    'task_id' => (int)$t->id,
                    'habit_time_id' => (int)$t->habit_time_id,
                    'habit_id' => (int)$ht->habit_id,
                    'time_slot' => (int)$ht->time_slot,
                    'evaluation_type' => (string)($habit->evaluation_type ?? 'simple'),
                    'date' => $todayJst, // ★追加：YYYY-MM-DD（JST）
                    'root_task_id' => $t->root_task_id ? (int)$t->root_task_id : null,
                    'parent_task_id' => $t->parent_task_id ? (int)$t->parent_task_id : null,
                    'ts' => now()->toIso8601String(),
                ];

                $res = $push->sendToUser((int)$userId, $payload);

                if (!empty($res['ok'])) {
                    DB::table('remind_tasks')
                        ->where('id', $t->id)
                        ->where('claim_token', $token)
                        ->update([
                            'status' => 'sent',
                            'sent_at' => now(),
                            'last_error' => null,
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);
                    $sentCount++;
                } else {
                    $err = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    DB::table('remind_tasks')
                        ->where('id', $t->id)
                        ->where('claim_token', $token)
                        ->update([
                            'status' => 'error',
                            'last_error' => $err,
                            'claim_token' => null,
                            'updated_at' => now(),
                        ]);
                    $errCount++;
                    if ($debug) $this->line("task {$t->id} failed: {$err}");
                }
            } catch (\Throwable $e) {
                DB::table('remind_tasks')
                    ->where('id', $t->id)
                    ->where('claim_token', $token)
                    ->update([
                        'status' => 'error',
                        'last_error' => $e->getMessage(),
                        'claim_token' => null,
                        'updated_at' => now(),
                    ]);
                $errCount++;
                if ($debug) $this->line("task {$t->id} exception: " . $e->getMessage());
            }
        }

        $this->info("done sent={$sentCount} skipped={$skipCount} error={$errCount}");
        return ($errCount === 0) ? self::SUCCESS : self::FAILURE;
    }
}
