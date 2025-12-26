<!-- resources/js/components/today/HabitRow.vue -->
<template>
  <div
    class="flex items-center justify-between gap-4 py-3"
    :class="isDone ? 'opacity-70' : ''"
  >
    <!-- 左側：タイトル + 回数 + ゲージ -->
    <div class="min-w-0 flex-1">
      <div class="font-medium truncate" :class="isDone ? 'line-through' : ''">
        {{ habit.title }}
      </div>

      <div class="text-xs text-gray-500 mt-0.5">
        {{ goalText }}
      </div>

      <!-- ★回数ベース進捗ゲージ（0/50/100...） -->
      <div class="mt-2 h-2 w-40 rounded-full bg-gray-200 overflow-hidden">
        <div
          class="h-full transition-all bg-green-500"
          :style="{ width: dailyPct + '%' }"
        ></div>
      </div>
    </div>

    <!-- 右側：評価ボタン → 状態ラベル → 完了ボタン -->
    <div class="flex items-center gap-3 shrink-0">
      <!-- SELF のときだけ評価ボタン -->
      <div v-if="isSelf" class="flex gap-2 mr-2">
        <button
          v-for="n in [0, 1, 2, 3, 4]"
          :key="n"
          type="button"
          @click="onSelectRating(n)"
          class="w-7 h-7 rounded-full flex items-center justify-center text-sm font-medium transition-all"
          :class="ratingButtonClass(n)"
        >
          {{ n }}
        </button>
      </div>

      <!-- 状態ラベル（固定幅で揺れない） -->
      <span
        class="px-2 py-0.5 rounded-full text-xs w-16 text-center"
        :class="statusBadgeClass"
      >
        {{ statusLabel }}
      </span>

      <!-- 完了ボタン -->
      <button
        type="button"
        class="px-3 py-1 text-sm rounded border hover:bg-gray-50"
        @click="toggleStatus"
      >
        {{ isDone ? "未完了" : "完了" }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from "vue";

const props = defineProps({
  habit: { type: Object, required: true },
  log: { type: Object, required: true },
  today: { type: String, required: true },

  // ★追加：その habit（id）に対する「今日の回数」と「今日の完了数」
  // 例：歯磨き（朝/夜）なら total=2, done=0/1/2
  dailyTotal: { type: Number, default: null },
  dailyDone: { type: Number, default: null },
});

const emit = defineEmits(["update"]);

/**
 * ★同期の肝（そのまま維持）
 * - props.log を真実として参照しつつ、クリック直後だけ pending を上書き表示して“止血”する
 */
const pending = ref(null); // { status?, rating? } 一時上書き

const isSelf = computed(() => props.habit.evaluation_type === "self");

const viewStatus = computed(() => {
  return pending.value?.status ?? props.log.status ?? "none";
});

const viewRating = computed(() => {
  const r = pending.value?.rating ?? props.log.rating ?? 0;
  return typeof r === "number" ? r : 0;
});

// 行の done 真実（SELFは rating===4）
const isDone = computed(() => {
  if (isSelf.value) return viewRating.value === 4;
  return viewStatus.value === "done";
});

// ★ラベル：dailyTotal があればそれを出す（無ければ従来通り 1回）
const goalText = computed(() => {
  const t = Number(props.dailyTotal ?? 0);
  return t >= 1 ? `${t}回` : "1回";
});

/**
 * ★回数ベースの進捗（0/50/100…）
 * - props.dailyDone/props.dailyTotal を基本にする
 * - ただしクリック直後に bar も即反映させたいので、
 *   「この行のdone状態が pending で変わった差分」を done数に加算して一時補正する
 */
function rowDoneFromLog(log, habit) {
  const type = habit?.evaluation_type ?? "simple";
  const status = log?.status ?? "none";
  const rating = log?.rating ?? null;
  if (type === "self") return Number(rating ?? 0) === 4;
  return status === "done";
}

const dailyPct = computed(() => {
  const total = Number(props.dailyTotal ?? 0);

  // 集計が来てない場合はフォールバック（行単体 0/100）
  if (!total || total < 1) return isDone.value ? 100 : 0;

  const baseDone = Number(props.dailyDone ?? 0);

  // この行が “元々doneだったか” と “今の表示上doneか” の差分で補正
  const before = rowDoneFromLog(props.log, props.habit) ? 1 : 0;
  const after = isDone.value ? 1 : 0;
  let effectiveDone = baseDone + (after - before);

  // 安全に丸め
  if (effectiveDone < 0) effectiveDone = 0;
  if (effectiveDone > total) effectiveDone = total;

  return Math.round((effectiveDone / total) * 100);
});

/**
 * store が props.log を patch したら pending を解除
 */
watch(
  () => [props.log.status, props.log.rating, props.log.checked_at, props.log.id],
  () => {
    pending.value = null;
  }
);

/* ----------------------------------------------------------
   状態ラベル
---------------------------------------------------------- */
const statusLabel = computed(() => {
  if (!isSelf.value) {
    return isDone.value ? "完了" : "未完了";
  }

  if (viewRating.value === 4) return "完了";
  if (viewRating.value >= 1) return "進行中";
  return "未完了";
});

const statusBadgeClass = computed(() => {
  if (isDone.value) return "bg-green-100 text-green-700";
  if (isSelf.value && viewRating.value >= 1) return "bg-yellow-100 text-yellow-700";
  return "bg-blue-100 text-blue-600";
});

/* ----------------------------------------------------------
   emit に共通で付ける（複数回の識別）
---------------------------------------------------------- */
function baseMeta() {
  return {
    date: props.today,
    habit_time_id: props.habit?.habit_time_id ?? null,
    time_slot: props.habit?.time_slot ?? 0,
  };
}

/* ----------------------------------------------------------
   完了ボタン
---------------------------------------------------------- */
function toggleStatus() {
  if (isSelf.value) {
    const nextRating = viewRating.value === 4 ? 0 : 4;
    const nextStatus = nextRating === 4 ? "done" : "none";

    pending.value = { rating: nextRating, status: nextStatus };

    emit("update", {
      habit: props.habit,
      payload: {
        ...baseMeta(),
        status: nextStatus,
        rating: nextRating, // ★SELFは常に送る
      },
    });
    return;
  }

  // simple
  const nextStatus = isDone.value ? "none" : "done";
  pending.value = { status: nextStatus, rating: null };

  emit("update", {
    habit: props.habit,
    payload: {
      ...baseMeta(),
      status: nextStatus,
      rating: null,
    },
  });
}

/* ----------------------------------------------------------
   rating ボタン
---------------------------------------------------------- */
function onSelectRating(n) {
  const nextStatus = n === 4 ? "done" : "none";

  // ちらつき止血
  pending.value = { rating: n, status: nextStatus };

  emit("update", {
    habit: props.habit,
    payload: {
      ...baseMeta(),
      status: nextStatus,
      rating: n,
    },
  });
}

/* ----------------------------------------------------------
   rating ボタンの色
---------------------------------------------------------- */
function ratingButtonClass(n) {
  // “現在のrating” を基準に塗る（pendingも反映）
  if (n <= viewRating.value) return "bg-blue-500 text-white";
  return "bg-gray-200 text-gray-500";
}
</script>

<style scoped>
button {
  transition: 0.15s all ease;
}
</style>
