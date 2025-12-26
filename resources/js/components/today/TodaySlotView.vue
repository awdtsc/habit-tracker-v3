<!-- resources/js/components/today/TodaySlotView.vue -->
<template>
  <div class="space-y-10">
    <!-- 1) この時間帯（未完） -->
    <section class="space-y-3">
      <h2 class="text-lg font-semibold">{{ slotLabel }} の習慣</h2>

      <TodayActionableSection
        :habits="currentPending"
        :today="today"
        @update="emitUpdate"
      />

      <p v-if="currentPending.length === 0" class="text-sm text-gray-500 pl-1">
        この時間帯の未完了の習慣はありません。
      </p>
    </section>

    <!-- 2) この時間帯（完了） -->
    <section v-if="currentDone.length > 0" class="space-y-3">
      <h3 class="text-md font-semibold text-gray-700">
        {{ slotLabel }} の完了
      </h3>

      <TodayDoneSection
        :habits="currentDone"
        :today="today"
        @update="emitUpdate"
      />
    </section>

    <!-- 3) 次の習慣（= “今の時間帯(nowSlot)” の次スロット未完了） -->
    <!-- ★重要：今の時間帯タブを見ている時だけ出す（夕タブ先読みで夜を出さない） -->
    <section v-if="shouldShowNext && nextPending.length > 0" class="space-y-3">
      <h3 class="text-md font-semibold text-gray-700">
        次の習慣（{{ nextSlotLabel }}）
      </h3>

      <TodayActionableSection
        :habits="nextPending"
        :today="today"
        @update="emitUpdate"
      />
    </section>

    <!-- 4) いつでも（未完） -->
    <section class="space-y-3">
      <h3 class="text-md font-semibold text-gray-700">いつでも の習慣</h3>

      <TodayActionableSection
        :habits="anytimePending"
        :today="today"
        @update="emitUpdate"
      />

      <p v-if="anytimePending.length === 0" class="text-sm text-gray-500 pl-1">
        未完了の習慣はありません。
      </p>
    </section>

    <!-- 5) いつでも（完了） -->
    <section v-if="anytimeDone.length > 0" class="space-y-3">
      <h3 class="text-md font-semibold text-gray-700">いつでも の完了</h3>

      <TodayDoneSection
        :habits="anytimeDone"
        :today="today"
        @update="emitUpdate"
      />
    </section>
  </div>
</template>

<script setup>
import { computed } from "vue";
import TodayActionableSection from "@/components/today/TodayActionableSection.vue";
import TodayDoneSection from "@/components/today/TodayDoneSection.vue";

const props = defineProps({
  slot: { type: String, required: true }, // "morning" | "day" | "evening" | "night"
  today: { type: String, required: true },
  habits: { type: Array, default: () => [] },

  // nowSlot は “今の時間帯” の真実（API由来）
  nowSlot: { type: [Number, String, null], default: null },

  // 互換：受け取るがこのファイルでは使わない
  nextSlot: { type: [Number, String, null], default: null },
  showNext: { type: Boolean, default: true }, // 親から来る想定に合わせて受けるだけ
});

const emit = defineEmits(["update"]);
function emitUpdate(payload) {
  emit("update", payload);
}

/**
 * 完了判定（TodayTab と一致）
 */
function isDone(h) {
  const type = h?.evaluation_type ?? "simple";
  const status = h?.log?.status ?? "none";
  const rating = h?.log?.rating ?? null;

  if (type === "self") return Number(rating ?? 0) === 4;
  return status === "done";
}

function slotKeyToNumber(key) {
  switch (key) {
    case "morning":
      return 1;
    case "day":
      return 2;
    case "evening":
      return 3;
    case "night":
      return 4;
    default:
      return 0;
  }
}

function numberToSlotLabel(n) {
  switch (Number(n)) {
    case 1:
      return "朝";
    case 2:
      return "昼";
    case 3:
      return "夕";
    case 4:
      return "夜";
    default:
      return "";
  }
}

const slotNumber = computed(() => slotKeyToNumber(props.slot));
const slotLabel = computed(() => numberToSlotLabel(slotNumber.value));

const list = computed(() => (Array.isArray(props.habits) ? props.habits : []));

// 未完/完了を幹で分ける
const pending = computed(() => list.value.filter((h) => !isDone(h)));
const done = computed(() => list.value.filter((h) => isDone(h)));

// 現在タブの表示（そのタブの time_slot のみ）
const currentPending = computed(() =>
  pending.value.filter((h) => Number(h.time_slot ?? 0) === slotNumber.value)
);
const currentDone = computed(() =>
  done.value.filter((h) => Number(h.time_slot ?? 0) === slotNumber.value)
);

/* ============================
   ★次の習慣は “今の時間帯(nowSlot)” 基準
   - ただし「今の時間帯タブ」を見ている時だけ出す
   ============================ */
const nowSlotNumber = computed(() => {
  const n = Number(props.nowSlot ?? 0);
  return n >= 1 && n <= 4 ? n : null;
});

const shouldShowNext = computed(() => {
  if (!props.showNext) return false;
  if (!nowSlotNumber.value) return false;

  // 「今が昼」なら 昼タブの時だけ “次(夕)” を出す
  return slotNumber.value === nowSlotNumber.value;
});

const nextSlotNumber = computed(() => {
  if (!shouldShowNext.value) return null;
  const cur = nowSlotNumber.value;
  if (!cur || cur >= 4) return null; // 夜は次なし
  return cur + 1;
});

const nextSlotLabel = computed(() => {
  if (!nextSlotNumber.value) return "";
  return numberToSlotLabel(nextSlotNumber.value);
});

const nextPending = computed(() => {
  if (!nextSlotNumber.value) return [];
  return pending.value.filter(
    (h) => Number(h.time_slot ?? 0) === Number(nextSlotNumber.value)
  );
});

// いつでも
const anytimePending = computed(() =>
  pending.value.filter((h) => Number(h.time_slot ?? 0) === 0)
);
const anytimeDone = computed(() =>
  done.value.filter((h) => Number(h.time_slot ?? 0) === 0)
);
</script>
