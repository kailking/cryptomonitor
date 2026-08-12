const CURRENT_NAMESPACE = "unified";
const LEGACY_NAMESPACES = ["web", "web89"];

function parseWidth(value) {
  if (typeof value !== "string" || !/^\d+$/.test(value)) return null;
  const width = Number.parseInt(value, 10);
  return Number.isFinite(width) ? width : null;
}

export function buildTableWidthKey(page, side, prop) {
  return `crypto-monitor:${CURRENT_NAMESPACE}:${page}:${side}:width:${prop}`;
}

export function readTableWidth(storage, page, side, prop, fallback) {
  const currentKey = buildTableWidthKey(page, side, prop);

  try {
    const current = parseWidth(storage.getItem(currentKey));
    if (current !== null) return current;

    for (const legacy of LEGACY_NAMESPACES) {
      const legacyKey = `crypto-monitor:${legacy}:${page}:${side}:width:${prop}`;
      const value = parseWidth(storage.getItem(legacyKey));
      if (value !== null) {
        try {
          storage.setItem(currentKey, String(value));
        } catch (error) {
          // The migrated read remains usable when browser storage is read-only.
        }
        return value;
      }
    }
  } catch (error) {
    return fallback;
  }

  return fallback;
}

export function readJson(storage, key, fallback) {
  const value = storage.getItem(key);
  if (value === null) return fallback;
  try {
    return JSON.parse(value);
  } catch (error) {
    return fallback;
  }
}
