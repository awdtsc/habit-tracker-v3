<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reminders\ReminderActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReminderController extends Controller
{
    public function __construct(
        private readonly ReminderActionService $actions,
    ) {}

    public function snooze(Request $request, $task): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->err('Unauthenticated.', 401);
        }

        $taskId = $this->validateTaskId($task);
        if ($taskId === null) {
            return $this->err('Invalid task id.', 422);
        }

        $minutesRaw = $request->input('minutes', null);
        $minutes = is_numeric($minutesRaw) ? (int) $minutesRaw : null;
        if ($minutes === null || $minutes < 1 || $minutes > 180) {
            return $this->err('Invalid minutes. (1..180)', 422);
        }

        if (!$this->isOwnedTask((int) $user->id, $taskId)) {
            return $this->err('Not found.', 404);
        }

        // Idempotency + concurrency guard (short TTL)
        $lockKey = $this->actionLockKey((int) $user->id, $taskId, 'snooze', $minutes);
        $lock = Cache::lock($lockKey, 10);
        $acquired = $lock->get();

        if (!$acquired) {
            return $this->err('Duplicate request.', 409);
        }

        try {
            // ★ service 定義に合わせて userId を渡す
            $res = $this->actions->snooze((int) $user->id, $taskId, $minutes);
            return $this->ok($res);
        } catch (\RuntimeException $e) {
            return $this->runtimeToJson($e);
        } catch (\Throwable $e) {
            return $this->err('Internal server error.', 500);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    public function done(Request $request, $task): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->err('Unauthenticated.', 401);
        }

        $taskId = $this->validateTaskId($task);
        if ($taskId === null) {
            return $this->err('Invalid task id.', 422);
        }

        if (!$this->isOwnedTask((int) $user->id, $taskId)) {
            return $this->err('Not found.', 404);
        }

        $lockKey = $this->actionLockKey((int) $user->id, $taskId, 'done', null);
        $lock = Cache::lock($lockKey, 10);
        $acquired = $lock->get();

        if (!$acquired) {
            return $this->err('Duplicate request.', 409);
        }

        try {
            // ★ service 定義に合わせて userId を渡す
            $res = $this->actions->doneSeries((int) $user->id, $taskId);
            return $this->ok($res);
        } catch (\RuntimeException $e) {
            return $this->runtimeToJson($e);
        } catch (\Throwable $e) {
            return $this->err('Internal server error.', 500);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    public function cancel(Request $request, $task): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->err('Unauthenticated.', 401);
        }

        $taskId = $this->validateTaskId($task);
        if ($taskId === null) {
            return $this->err('Invalid task id.', 422);
        }

        if (!$this->isOwnedTask((int) $user->id, $taskId)) {
            return $this->err('Not found.', 404);
        }

        $lockKey = $this->actionLockKey((int) $user->id, $taskId, 'cancel', null);
        $lock = Cache::lock($lockKey, 10);
        $acquired = $lock->get();

        if (!$acquired) {
            return $this->err('Duplicate request.', 409);
        }

        try {
            // ★ service 定義に合わせて userId を渡す
            $res = $this->actions->cancelSingle((int) $user->id, $taskId);
            return $this->ok($res);
        } catch (\RuntimeException $e) {
            return $this->runtimeToJson($e);
        } catch (\Throwable $e) {
            return $this->err('Internal server error.', 500);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function validateTaskId($task): ?int
    {
        if (is_int($task)) {
            return $task > 0 ? $task : null;
        }
        if (is_string($task) && ctype_digit($task)) {
            $id = (int) $task;
            return $id > 0 ? $id : null;
        }
        if (is_numeric($task)) {
            $id = (int) $task;
            return $id > 0 ? $id : null;
        }
        return null;
    }

    private function isOwnedTask(int $userId, int $taskId): bool
    {
        return DB::table('remind_tasks as rt')
            ->join('habit_times as ht', 'ht.id', '=', 'rt.habit_time_id')
            ->join('habits as h', 'h.id', '=', 'ht.habit_id')
            ->where('rt.id', $taskId)
            ->where('h.user_id', $userId)
            ->exists();
    }

    private function actionLockKey(int $userId, int $taskId, string $action, ?int $minutes): string
    {
        $suffix = $minutes === null ? 'na' : (string) $minutes;
        return 'reminder_action:' . sha1($userId . '|' . $taskId . '|' . $action . '|' . $suffix);
    }

    private function ok(mixed $payload, int $status = 200): JsonResponse
    {
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
        $message = ($status === 500) ? 'Internal server error.' : (string) $e->getMessage();
        return $this->err($message, $status);
    }
}
