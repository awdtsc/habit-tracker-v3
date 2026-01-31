<?php

namespace App\Services\Reminders;

use Carbon\Carbon;

class ReminderPayloadFactory
{
    private const TZ = 'Asia/Tokyo';

    public function __construct()
    {
    }

    public function todayJstYmd(): string
    {
        return Carbon::now(self::TZ)->toDateString();
    }

    private function nowJstIso(): string
    {
        return Carbon::now(self::TZ)->toIso8601String();
    }

    public function individualPayload(array $ctx): array
    {
        // $ctx: [
        //   'app_url', 'task_id', 'habit_time_id', 'habit_id', 'habit_title',
        //   'time_slot', 'evaluation_type', 'date_ymd', 'root_task_id', 'parent_task_id'
        // ]
        $appUrl = $this->safeAppUrl((string)$ctx['app_url']);
        $date = (string)$ctx['date_ymd'];

        $title = $this->sanitizeText('Habit Reminder', 60);
        $body = $this->sanitizeText('時間です。今日の習慣をチェックしよう。', 200);

        $habitTitle = (string)($ctx['habit_title'] ?? '');
        if ($habitTitle !== '') {
            $habitTitle = $this->sanitizeText($habitTitle, 60);
            $body = $this->sanitizeText("「{$habitTitle}」の時間です。", 200);
        }

        $evalType = (string)($ctx['evaluation_type'] ?? 'simple');
        $timeSlot = (int)($ctx['time_slot'] ?? 0);

        $url = $appUrl . '/today'
            . '?from=push'
            . '&task_id=' . (int)$ctx['task_id']
            . '&habit_time_id=' . (int)$ctx['habit_time_id']
            . '&date=' . rawurlencode($date)
            . '&evaluation_type=' . rawurlencode($evalType)
            . '&time_slot=' . $timeSlot;

        return [
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
            'task_id' => (int)$ctx['task_id'],
            'habit_time_id' => (int)$ctx['habit_time_id'],
            'habit_id' => (int)$ctx['habit_id'],
            'time_slot' => $timeSlot,
            'evaluation_type' => $evalType,
            'date' => $date,
            'root_task_id' => $ctx['root_task_id'] ?? null,
            'parent_task_id' => $ctx['parent_task_id'] ?? null,
            'ts' => $this->nowJstIso(),
        ];
    }

    public function digestPayload(array $ctx): array
    {
        // $ctx: ['app_url','count','date_ymd','top_titles'=>string[]]
        $appUrl = $this->safeAppUrl((string)$ctx['app_url']);
        $count  = (int)$ctx['count'];
        $date   = (string)$ctx['date_ymd'];
        $topTitles = $ctx['top_titles'] ?? [];

        $title = $this->sanitizeText('Habit Reminder', 60);
        $body  = $this->sanitizeText("未確認のリマインドが{$count}件あります。タップして確認。", 200, true);

        if (!empty($topTitles)) {
            $topTitles = array_slice($topTitles, 0, 5);
            $topTitles = array_map(
                fn($t) => $this->sanitizeText((string)$t, 40, true),
                $topTitles
            );

            $body .= "\n" . implode("\n", array_map(fn($t) => '・' . $t, $topTitles));
            $body = $this->sanitizeText($body, 240, true);
        }

        $url = $appUrl . '/today'
            . '?from=push'
            . '&digest=1'
            . '&date=' . rawurlencode($date);

        return [
            'title'  => $title,
            'body'   => $body,
            'url'    => $url,
            'digest' => true,
            'count'  => $count,
            'date'   => $date,
            'ts'     => $this->nowJstIso(),
        ];
    }

    /**
     * WebPushServiceの戻り値（配列想定）を “安全に要約” して last_error に入れるための文字列
     * - endpoint/URL/長いtokenっぽい文字列を残さない
     * - 文字数を厳格に制限する
     */
    public function errStr($res): string
    {
        $a = is_array($res) ? $res : ['raw_type' => gettype($res)];

        $summary = [
            'ok'      => (bool)($a['ok'] ?? false),
            'queued'  => (int)($a['queued'] ?? 0),
            'sent'    => (int)($a['sent'] ?? 0),
            'failed'  => (int)($a['failed'] ?? 0),
            'skipped' => (int)($a['skipped'] ?? 0),
            'removed' => (int)($a['removed'] ?? 0),
        ];

        if (isset($a['message']) && is_string($a['message'])) {
            $summary['message'] = mb_strimwidth($a['message'], 0, 200, '…', 'UTF-8');
        }

        if (isset($a['failures']) && is_array($a['failures'])) {
            $summary['failures_count'] = count($a['failures']);
        }

        if (isset($a['code']) && (is_string($a['code']) || is_int($a['code']))) {
            $summary['code'] = $a['code'];
        }
        if (isset($a['status']) && (is_string($a['status']) || is_int($a['status']))) {
            $summary['status'] = $a['status'];
        }

        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $this->maskSecretsLikeStrings((string)$json);

        return mb_strimwidth($json, 0, 500, '…', 'UTF-8');
    }

    /**
     * 例外メッセージを last_error 用に安全化（URLや長いtokenをマスクし、長さ制限）
     */
    public function safeErrorMessage(\Throwable|string $e): string
    {
        $msg = is_string($e) ? $e : (string)$e->getMessage();
        $msg = $this->maskSecretsLikeStrings($msg);
        return mb_strimwidth($msg, 0, 500, '…', 'UTF-8');
    }

    private function maskSecretsLikeStrings(string $s): string
    {
        // URLっぽいものを除去
        $s = preg_replace('~https?://\S+~u', '[url]', $s) ?? $s;
        // 40文字以上のトークンっぽい文字列を除去
        $s = preg_replace('~([A-Za-z0-9_\-]{40,})~u', '[redacted]', $s) ?? $s;
        return $s;
    }

    private function sanitizeText(string $text, int $maxLen, bool $allowNewlines = false): string
    {
        $pattern = $allowNewlines ? '~(?!\n)\p{C}~u' : '~\p{C}~u';
        $text = preg_replace($pattern, '', $text) ?? $text;
        return mb_strimwidth($text, 0, $maxLen, '…', 'UTF-8');
    }

    private function safeAppUrl(string $appUrl): string
    {
        $appUrl = trim($appUrl);
        if ($appUrl === '') {
            return '';
        }

        $parts = parse_url($appUrl);
        if ($parts === false) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        if ($scheme === '' && !str_starts_with($appUrl, '/')) {
            return '';
        }

        if ($scheme !== '' && empty($parts['host'])) {
            return '';
        }

        return rtrim($appUrl, '/');
    }
}
