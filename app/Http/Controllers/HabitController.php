<?php

namespace App\Http\Controllers;

use App\Models\Habit;
use App\Models\HabitTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HabitController extends Controller
{
    /**
     * POST /api/habits
     */
    public function store(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],

            'frequency_type' => ['required', 'string', 'max:255'],

            // ★配列で受ける（Controllerでjson_encodeしない）
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:1', 'max:7'],

            // 配列/文字列/null どれでも来うるのでここは緩く
            'target_times' => ['nullable'],

            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],

            'category' => ['nullable', 'string', 'max:255'],
            'color_tag' => ['nullable', 'string', 'max:255'],

            'evaluation_type' => ['required', Rule::in(['simple', 'self'])],

            // ★time_slots は必須（最低1つ）
            'time_slots' => ['required', 'array', 'min:1'],
            'time_slots.*' => ['integer', 'min:0', 'max:4', 'distinct'],
        ]);

        // 重複排除 + 昇順（distinctあるけど念のため安定化）
        $timeSlots = array_values(array_unique(array_map('intval', $data['time_slots'] ?? [])));
        sort($timeSlots);

        // ★0(anytime) と 1..4 の同時選択は禁止
        $hasAnytime = in_array(0, $timeSlots, true);
        $hasSlot    = (bool) array_filter($timeSlots, fn ($v) => $v >= 1 && $v <= 4);
        if ($hasAnytime && $hasSlot) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'time_slots' => ['「いつでも(0)」と「朝/昼/夕/夜(1-4)」は同時に選べません。'],
                ],
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return DB::transaction(function () use ($userId, $data, $timeSlots) {

            $habit = new Habit();
            $habit->user_id = $userId;
            $habit->title = $data['title'];
            $habit->description = $data['description'] ?? null;

            $habit->frequency_type = $data['frequency_type'];

            // ★casts(array)に任せる：配列/ null のまま
            $habit->days_of_week = $data['days_of_week'] ?? null;

            // target_times：配列ならそのまま、文字列ならそのまま、nullならnull
            $habit->target_times = $data['target_times'] ?? null;

            $habit->start_date = $data['start_date'] ?? null;
            $habit->end_date = $data['end_date'] ?? null;

            $habit->archived = false;

            // ★v3ではHabitTimeを見るので、habits.time_slot は互換で 0 固定
            $habit->time_slot = 0;

            $habit->category = $data['category'] ?? null;
            $habit->color_tag = $data['color_tag'] ?? null;

            $habit->evaluation_type = $data['evaluation_type'];

            $habit->save();

            $habitTimes = [];
            foreach ($timeSlots as $slot) {
                // 新規作成なので create でOK
                $habitTimes[] = HabitTime::create([
                    'habit_id' => $habit->id,
                    'time_slot' => $slot,
                    'notify_time' => null,
                    // ★NOT NULL対策（テーブルにあるなら0を入れておく）
                    'remind_offset' => 0,
                    // ★label は DB に無いので触らない
                ]);
            }

            return response()->json([
                'habit' => [
                    'id' => (int) $habit->id,
                    'title' => $habit->title,
                    'description' => $habit->description,
                    'frequency_type' => $habit->frequency_type,
                    'days_of_week' => $habit->days_of_week ?? [],
                    'target_times' => $habit->target_times,
                    'evaluation_type' => $habit->evaluation_type,
                    'archived' => (bool) $habit->archived,
                ],
                'habit_times' => collect($habitTimes)->map(fn (HabitTime $t) => [
                    'id' => (int) $t->id,
                    'habit_id' => (int) $t->habit_id,
                    'time_slot' => (int) $t->time_slot,
                    'notify_time' => $t->notify_time,
                    'remind_offset' => (int) ($t->remind_offset ?? 0),
                ])->values(),
            ], 201, [], JSON_UNESCAPED_UNICODE);
        });
    }
}
