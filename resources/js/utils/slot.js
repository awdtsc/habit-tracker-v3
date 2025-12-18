// resources/js/utils/slot.js

export const SLOT_ENUM = {
    ANYTIME: 0,
    MORNING: 1,
    DAY: 2,
    EVENING: 3,
    NIGHT: 4,
};

export const SLOT_KEY_MAP = {
    1: "morning",
    2: "day",
    3: "evening",
    4: "night",
};

export const SLOT_LABEL_MAP = {
    morning: "朝",
    day: "昼",
    evening: "夕",
    night: "夜",
};

const KEY_TO_ENUM = {
    morning: SLOT_ENUM.MORNING,
    day: SLOT_ENUM.DAY,
    evening: SLOT_ENUM.EVENING,
    night: SLOT_ENUM.NIGHT,
};

export function enumToSlotKey(enumValue) {
    return SLOT_KEY_MAP[enumValue] ?? null;
}

export function slotKeyToEnum(slotKey) {
    return KEY_TO_ENUM[slotKey] ?? null;
}

export function slotKeyToLabel(slotKey) {
    return SLOT_LABEL_MAP[slotKey] ?? "";
}

export function isSlotKey(value) {
    return ["morning", "day", "evening", "night"].includes(value);
}

/**
 * "morning/day/evening/night" の次スロット(enum)を返す
 * night の次は null
 */
export function nextSlotEnumByKey(slotKey) {
    const cur = slotKeyToEnum(slotKey);
    if (cur == null) return null;
    const next = cur + 1;
    return next > SLOT_ENUM.NIGHT ? null : next;
}

/**
 * progress計算などで使う: scope("morning/day/evening/night/all") → slot(enum)
 * all は null を返す（= slotで絞らない）
 */
export function scopeToSlotEnum(scope) {
    if (!scope) return null;
    if (scope === "all") return null;
    return slotKeyToEnum(scope) ?? null;
}
