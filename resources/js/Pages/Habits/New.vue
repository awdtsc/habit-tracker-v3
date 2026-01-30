<!-- resources/js/Pages/Habits/New.vue -->
<template>
  <div class="p-4 md:p-6 max-w-2xl mx-auto space-y-6">
    <header class="space-y-1">
      <h1 class="text-2xl font-semibold">習慣を追加</h1>
      <p class="text-sm text-gray-500">
        朝/夜など複数スロットはチェックで指定できます。
      </p>
    </header>

    <div v-if="errorMessage" class="text-sm text-red-600">
      {{ errorMessage }}
    </div>
    <div v-if="successMessage" class="text-sm text-green-700">
      {{ successMessage }}
    </div>

    <form class="space-y-6" @submit.prevent="submit">
      <!-- title -->
      <div class="space-y-2">
        <label class="block text-sm font-medium">タイトル</label>
        <input
          v-model.trim="form.title"
          type="text"
          class="w-full rounded-xl border px-3 py-2"
          placeholder="例：歯磨き"
          required
          maxlength="255"
        />
      </div>

      <!-- evaluation type -->
      <div class="space-y-2">
        <label class="block text-sm font-medium">評価タイプ</label>
        <div class="flex gap-3">
          <label class="inline-flex items-center gap-2">
            <input type="radio" value="simple" v-model="form.evaluation_type" />
            <span class="text-sm">Simple（完了/未完了）</span>
          </label>
          <label class="inline-flex items-center gap-2">
            <input type="radio" value="self" v-model="form.evaluation_type" />
            <span class="text-sm">Self（自己評価 0-4）</span>
          </label>
        </div>
      </div>

      <!-- time slots -->
      <div class="space-y-2">
        <label class="block text-sm font-medium">時間帯</label>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
          <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-2">
            <input
              type="checkbox"
              :value="0"
              v-model="form.time_slots"
              @change="onToggleAnytime"
            />
            <span class="text-sm">いつでも</span>
          </label>

          <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-2">
            <input
              type="checkbox"
              :value="1"
              v-model="form.time_slots"
              @change="onToggleSlot"
            />
            <span class="text-sm">朝</span>
          </label>

          <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-2">
            <input
              type="checkbox"
              :value="2"
              v-model="form.time_slots"
              @change="onToggleSlot"
            />
            <span class="text-sm">昼</span>
          </label>

          <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-2">
            <input
              type="checkbox"
              :value="3"
              v-model="form.time_slots"
              @change="onToggleSlot"
            />
            <span class="text-sm">夕</span>
          </label>

          <label class="inline-flex items-center gap-2 rounded-xl border px-3 py-2">
            <input
              type="checkbox"
              :value="4"
              v-model="form.time_slots"
              @change="onToggleSlot"
            />
            <span class="text-sm">夜</span>
          </label>
        </div>

        <p class="text-xs text-gray-500">
          ※「いつでも」と他スロットは同時に選べません（どちらか一方）。
        </p>
      </div>

      <!-- description (optional) -->
      <div class="space-y-2">
        <label class="block text-sm font-medium">メモ（任意）</label>
        <textarea
          v-model="form.description"
          class="w-full rounded-xl border px-3 py-2"
          rows="3"
          placeholder="任意"
        />
      </div>

      <!-- actions -->
      <div class="flex items-center gap-3">
        <button
          type="submit"
          class="rounded-xl bg-black text-white px-4 py-2 disabled:opacity-60"
          :disabled="submitting"
        >
          {{ submitting ? "作成中…" : "作成" }}
        </button>

        <button
          type="button"
          class="rounded-xl border px-4 py-2"
          :disabled="submitting"
          @click="goBack"
        >
          キャンセル
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "@/axios";

const router = useRouter();

const submitting = ref(false);
const errorMessage = ref("");
const successMessage = ref("");

const form = reactive({
  title: "",
  description: "",
  frequency_type: "daily", // 今は固定でOK
  days_of_week: [],        // 今は未使用（将来）
  evaluation_type: "simple",
  time_slots: [0],         // デフォは「いつでも」
});

function normalizeSlotsUniqueSorted(slots) {
  const uniq = Array.from(new Set((slots ?? []).map((v) => Number(v))));
  uniq.sort((a, b) => a - b);
  return uniq;
}

// anytime を選んだら他スロットを外す
function onToggleAnytime() {
  const slots = normalizeSlotsUniqueSorted(form.time_slots);
  if (slots.includes(0)) {
    form.time_slots = [0];
  } else {
    // 何も選ばれてない状態を避ける
    if (slots.length === 0) form.time_slots = [0];
  }
}

// slot(1..4) を選んだら anytime を外す
function onToggleSlot() {
  let slots = normalizeSlotsUniqueSorted(form.time_slots);
  if (slots.includes(0) && slots.some((s) => s >= 1 && s <= 4)) {
    slots = slots.filter((s) => s !== 0);
  }
  if (slots.length === 0) slots = [0];
  form.time_slots = slots;
}

function goBack() {
  router.push({ name: "today" });
}

async function submit() {
  errorMessage.value = "";
  successMessage.value = "";

  const slots = normalizeSlotsUniqueSorted(form.time_slots);
  if (!slots.length) {
    errorMessage.value = "時間帯を選んでください。";
    return;
  }

  submitting.value = true;
  try {
    const payload = {
      title: form.title,
      description: form.description || null,
      frequency_type: form.frequency_type,
      days_of_week: form.days_of_week,
      evaluation_type: form.evaluation_type,
      time_slots: slots,
    };

    const res = await api.post("/habits", payload);

    successMessage.value = "作成しました。";
    // 今日へ戻る（Today側で再fetchされる想定）
    router.push({ name: "today" });
    return res.data;
  } catch (e) {
    // Laravel validation
    const msg =
      e?.response?.data?.message ||
      (e?.response?.data?.errors
        ? Object.values(e.response.data.errors).flat().join("\n")
        : null) ||
      "作成に失敗しました。";
    errorMessage.value = msg;
  } finally {
    submitting.value = false;
  }
}
</script>
