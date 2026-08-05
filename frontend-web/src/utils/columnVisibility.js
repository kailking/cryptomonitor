export function isColumnVisible(columns, key, fallback = false) {
  if (!Array.isArray(columns)) return fallback;
  const column = columns.find((item) => item && item.key === key);
  return column ? column.ispass === true : fallback;
}
