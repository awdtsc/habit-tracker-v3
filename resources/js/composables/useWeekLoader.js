// resources/js/composables/useWeekLoader.js
//------------------------------------------------------------
// Week API ローダー（キャッシュ即描画 → silent revalidate）
//------------------------------------------------------------
import { ref } from "vue";
import api from "@/axios";
import { getCachedWeek, setCachedWeek } from "@/state/weekCache";

export function useWeekLoader() {
    const week = ref({
        week_start: null,
        week_end: null,
        days: [],
        weekly_progress: { done: 0, total: 0, percent: 0 },
    });

    const loading = ref(false); // 初回ロード用（画面が空のときだけ）
    const refreshing = ref(false); // 画面を消さない更新用（silent）
    const hasLoaded = ref(false);
    const errorMessage = ref("");

    let controller = null;
    let reqNo = 0;

    /**
     * @param {string|null|undefined} weekStart
     *   - "YYYY-MM-DD"（週の開始日）
     *   - null/undefined の場合は「現在週」をサーバーに任せる
     * @param {object} opts
     *   - silent: boolean（trueなら画面を消さず更新）
     *   - preferCache: boolean（trueならキャッシュがあれば先に表示）
     *   - revalidate: boolean（trueならキャッシュ表示後もAPIで再取得）
     */
    async function fetchWeek(weekStart, opts = {}) {
        const { silent = false, preferCache = true, revalidate = true } = opts;

        errorMessage.value = "";

        const key =
            typeof weekStart === "string" && weekStart ? weekStart : null;

        // ------------------------------
        // 1) キャッシュ即描画
        // ------------------------------
        const cached = preferCache && key ? getCachedWeek(key) : null;

        // 「初回切り替えが遅い」を潰すポイント：キャッシュがあれば即出す
        if (cached) {
            week.value = cached;
            hasLoaded.value = true;
        }

        // キャッシュだけで十分ならここで終了（API叩かない）
        if (cached && !revalidate) {
            return cached;
        }

        // ------------------------------
        // 2) APIで取得（silentを自動判定）
        // ------------------------------
        // 前のリクエストを中断（連打・競合対策）
        if (controller) controller.abort();
        controller = new AbortController();

        const myNo = ++reqNo;

        // 表示済み（キャッシュ or 過去ロード済み）なら強制 silent で体感を良くする
        const effectiveSilent = silent || hasLoaded.value || !!cached;

        if (effectiveSilent) refreshing.value = true;
        else loading.value = true;

        try {
            const res = await api.get("/week", {
                params: key ? { week_start: key } : undefined,
                signal: controller.signal,
            });

            if (myNo !== reqNo) return null;

            week.value = res.data;
            hasLoaded.value = true;

            // キャッシュ更新
            setCachedWeek(res.data);

            return res.data;
        } catch (e) {
            // Abort は無視
            if (e?.name === "CanceledError" || e?.code === "ERR_CANCELED") {
                return null;
            }

            // キャッシュが表示済みなら「致命」じゃないのでUIは維持しつつメッセージだけ
            errorMessage.value = "週データの取得に失敗しました";
            return null;
        } finally {
            if (effectiveSilent) refreshing.value = false;
            else loading.value = false;
        }
    }

    return {
        week,
        loading,
        refreshing,
        hasLoaded,
        errorMessage,
        fetchWeek,
    };
}
