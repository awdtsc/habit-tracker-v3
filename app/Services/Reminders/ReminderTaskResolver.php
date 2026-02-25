<?php

namespace App\Services\Reminders;

use Illuminate\Support\Facades\DB;

class ReminderTaskResolver
{
    public function getTaskOrFail(int $taskId): object
    {
        $t = DB::table('remind_tasks')->where('id', $taskId)->first();
        if (!$t) {
            throw new \RuntimeException('task not found', 404);
        }
        return $t;
    }

    public function getHabitTimeOrFail(int $habitTimeId): object
    {
        $ht = DB::table('habit_times')->where('id', $habitTimeId)->first();
        if (!$ht) {
            throw new \RuntimeException('habit_time not found', 404);
        }
        return $ht;
    }

    public function getHabitOrFail(int $habitId): object
    {
        $habit = DB::table('habits')->where('id', $habitId)->first();
        if (!$habit) {
            throw new \RuntimeException('habit not found', 404);
        }
        return $habit;
    }

    public function assertOwnershipOrFail(object $habit, int $userId): void
    {
        if ((int)($habit->user_id ?? 0) !== (int)$userId) {
            throw new \RuntimeException('forbidden', 403);
        }
    }

    /**
     * task -> habit_time -> habit をまとめて解決し、所有確認も行う
     * @return array{task:object, habitTime:object, habit:object, rootId:int}
     */
    public function resolveTaskForUser(int $userId, int $taskId): array
    {
        $t = $this->getTaskOrFail($taskId);

        if (empty($t->habit_time_id)) {
            throw new \RuntimeException('habit_time_id missing', 500);
        }

        $ht = $this->getHabitTimeOrFail((int)$t->habit_time_id);
        if (empty($ht->habit_id)) {
            throw new \RuntimeException('habit_id missing', 500);
        }

        $habit = $this->getHabitOrFail((int)$ht->habit_id);
        $this->assertOwnershipOrFail($habit, $userId);

        $rootId = $t->root_task_id ? (int)$t->root_task_id : (int)$t->id;

        return [
            'task' => $t,
            'habitTime' => $ht,
            'habit' => $habit,
            'rootId' => $rootId,
        ];
    }
}
