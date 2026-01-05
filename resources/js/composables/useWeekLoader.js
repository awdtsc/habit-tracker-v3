// resources/js/composables/useWeekLoader.js
//------------------------------------------------------------
// Week API ローダー（キャッシュ即描画 → silent revalidate）
//
// - preferCache: true ならキャッシュ即描画（体感を最優先）
// - revalidate : true なら描画後に裏で再取得して更新
// - silent     : true なら画面を消さず更新（refreshingフラグのみ）
//
// ★最適化：
// - 同じ weekStart の多重リクエストを dedupe
// - weekStart 未指定（現在週）は prefetchCurrentWeek を活用
//------------------------------------------------------------
import { ref } from "vue";
import api from "@/axios";
import {
    getCachedWeek,
    setCachedWeek,
    prefetchCurrentWeek,
} from "@/state/weekCache";
import { getWeekStartISO } from "@/utils/dateIso";

function isCanceled(e) {
    return (
        e?.name === "CanceledError" ||
        e?.code === "ERR_CANCELED" ||
        e?.name === "AbortError"
    );
}

function cloneWeek(obj) {
    if (!obj) return obj;
    // structuredClone があれば最優先（参照共有を確実に切る）
    if (typeof structuredClone === "function") {
        try {
            return structuredClone(obj);
        } catch {
            // fallthrough
        }
    }
    // 週データは基本 JSON で表現できる前提（Date等が無い）
    try {
        return JSON.parse(JSON.stringify(obj));
    } catch {
        return obj;
    }
}

export function useWeekLoader() {
    const week = ref({
        week_start: null,
        week_end: null,
        days: [],
        weekly_progress: { done: 0, total: 0, percent: 0 },
    });

    const loading = ref(false);
    const refreshing = ref(false);
    const hasLoaded = ref(false);
    const errorMessage = ref("");

    let controller = null;
    let reqNo = 0;

    // 同じ週の多重リクエストを潰す（weekStart -> Promise）
    const inflightByKey = new Map();

    /**
     * @param {string|null|undefined} weekStart "YYYY-MM-DD" or null
     * @param {object} opts { silent, preferCache, revalidate }
     */
    async function fetchWeek(weekStart, opts = {}) {
        const { silent = false, preferCache = true, revalidate = true } = opts;

        const key =
            typeof weekStart === "string" && weekStart ? weekStart : null;
        const currentWeekKey = getWeekStartISO();
        let usedPrefetch = false;

        // ------------------------------
        // 1) キャッシュ即描画（※cloneして汚染を防ぐ）
        // ------------------------------
        if (preferCache && key) {
            let cached = getCachedWeek(key);
            // 今日画面の事前fetchが飛んでいても cache miss になるので、現在週は prefetch を共有する
            if (!cached && key === currentWeekKey) {
                cached = await prefetchCurrentWeek();
                usedPrefetch = !!cached;
            }
            if (cached) {
                week.value = cloneWeek(cached);
                hasLoaded.value = true;
            }
            if (cached && (!revalidate || usedPrefetch)) return cached;
        }

        // ------------------------------
        // 2) dedupe（同じ週のリクエストは1本にまとめる）
        // ------------------------------
        // key=null（現在週）は weekCache 側の prefetch を使うので dedupe はそちら任せでOK
        if (key && inflightByKey.has(key)) {
            return inflightByKey.get(key);
        }

        // ------------------------------
        // 3) API 取得（silent 自動判定）
        // ------------------------------
        // 連打・競合対策：前のリクエストを中断
        if (controller) controller.abort();
        const localController = new AbortController();
        controller = localController;

        const myNo = ++reqNo;

        // ※hasLoaded/cached があるなら silent 扱い（画面は消さず refreshing のみ）
        const cachedNow = preferCache && key ? getCachedWeek(key) : null;
        const effectiveSilent = silent || hasLoaded.value || !!cachedNow;

        // フラグ更新は「最新リクエスト」だけが行う
        if (myNo === reqNo) {
            errorMessage.value = "";
            if (effectiveSilent) refreshing.value = true;
            else loading.value = true;
        }

        const runner = (async () => {
            try {
                // 現在週（keyなし）は weekCache 側の1本化を使う
                if (!key) {
                    const data = await prefetchCurrentWeek();
                    if (myNo !== reqNo) return null;
                    if (!data) throw new Error("prefetch failed");

                    week.value = cloneWeek(data);
                    hasLoaded.value = true;
                    // prefetchCurrentWeek 内で setCachedWeek 済み想定
                    return data;
                }

                const res = await api.get("/week", {
                    params: { week: key },
                    signal: localController.signal,
                });

                if (myNo !== reqNo) return null;

                week.value = cloneWeek(res.data);
                hasLoaded.value = true;

                // キャッシュも clone して保存（UI側ミューテーション汚染を防ぐ）
                setCachedWeek(cloneWeek(res.data));
                return res.data;
            } catch (e) {
                if (isCanceled(e)) return null;
                if (myNo !== reqNo) return null;

                errorMessage.value =
                    e?.response?.data?.message ||
                    e?.message ||
                    "週データの取得に失敗しました";
                return null;
            } finally {
                // ★ここが本丸：最新リクエストだけがフラグを戻す
                if (myNo !== reqNo) return;

                if (effectiveSilent) refreshing.value = false;
                else loading.value = false;
            }
        })();

        if (key) {
            inflightByKey.set(key, runner);
            try {
                const out = await runner;
                return out;
            } finally {
                inflightByKey.delete(key);
            }
        }

        return runner;
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
