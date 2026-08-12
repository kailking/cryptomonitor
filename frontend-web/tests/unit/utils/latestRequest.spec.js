import { createLatestRequestGuard } from "@/utils/latestRequest";

describe("createLatestRequestGuard", () => {
  it("accepts only the newest request token", () => {
    const guard = createLatestRequestGuard();
    const first = guard.begin();
    const second = guard.begin();

    expect(guard.isCurrent(first)).toBe(false);
    expect(guard.isCurrent(second)).toBe(true);
  });

  it("invalidates in-flight work during component teardown", () => {
    const guard = createLatestRequestGuard();
    const token = guard.begin();
    guard.invalidate();
    expect(guard.isCurrent(token)).toBe(false);
  });
});
