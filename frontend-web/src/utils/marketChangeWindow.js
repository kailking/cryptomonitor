export const MARKET_CHANGE_WINDOW_SECONDS = {
  SHORT: 30,
  LONG: 300,
};

export function normalizeMarketChangeWindowSeconds(value) {
  return Number(value) === MARKET_CHANGE_WINDOW_SECONDS.SHORT
    ? MARKET_CHANGE_WINDOW_SECONDS.SHORT
    : MARKET_CHANGE_WINDOW_SECONDS.LONG;
}

export function formatMarketChangeWindow(value) {
  const seconds = Number(value);
  if (seconds === MARKET_CHANGE_WINDOW_SECONDS.SHORT) return "30秒";
  if (seconds === MARKET_CHANGE_WINDOW_SECONDS.LONG) return "5分钟";
  return "--";
}

export function isMarketChangeWindowResponseValid(page, expectedSeconds) {
  if (!page || !Array.isArray(page.data)) return false;

  const expected = normalizeMarketChangeWindowSeconds(expectedSeconds);
  if (
    !Object.prototype.hasOwnProperty.call(page, "window_seconds") ||
    Number(page.window_seconds) !== expected
  ) {
    return false;
  }

  return page.data.every(
    (row) => row && Number(row.window_seconds) === expected
  );
}
