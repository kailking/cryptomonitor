export function stopInterval(intervalId) {
  if (intervalId !== null && intervalId !== undefined) {
    clearInterval(intervalId);
  }
  return null;
}

export function restartInterval(
  intervalId,
  callback,
  delay,
  isDisposed = false
) {
  stopInterval(intervalId);
  if (isDisposed) return null;
  return setInterval(callback, delay);
}
