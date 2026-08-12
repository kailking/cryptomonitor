import { restartInterval, stopInterval } from "@/utils/interval";

describe("interval helpers", () => {
  beforeEach(() => jest.useFakeTimers());
  afterEach(() => jest.useRealTimers());

  it("clears the previous timer before starting a new interval", () => {
    const callback = jest.fn();
    const first = restartInterval(null, callback, 1000);
    const second = restartInterval(first, callback, 3000);

    jest.advanceTimersByTime(3000);
    expect(callback).toHaveBeenCalledTimes(1);
    expect(second).not.toBe(first);
  });

  it("returns null after stopping", () => {
    const timer = restartInterval(null, jest.fn(), 1000);
    expect(stopInterval(timer)).toBeNull();
  });

  it("clears without restarting a disposed interval", () => {
    const callback = jest.fn();
    const timer = restartInterval(null, callback, 1000);
    const setIntervalSpy = jest.spyOn(global, "setInterval");
    setIntervalSpy.mockClear();

    const result = restartInterval(timer, callback, 1000, true);

    expect(result).toBeNull();
    expect(setIntervalSpy).not.toHaveBeenCalled();
    jest.advanceTimersByTime(1000);
    expect(callback).not.toHaveBeenCalled();
    setIntervalSpy.mockRestore();
  });
});
