<!-- resources/js/components/tabs/TodayTab.vue -->
<template>
  <div class="p-4 md:p-6 space-y-6">

    <!-- ▼ ヘッダー（最小構成） -->
    <header>
      <h1 class="text-2xl font-semibold">今日</h1>
      <p class="text-sm text-gray-500">{{ today }}</p>
    </header>

    <!-- ▼ ロード中 / エラー -->
    <div v-if="loading" class="text-gray-500 text-sm">読み込み中…</div>
    <div v-else-if="errorMessage" class="text-red-500 text-sm">{{ errorMessage }}</div>

    <template v-else>

      <!-- -------------------------------------------------------------- -->
      <!-- ▼ タブ（自動 / 朝 / 昼 / 夕 / 夜 / すべて） -->
      <!-- -------------------------------------------------------------- -->
      <div class="flex items-center gap-2 border-b pb-2 mb-4">
        <button
          v-for="t in tabs"
          :key="t.key"
          :class="[
            'px-3 py-1 rounded-full text-sm border transition',
            activeTab === t.key
              ? 'bg-blue-600 text-white border-blue-600'
              : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100'
          ]"
          @click="activeTab = t.key"
        >
          {{ t.label }}
        </button>
      </div>

      <!-- -------------------------------------------------------------- -->
      <!-- ▼ 達成率バー -->
      <!-- -------------------------------------------------------------- -->
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

        <p class="text-xs text-gray-500">{{ progress.done_count }} / {{ progress.planned_count }} 件完了</p>
      </section>

      <!-- ============================================================== -->
      <!-- ▼ タブ別コンテンツ -->
      <!-- ============================================================== -->

      <!-- ▼ 自動タブ -->
      <template v-if="activeTab === 'auto'">

        <TodayActionableSection
          :habits="actionable"
          :today="today"
          @update="onRowUpdate"
        />

        <TodayNextSlotSection
          :habits="nextSlot"
          :today="today"
          @update="onRowUpdate"
        />

        <TodayDoneSection
          :habits="doneHabits"
          :today="today"
          @update="onRowUpdate"
        />

        <!-- 空 -->
        <section
          v-if="
            (!actionable || actionable.length === 0) &&
            (!nextSlot || nextSlot.length === 0) &&
            (!doneHabits || doneHabits.length === 0)
          "
          class="text-sm text-gray-500"
        >
          今日の習慣はまだ登録されていません。
        </section>

      </template>

      <!-- ▼ 朝/昼/夕/夜タブ -->
      <template v-else-if="isSlotTab">
        <TodaySlotView
          :slot="activeTab"
          :today="today"
          :habits="habits"
          :progress="progress"
          :now-slot="nowSlot"
          @update="onRowUpdate"
        />
      </template>

      <!-- ▼ すべてタブ -->
      <template v-else-if="activeTab === 'all'">
        <TodayAllView />
      </template>

    </template>

  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

// ▼ 必要なコンポーネントだけ import（存在するものだけ）
import TodayActionableSection from '@/components/today/TodayActionableSection.vue'
import TodayNextSlotSection from '@/components/today/TodayNextSlotSection.vue'
import TodayDoneSection from '@/components/today/TodayDoneSection.vue'
import TodaySlotView from '@/components/today/TodaySlotView.vue'
import TodayAllView from '@/components/today/TodayAllView.vue'

// ▼ composables
import { useTodayLoader } from '@/composables/useTodayLoader'
import { useTodayFilters } from '@/composables/useTodayFilters'
import { useHabitToggle } from '@/composables/useHabitToggle'

/* ---------------------------------------------------------
   ▼ タブ
--------------------------------------------------------- */
const tabs = [
  { key: 'auto',    label: '自動' },
  { key: 'morning', label: '朝' },
  { key: 'noon',    label: '昼' },
  { key: 'evening', label: '夕' },
  { key: 'night',   label: '夜' },
  { key: 'all',     label: 'すべて' },
]

const activeTab = ref('auto')

const isSlotTab = computed(() =>
  ['morning', 'noon', 'evening', 'night'].includes(activeTab.value)
)

/* ---------------------------------------------------------
   ▼ Today ロード
--------------------------------------------------------- */
const {
  today,
  nowSlot,
  habits,
  progress,
  loading,
  errorMessage,
} = useTodayLoader()

/* ---------------------------------------------------------
   ▼ フィルタ分類
--------------------------------------------------------- */
const {
  actionable,
  nextSlot,
  doneHabits,
  progressPct,
} = useTodayFilters(habits, progress, nowSlot)

/* ---------------------------------------------------------
   ▼ トグル
--------------------------------------------------------- */
const { toggle } = useHabitToggle(today)

async function onRowUpdate({ habit, payload }) {
  const { log } = await toggle(habit, payload)
  habit.log = { ...log }
}
</script>