// resources/js/composables/useTodayLoader.js
//------------------------------------------------------------
// Today API を一括ロードし、UI に必要な状態だけ expose する
// ★ タブ切替では再fetchしない（遅延の根本原因を潰す）
//------------------------------------------------------------

import { ref } from "vue";
import api from "@/axios";

export function useTodayLoader() {
    const loading = ref(false);
    const errorMessage = ref("");

    const today = ref("");
    const nowSlot = ref(null);
    const nextSlot = ref(null); // ★ 追加
    const habits = ref([]);

    const topPick = ref(null);

    async function fetchToday() {
        loading.value = true;
        errorMessage.value = "";

        try {
            const { data } = await api.get("/today");
            today.value = data.today ?? "";
            nowSlot.value = data.now_slot ?? null;
            nextSlot.value = data.next_slot ?? null; // ★ 追加
            habits.value = data.habits ?? [];
            topPick.value = data.top_pick ?? null;
        } catch (e) {
            console.error("[useTodayLoader] Today fetch failed", e);
            errorMessage.value = "今日の習慣を取得できませんでした。";
        } finally {
            loading.value = false;
        }
    }

    async function refresh() {
        await fetchToday();
    }

    fetchToday();

    return {
        loading,
        errorMessage,
        today,
        nowSlot,
        nextSlot, // ★ 追加
        habits,
        topPick,
        refresh,
    };
}
