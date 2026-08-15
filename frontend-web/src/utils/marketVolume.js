import { covertime } from "@/utils/index";

export const MARKET_VOLUME_MAX_AGE_SECONDS = 30 * 60;
export const MARKET_VOLUME_FUTURE_TOLERANCE_SECONDS = 5 * 60;
export const MARKET_VOLUME_EMPTY_TEXT = "--";
export const MARKET_VOLUME_QUICK_OPTIONS = [
  { label: "0", value: "" },
  { label: "10万", value: "100000" },
  { label: "50万", value: "500000" },
  { label: "100万", value: "1000000" },
  { label: "300万", value: "3000000" },
];

const DECIMAL_PATTERN = /^\d+(?:\.\d+)?$/;

function decimalText(value) {
  if (typeof value === "number") {
    return Number.isFinite(value) && value >= 0 ? String(value) : "";
  }
  if (typeof value !== "string") return "";
  const normalized = value.trim();
  return DECIMAL_PATTERN.test(normalized) ? normalized : "";
}

function timestampMillis(value) {
  if (typeof value === "string" && !/^\d+$/.test(value.trim())) return NaN;
  const timestamp = Number(value);
  return Number.isSafeInteger(timestamp) && timestamp > 0 ? timestamp : NaN;
}

export function normalizeMinVolumeFilter(value) {
  const normalized = decimalText(value);
  if (!normalized) return "";
  return /[1-9]/.test(normalized) ? normalized : "";
}

export function getMarketVolumeFilterPayload(query) {
  return {
    min_volume_24h_usdt: normalizeMinVolumeFilter(
      query && query.min_volume_24h_usdt
    ),
  };
}

export function restoreMarketVolumeFilter(query, savedFilter) {
  if (!query || typeof query !== "object") return query;
  query.min_volume_24h_usdt = normalizeMinVolumeFilter(
    savedFilter && savedFilter.min_volume_24h_usdt
  );
  return query;
}

export function isMarketVolumeFresh(
  row,
  valueKey,
  timestampKey,
  now = Date.now(),
  maxAgeSeconds = MARKET_VOLUME_MAX_AGE_SECONDS
) {
  if (!row || row.volume_available !== true) return false;
  if (!decimalText(row[valueKey])) return false;

  const timestamp = timestampMillis(row[timestampKey]);
  if (!Number.isFinite(timestamp)) return false;

  const maxAge = Number(maxAgeSeconds) * 1000;
  if (!Number.isFinite(maxAge) || maxAge <= 0) return false;
  return (
    timestamp <= now + MARKET_VOLUME_FUTURE_TOLERANCE_SECONDS * 1000 &&
    now - timestamp < maxAge
  );
}

export function formatCompactMarketVolume(value) {
  const normalized = decimalText(value);
  if (!normalized) return MARKET_VOLUME_EMPTY_TEXT;

  const amount = Number(normalized);
  if (!Number.isFinite(amount)) return normalized;

  const units = [
    { value: 1e15, suffix: "Q" },
    { value: 1e12, suffix: "T" },
    { value: 1e9, suffix: "B" },
    { value: 1e6, suffix: "M" },
    { value: 1e3, suffix: "K" },
  ];
  const unit = units.find((item) => amount >= item.value);
  const scaled = unit ? amount / unit.value : amount;
  const precision = scaled >= 100 ? 0 : scaled >= 10 ? 1 : 2;
  const text = scaled
    .toFixed(precision)
    .replace(/(\.\d*?[1-9])0+$/, "$1")
    .replace(/\.0+$/, "");
  return `${text}${unit ? unit.suffix : ""}`;
}

export function getMarketVolumeDisplay(
  row,
  valueKey,
  timestampKey,
  now = Date.now()
) {
  if (!isMarketVolumeFresh(row, valueKey, timestampKey, now)) {
    return MARKET_VOLUME_EMPTY_TEXT;
  }
  return formatCompactMarketVolume(row[valueKey]);
}

export function getMarketVolumeExact(
  row,
  valueKey,
  timestampKey,
  now = Date.now()
) {
  if (!isMarketVolumeFresh(row, valueKey, timestampKey, now)) return "";
  return `${decimalText(row[valueKey])} USDT`;
}

export function getMarketVolumeTimeDisplay(
  row,
  valueKey,
  timestampKey,
  now = Date.now()
) {
  if (!isMarketVolumeFresh(row, valueKey, timestampKey, now)) {
    return MARKET_VOLUME_EMPTY_TEXT;
  }
  return covertime(timestampMillis(row[timestampKey]));
}
