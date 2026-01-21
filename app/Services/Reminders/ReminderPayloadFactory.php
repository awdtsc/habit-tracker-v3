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
        $appUrl = rtrim((string)$ctx['app_url'], '/');
        $date = (string)$ctx['date_ymd'];

        $title = 'Habit Reminder';
        $body = '時間です。今日の習慣をチェックしよう。';
        $habitTitle = (string)($ctx['habit_title'] ?? '');
        if ($habitTitle !== '') {
            $body = "「{$habitTitle}」の時間です。";
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
            // ★timezone統一
            'ts' => $this->nowJstIso(),
        ];
    }

    public function digestPayload(array $ctx): array
    {
        // $ctx: ['app_url','count','date_ymd','top_titles'=>string[]]
        $appUrl = rtrim((string)$ctx['app_url'], '/');
        $count  = (int)$ctx['count'];
        $date   = (string)$ctx['date_ymd'];
        $topTitles = $ctx['top_titles'] ?? [];

        $title = 'Habit Reminder';
        $body  = "未確認のリマインドが{$count}件あります。タップして確認。";
        if (!empty($topTitles)) {
            $body .= "\n" . implode("\n", array_map(fn($t) => '・' . $t, $topTitles));
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
            // ★timezone統一
            'ts'     => $this->nowJstIso(),
        ];
    }

    /**
     * 送信失敗レスポンスの安全な要約文字列を作る
     *
     * 目的:
     * - DBに endpoint/keys 等の機微情報を残さない
     * - 長大なペイロードでDBを肥大させない
     *
     * 方針:
     * - ok/queued/sent/failed/removed/skipped 等の「数値サマリ」中心
     * - failures はあっても件数だけ（詳細は落とす）
     * - URL/endpoint/keys/tokenらしき長文字列は除去
     */
    public function errStr($res): string
    {
        // 配列想定だが安全に
        $a = is_array($res) ? $res : ['raw_type' => gettype($res)];

        $summary = [
            'ok'      => (bool)($a['ok'] ?? false),
            'queued'  => (int)($a['queued'] ?? 0),
            'sent'    => (int)($a['sent'] ?? 0),
            'failed'  => (int)($a['failed'] ?? 0),
            'skipped' => (int)($a['skipped'] ?? 0),
            'removed' => (int)($a['removed'] ?? 0),
        ];

        // message があれば短く入れる
        if (isset($a['message']) && is_string($a['message'])) {
            $summary['message'] = mb_strimwidth($a['message'], 0, 200, '…', 'UTF-8');
        }

        // failures の詳細は落として件数だけ
        if (isset($a['failures']) && is_array($a['failures'])) {
            $summary['failures_count'] = count($a['failures']);
        }

        // もし code/status などがあれば拾う（WebPushService側が返す場合）
        if (isset($a['code']) && (is_string($a['code']) || is_int($a['code']))) {
            $summary['code'] = $a['code'];
        }
        if (isset($a['status']) && (is_string($a['status']) || is_int($a['status']))) {
            $summary['status'] = $a['status'];
        }

        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 念のため、URLっぽいものや長いトークンっぽい断片をマスク（保険）
        $json = preg_replace('~https?://\S+~u', '[url]', (string)$json);
        $json = preg_replace('~([A-Za-z0-9_\-]{40,})~u', '[redacted]', (string)$json);

        return mb_strimwidth($json, 0, 500, '…', 'UTF-8');
    }
}
