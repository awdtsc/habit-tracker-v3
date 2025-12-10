<!-- resources/js/components/tabs/TodayTab.vue -->
<template>
  <div class="p-4 md:p-6 space-y-6">
    
    <!-- ヘッダー -->
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold">今日</h1>
        <p class="text-sm text-gray-500">{{ today }}</p>
      </div>
    </header>

    <!-- ロード中 / エラー -->
    <div v-if="loading" class="text-gray-500 text-sm">読み込み中…</div>
    <div v-else-if="errorMessage" class="text-red-500 text-sm">{{ errorMessage }}</div>

    <template v-else>
      <!-- 進捗 -->
      <section class="space-y-2">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-600">今日の達成率</span>
          <span class="text-sm font-semibold">{{ progressPct }}%</span>
        </div>
        <div class="w-full h-2 rounded-full bg-gray-200 overflow-hidden">
          <div
            class="h-full bg-blue-500 transition-all"
            :style="{ width: progressPct + '%' }"
          />
        </div>
        <p class="text-xs text-gray-500">
          {{ progress.done_count }} / {{ progress.planned_count }} 件完了
        </p>
      </section>

      <!-- 朝 -->
      <TodaySection label="朝" :items="morningHabits" :onUpdate="onRowUpdate" />

      <!-- 昼 -->
      <TodaySection label="昼" :items="noonHabits" :onUpdate="onRowUpdate" />

      <!-- 夕方 -->
      <TodaySection label="夕方" :items="eveningHabits" :onUpdate="onRowUpdate" />

      <!-- 夜 -->
      <TodaySection label="夜" :items="nightHabits" :onUpdate="onRowUpdate" />

      <!-- いつでも -->
      <TodaySection label="いつでも" :items="anytimeHabits" :onUpdate="onRowUpdate" />

      <!-- 完了 -->
      <TodaySection label="完了" :items="doneHabits" :onUpdate="onRowUpdate" done />

      <!-- 空表示 -->
      <section
        v-if="
          !morningHabits.length &&
          !noonHabits.length &&
          !eveningHabits.length &&
          !nightHabits.length &&
          !anytimeHabits.length
        "
        class="text-sm text-gray-500"
      >
        今日の習慣はまだ登録されていません。
      </section>
    </template>
  </div>
</template>

<script setup>
import TodaySection from '@/components/today/TodaySection.vue'
import { useTodayLoader } from '@/composables/useTodayLoader'
import { useTodayFilters } from '@/composables/useTodayFilters'
import { useHabitToggle } from '@/composables/useHabitToggle'

// -------------------------
// Today ロード
// -------------------------
const {
  today,
  nowSlot,
  habits,
  progress,
  topPick,
  loading,
  errorMessage,
  refresh,
} = useTodayLoader()

// -------------------------
// Slot別フィルタリング
// -------------------------
const {
  morningHabits,
  noonHabits,
  eveningHabits,
  nightHabits,
  anytimeHabits,
  doneHabits,
  progressPct,
} = useTodayFilters(habits, progress)

// -------------------------
// トグル
// -------------------------
const { toggle } = useHabitToggle(today)

async function onRowUpdate(habit, payload) {
  await toggle(habit, payload)
}
</script>