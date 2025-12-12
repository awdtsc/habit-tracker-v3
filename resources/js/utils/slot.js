// utils/slot.js

export const SLOT_ENUM = {
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

export function enumToSlotKey(enumValue) {
    return SLOT_KEY_MAP[enumValue] ?? null;
}

export function slotKeyToLabel(slotKey) {
    return SLOT_LABEL_MAP[slotKey] ?? "";
}

export function isSlotKey(value) {
    return ["morning", "day", "evening", "night"].includes(value);
}
