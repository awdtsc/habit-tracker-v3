<?php
// app/Http/Controllers/HabitLogController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

use App\Models\Habit;
use App\Models\HabitLog;

class HabitLogController extends Controller
{
    public function toggle(Request $request, Habit $habit)
    {
        $userId = Auth::id();

        if ($habit->user_id !== $userId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'date'   => 'required|date',
            'status' => 'nullable|in:none,done',
            'rating' => 'nullable|integer|min:0|max:4',
        ]);

        $date   = Carbon::parse($validated['date'])->toDateString();
        $status = $validated['status'] ?? null;
        $rating = array_key_exists('rating', $validated)
                    ? $validated['rating']
                    : null;

        $slot   = $habit->time_slot ?? 0;
        $type   = $habit->evaluation_type;

        $log = HabitLog::where('habit_id', $habit->id)
            ->where('user_id', $userId)
            ->where('date', $date)
            ->where('time_slot', $slot)
            ->first();

        /* ===========================================================
         * SIMPLE
         * ===========================================================*/
        if ($type === 'simple') {

            if (!$log) {
                $log = HabitLog::create([
                    'habit_id'   => $habit->id,
                    'user_id'    => $userId,
                    'date'       => $date,
                    'time_slot'  => $slot,
                    'status'     => $status ?? 'none',
                    'rating'     => null,
                    'checked_at' => now(),
                ]);
            } else {
                if (!is_null($status)) {
                    $log->status = $status;
                }
                $log->rating = null;
                $log->checked_at = now();
                $log->save();
            }

            return $this->jsonResponse($habit, $log);
        }

        /* ===========================================================
         * SELF
         * ===========================================================*/
        if ($type === 'self') {

            if (!$log) {
                $log = HabitLog::create([
                    'habit_id'   => $habit->id,
                    'user_id'    => $userId,
                    'date'       => $date,
                    'time_slot'  => $slot,
                    'rating'     => $rating,
                    'status'     => ($rating === 4 ? 'done' : 'none'),
                    'checked_at' => now(),
                ]);
            } else {

                if (!is_null($rating)) {
                    $log->rating = $rating;
                    $log->status = ($rating === 4 ? 'done' : 'none');
                }

                if ($status === 'none' && $log->status === 'done') {
                    $log->status = 'none';
                    $log->rating = 0;
                }

                $log->checked_at = now();
                $log->save();
            }

            return $this->jsonResponse($habit, $log);
        }

        return response()->json(['message' => 'invalid evaluation_type'], 400);
    }


    /* ===========================================================
     * ★ API 契約を Today API とそろえる（最重要修正）
     * ===========================================================*/
    private function jsonResponse(Habit $habit, HabitLog $log)
    {
        return response()->json([
            'habit' => [
                'id'               => $habit->id,
                'title'            => $habit->title,
                'description'      => $habit->description,
                'time_slot'        => $habit->time_slot,
                'evaluation_type'  => $habit->evaluation_type,
                'target_times'     => $habit->target_times,
                'days_of_week'     => $habit->days_of_week,
                'archived'         => $habit->archived,
                // ▼ 今日 API と同じ位置に log を返す
                'log' => [
                    'id'         => $log->id,
                    'habit_id'   => $habit->id,
                    'date'       => $log->date,
                    'time_slot'  => $log->time_slot,
                    'status'     => $log->status,
                    'rating'     => $log->rating,
                    'checked_at' => $log->checked_at,
                ],
            ]
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}