<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reminders\ReminderActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReminderController extends Controller
{
    public function __construct(
        private readonly ReminderActionService $actions,
    ) {}

    public function snooze(Request $request, int $task): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->err('Unauthenticated.', 401);
        }

        $taskId = $this->validateTaskId($task);
        if ($taskId === null) {
            return $this->err('Invalid task id.', 422);
        }

        // minutes validation: 1..180 (必要なら増やしてOK)
        $minutesRaw = $request->input('minutes', null);
        $minutes = is_numeric($minutesRaw) ? (int)$minutesRaw : null;
        if ($minutes === null || $minutes < 1 || $minutes > 180) {
            return $this->err('Invalid minutes. (1..180)', 422);
        }

        // ★Ownership enforcement (within this file): DBで「このtaskはこのuserのもの」を確定
        if (!$this->isOwnedTask((int)$user->id, $taskId)) {
            // 404にして存在秘匿（APIの定石）
            return $this->err('Not found.', 404);
        }

        try {
            $res = $this->actions->snooze((int)$user->id, $taskId, $minutes);
            return $this->ok($res);
        } catch (\RuntimeException $e) {
            return $this->runtimeToJson($e);
        } catch (\Throwable $e) {
            return $this->err('Internal server error.', 500);
        }
    }

    public function done(Request $request, int $task): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->err('Unauthenticated.', 401);
        }

        $taskId = $this->validateTaskId($task);
        if ($taskId === null) {
            return $this->err('Invalid task id.', 422);
        }

        if (!$this->isOwnedTask((int)$user->id, $taskId)) {
            return $this->err('Not found.', 404);
        }

        try {
            // ※既存設計: done は series（root単位）で処理する想定
            $res = $this->actions->doneSeries((int)$user->id, $taskId);
            return $this->ok($res);
        } catch (\RuntimeException $e) {
            return $this->runtimeToJson($e);
        } catch (\Throwable $e) {
            return $this->err('Internal server error.', 500);
        }
    }

    public function cancel(Request $request, int $task): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->err('Unauthenticated.', 401);
        }

        $taskId = $this->validateTaskId($task);
        if ($taskId === null) {
            return $this->err('Invalid task id.', 422);
        }

        if (!$this->isOwnedTask((int)$user->id, $taskId)) {
            return $this->err('Not found.', 404);
        }

        try {
            // ※既存設計: cancel は single（このtask単位）想定
            $res = $this->actions->cancelSingle((int)$user->id, $taskId);
            return $this->ok($res);
        } catch (\RuntimeException $e) {
            return $this->runtimeToJson($e);
        } catch (\Throwable $e) {
            return $this->err('Internal server error.', 500);
        }
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function validateTaskId(int $task): ?int
    {
        return $task > 0 ? $task : null;
    }

    /**
     * Ownership check inside controller scope (so the 2-file audit can prove it).
     * Uses: remind_tasks -> habit_times -> habits -> user_id
     */
    private function isOwnedTask(int $userId, int $taskId): bool
    {
        $cnt = DB::table('remind_tasks as rt')
            ->join('habit_times as ht', 'ht.id', '=', 'rt.habit_time_id')
            ->join('habits as h', 'h.id', '=', 'ht.habit_id')
            ->where('rt.id', $taskId)
            ->where('h.user_id', $userId)
            ->count();

        return (int)$cnt > 0;
    }

    private function ok(mixed $payload, int $status = 200): JsonResponse
    {
        // 既存の service 返却が {ok:...} 形式なら尊重して返す（破壊的変更を避ける）
        if (is_array($payload) && array_key_exists('ok', $payload)) {
            return response()->json($payload, $status);
        }

        return response()->json(['ok' => true, 'data' => $payload], $status);
    }

    private function err(string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }

    private function runtimeToJson(\RuntimeException $e): JsonResponse
    {
        $code = (int) $e->getCode();
        $status = ($code >= 400 && $code <= 599) ? $code : 500;

        // 500 の場合は内部メッセージを隠す（事故防止）
        $message = ($status === 500) ? 'Internal server error.' : (string) $e->getMessage();

        return $this->err($message, $status);
    }
}
