<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reminders\ReminderActionService;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function __construct(
        private readonly ReminderActionService $actions,
    ) {}

    public function snooze(Request $request, int $task)
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $minutes = (int)($request->input('minutes', 10));

        try {
            $res = $this->actions->snooze((int)$userId, (int)$task, (int)$minutes);
            return response()->json($res);
        } catch (\RuntimeException $e) {
            $code = (int)$e->getCode();
            if ($code >= 400 && $code <= 599) {
                return response()->json(['message' => $e->getMessage()], $code);
            }
            return response()->json(['message' => 'internal error'], 500);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'internal error'], 500);
        }
    }

    public function done(Request $request, int $task)
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $res = $this->actions->doneSeries((int)$userId, (int)$task);
            return response()->json($res);
        } catch (\RuntimeException $e) {
            $code = (int)$e->getCode();
            if ($code >= 400 && $code <= 599) {
                return response()->json(['message' => $e->getMessage()], $code);
            }
            return response()->json(['message' => 'internal error'], 500);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'internal error'], 500);
        }
    }

    public function cancel(Request $request, int $task)
    {
        $userId = $request->user()?->id;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $res = $this->actions->cancelSingle((int)$userId, (int)$task);
            return response()->json($res);
        } catch (\RuntimeException $e) {
            $code = (int)$e->getCode();
            if ($code >= 400 && $code <= 599) {
                return response()->json(['message' => $e->getMessage()], $code);
            }
            return response()->json(['message' => 'internal error'], 500);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'internal error'], 500);
        }
    }
}
