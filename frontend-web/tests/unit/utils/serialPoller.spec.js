import { createSerialPoller } from "@/utils/serialPoller";

function createDeferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

async function flushMicrotasks() {
  await Promise.resolve();
  await Promise.resolve();
}

describe("createSerialPoller", () => {
  beforeEach(() => {
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  test("rejects invalid callbacks and delays before scheduling work", () => {
    expect(() => createSerialPoller(null)).toThrow(TypeError);
    expect(() => createSerialPoller(jest.fn(), -1)).toThrow(TypeError);
    expect(() => createSerialPoller(jest.fn(), Infinity)).toThrow(TypeError);
  });

  test("runs immediately and schedules the next round only after completion", async() => {
    const first = createDeferred();
    const callback = jest
      .fn()
      .mockImplementationOnce(() => first.promise)
      .mockResolvedValue(undefined);
    const poller = createSerialPoller(callback, 5000);

    const initialRun = poller.start();

    expect(callback).toHaveBeenCalledTimes(1);
    expect(poller.isRunning()).toBe(true);

    jest.advanceTimersByTime(15000);
    expect(callback).toHaveBeenCalledTimes(1);

    first.resolve();
    await initialRun;
    await flushMicrotasks();
    expect(poller.isRunning()).toBe(false);

    jest.advanceTimersByTime(4999);
    expect(callback).toHaveBeenCalledTimes(1);

    jest.advanceTimersByTime(1);
    await flushMicrotasks();
    expect(callback).toHaveBeenCalledTimes(2);

    poller.stop();
  });

  test("coalesces refresh requests made during an in-flight round", async() => {
    const first = createDeferred();
    const callback = jest
      .fn()
      .mockImplementationOnce(() => first.promise)
      .mockResolvedValue(undefined);
    const poller = createSerialPoller(callback, 5000);

    const initialRun = poller.start();
    await expect(poller.refresh()).resolves.toBe(false);
    await expect(poller.refresh()).resolves.toBe(false);
    expect(callback).toHaveBeenCalledTimes(1);

    first.resolve();
    await initialRun;
    await flushMicrotasks();

    expect(callback).toHaveBeenCalledTimes(2);
    poller.stop();
  });

  test("stop cancels a scheduled round and prevents an in-flight round from rescheduling", async() => {
    const deferred = createDeferred();
    const callback = jest.fn(() => deferred.promise);
    const poller = createSerialPoller(callback, 5000);

    const initialRun = poller.start();
    poller.stop();
    expect(poller.isStopped()).toBe(true);

    deferred.resolve();
    await initialRun;
    await flushMicrotasks();
    jest.advanceTimersByTime(10000);

    expect(callback).toHaveBeenCalledTimes(1);
    await expect(poller.refresh()).resolves.toBe(false);
  });

  test("continues polling after a transient callback rejection", async() => {
    const callback = jest
      .fn()
      .mockRejectedValueOnce(new Error("temporary failure"))
      .mockResolvedValue(undefined);
    const poller = createSerialPoller(callback, 5000);

    await expect(poller.start()).resolves.toBe(true);
    jest.advanceTimersByTime(5000);
    await flushMicrotasks();

    expect(callback).toHaveBeenCalledTimes(2);
    poller.stop();
  });
});
