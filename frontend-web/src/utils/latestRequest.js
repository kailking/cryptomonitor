export function createLatestRequestGuard() {
  let version = 0;
  return {
    begin() {
      version += 1;
      return version;
    },
    isCurrent(token) {
      return token === version;
    },
    invalidate() {
      version += 1;
    },
  };
}
