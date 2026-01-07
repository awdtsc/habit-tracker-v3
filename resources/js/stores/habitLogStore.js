// resources/js/stores/habitLogStore.js
import { reactive } from "vue";
import api from "@/axios";

/**
 * シングルトンストア（HabitLogだけ）
 * - logsByKey: `${date}:${habitTimeId}` -> logObject
 * - attach(ownerId, date, habitObj): habitObj.log を store の logObject 参照に差し替えて同期する
 * - patchLog(): ★参照差し替え（new object）で UI も追従させる
 * - toggle(): optimistic -> API -> confirmed / rollback（競合は requestVersion で捨てる）
 *
 * ★注意：
 * log の同一性は habit_time_id + date。
 *
 * ★2026-01-06:
 * - detachOwner時に「そのkeyを参照しているownerがゼロになったら」logsByKey/requestVersionも掃除する
 *   → attachの積み上がり/画面遷移の繰り返しでメモリが増え続けるのを抑える
 */

const state = reactive({
    logsByKey: new Map(), // key -> logObject
});

const requestVersion = new Map(); // key -> version

// key -> Map(ownerId -> Set<habitObj>)
const attached = new Map();
// ownerId -> Set<key>
const keysByOwner = new Map();

// 変更通知
const listeners = new Set();

/* ------------------------------
  helpers
------------------------------ */
function keyOf(date, habitTimeId) {
    return `${date}:${habitTimeId}`;
}

function getTodayJstISO() {
    // JSTを “加算” で作ると端末TZで壊れるので timeZone 指定で確定させる
    const fmt = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Tokyo",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
    return fmt.format(new Date()); // YYYY-MM-DD
}

function normalizeRatingMaybe(v) {
    if (v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
}

function ensureLogObject(key, initial = null) {
    if (!state.logsByKey.has(key)) {
        const initRating = Object.prototype.hasOwnProperty.call(
            initial ?? {},
            "rating"
        )
            ? normalizeRatingMaybe(initial?.rating)
            : null;

        state.logsByKey.set(key, {
            id: initial?.id ?? null,
            habit_id: initial?.habit_id ?? null,
            habit_time_id: initial?.habit_time_id ?? null,
            time_slot: Number(initial?.time_slot ?? 0),
            status: initial?.status ?? "none",
            rating: initRating,
            checked_at: initial?.checked_at ?? null,
        });
    }
    return state.logsByKey.get(key);
}

function emit(evt) {
    for (const fn of listeners) fn(evt);
}

function getAttachedHabitObjs(key) {
    const perKey = attached.get(key);
    if (!perKey) return [];

    const out = [];
    for (const set of perKey.values()) {
        for (const h of set) out.push(h);
    }
    return out;
}

function resolveHabitTimeId(habitObj) {
    return habitObj?.habit_time_id ?? habitObj?.log?.habit_time_id ?? null;
}

function isSelfEval(habitObj) {
    return (habitObj?.evaluation_type ?? "simple") === "self";
}

/* ------------------------------
  public API
------------------------------ */
function createOwner() {
    return Symbol("habitLogOwner");
}

/**
 * ownerを外す
 * ★重要: perKey.size===0（そのkeyを参照するownerがいなくなった）なら
 * logsByKey / requestVersion も掃除してメモリ増殖を抑える
 */
function detachOwner(ownerId) {
    const keys = keysByOwner.get(ownerId);
    if (!keys) return;

    for (const key of keys) {
        const perKey = attached.get(key);
        if (!perKey) continue;

        perKey.delete(ownerId);

        if (perKey.size === 0) {
            // このkeyを参照するownerがゼロになった
            attached.delete(key);

            // ★ここでログキャッシュも掃除（必要なら残す設計にもできるが、今は安全側）
            state.logsByKey.delete(key);
            requestVersion.delete(key);
        }
    }

    keysByOwner.delete(ownerId);
}

function attach(ownerId, date, habitObj) {
    const habitTimeId = resolveHabitTimeId(habitObj);
    if (!date || !habitTimeId) return;

    const key = keyOf(date, habitTimeId);

    if (!keysByOwner.has(ownerId)) keysByOwner.set(ownerId, new Set());
    keysByOwner.get(ownerId).add(key);

    if (!attached.has(key)) attached.set(key, new Map());
    const perKey = attached.get(key);
    if (!perKey.has(ownerId)) perKey.set(ownerId, new Set());
    perKey.get(ownerId).add(habitObj);

    const logRef = ensureLogObject(key, habitObj.log ?? null);
    habitObj.log = logRef;
}

function attachHabits(ownerId, date, habits) {
    (habits ?? []).forEach((h) => attach(ownerId, date, h));
}

function attachWeek(ownerId, days) {
    (days ?? []).forEach((d) => {
        attachHabits(ownerId, d.date, d.habits ?? []);
    });
}

function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/**
 * log patch（確定/optimistic共通）
 * ★重要: “参照差し替え” を行う
 */
function patchLog(date, habitTimeId, patch, meta = {}) {
    const key = keyOf(date, habitTimeId);
    const prev = ensureLogObject(key, patch);

    const hasRatingKey = Object.prototype.hasOwnProperty.call(
        patch ?? {},
        "rating"
    );
    const nextRating = hasRatingKey
        ? normalizeRatingMaybe(patch.rating)
        : prev.rating;

    const next = {
        ...prev,
        ...(patch ?? {}),
        habit_time_id: patch?.habit_time_id ?? prev.habit_time_id,
        // time_slot は属性。APIから返ってきた値があればそれを採用（nullならprev維持）
        time_slot: Object.prototype.hasOwnProperty.call(
            patch ?? {},
            "time_slot"
        )
            ? Number(patch.time_slot ?? 0)
            : prev.time_slot,
        rating: nextRating,
    };

    state.logsByKey.set(key, next);

    for (const habitObj of getAttachedHabitObjs(key)) {
        if (habitObj) habitObj.log = next;
    }

    emit({ type: "log:patched", date, habitTimeId, log: next, ...meta });
}

/**
 * トグル（唯一の更新窓口）
 */
async function toggle(date, habitObj, payload, scope = "all", meta = {}) {
    const d = date ?? getTodayJstISO();
    const habitId = habitObj.id;
    const habitTimeId = resolveHabitTimeId(habitObj);

    if (!habitTimeId) {
        throw new Error("habit_time_id is required for toggle");
    }

    const key = keyOf(d, habitTimeId);

    const v = (requestVersion.get(key) ?? 0) + 1;
    requestVersion.set(key, v);

    const beforeObj = state.logsByKey.get(key) ?? null;
    const before = beforeObj ? { ...beforeObj } : null;

    // --------
    // optimistic（ここでUIは即反映される）
    // SELF の場合、rating が無い payload でも status から 0/4 を補完して “一瞬未完” を防ぐ
    // --------
    const hasRatingInPayload = Object.prototype.hasOwnProperty.call(
        payload ?? {},
        "rating"
    );

    let optimisticRating;
    if (hasRatingInPayload) {
        optimisticRating = normalizeRatingMaybe(payload.rating);
    } else if (isSelfEval(habitObj)) {
        optimisticRating = payload?.status === "done" ? 4 : 0;
    } else {
        optimisticRating = null; // SIMPLEは常にnullでOK
    }

    patchLog(
        d,
        habitTimeId,
        {
            habit_id: habitId,
            habit_time_id: habitTimeId,
            // time_slot は属性として保持（habit_timeの現在slotを優先）
            time_slot: Number(habitObj?.time_slot ?? beforeObj?.time_slot ?? 0),
            status: payload.status,
            rating: optimisticRating,
            checked_at: new Date().toISOString(),
        },
        { phase: "optimistic", ...meta }
    );

    try {
        const { data } = await api.post(`/habits/${habitId}/toggle`, {
            ...payload,
            habit_time_id: habitTimeId,
            date: d,
            scope,
        });

        if (requestVersion.get(key) !== v) return data;

        // 互換: data.habit.log / data.log
        const log = data?.habit?.log ?? data?.log ?? null;

        if (log) {
            patchLog(
                d,
                habitTimeId,
                {
                    id: log.id,
                    habit_id: log.habit_id ?? habitId,
                    habit_time_id: log.habit_time_id ?? habitTimeId,
                    time_slot: Number(
                        log.time_slot ?? habitObj?.time_slot ?? 0
                    ),
                    status: log.status,
                    rating: log.rating,
                    checked_at: log.checked_at,
                },
                { phase: "confirmed", ...meta }
            );
        }

        return data;
    } catch (e) {
        // すでに新しいtoggleが走ってたら巻き戻さない
        if (requestVersion.get(key) !== v) return;

        // rollback
        if (before && Object.keys(before).length) {
            patchLog(d, habitTimeId, before, { phase: "rollback", ...meta });
        } else {
            patchLog(
                d,
                habitTimeId,
                { status: "none", rating: null },
                { phase: "rollback", ...meta }
            );
        }
        throw e;
    }
}

export function useHabitLogStore() {
    return {
        state,
        createOwner,
        detachOwner,
        attachHabits,
        attachWeek,
        subscribe,
        patchLog,
        toggle,
    };
}
