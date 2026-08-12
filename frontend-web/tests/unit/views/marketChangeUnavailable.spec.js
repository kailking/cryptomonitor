import { createLatestRequestGuard } from "@/utils/latestRequest";

const mockGetMarketChange = jest.fn();

jest.mock("@/api/table", () => ({
  getMarketChange: mockGetMarketChange,
  getPlatformList: jest.fn(),
  getSymbolOption: jest.fn(),
}));

jest.mock("@/api/user", () => ({
  changeBlockId: jest.fn(),
  setCommonFilter: jest.fn(),
  getCommonFilter: jest.fn(),
  getInfo: jest.fn(),
}));

jest.mock("@/utils", () => ({
  copyText: jest.fn(),
  isMobile: jest.fn(() => false),
  parseNumber: jest.fn((value) => value),
}));

jest.mock("@/utils/platform", () => ({
  buildPlatformTradeUrl: jest.fn(),
}));

const Left = require("@/views/change/left.vue").default;
const Right = require("@/views/change/right.vue").default;

const pages = [
  { name: "left", component: Left },
  { name: "right", component: Right },
];

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

function context(component) {
  const vm = {
    ...component.data(),
    topicsRequestGuard: createLatestRequestGuard(),
  };
  Object.keys(component.methods).forEach((name) => {
    vm[name] = component.methods[name].bind(vm);
  });
  return vm;
}

describe.each(pages)("$name market change fail-closed state", ({ component }) => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers();
    localStorage.clear();
  });

  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  test("current request failure clears stale rows and stops auto refresh", async () => {
    const vm = context(component);
    vm.query.page = 3;
    vm.list = { data: [{ id: 9 }], current_page: 3, total: 1 };
    vm.refresh_button = 1;
    vm.intervalId = setInterval(jest.fn(), 1000);
    mockGetMarketChange.mockRejectedValueOnce(new Error("redis warming"));

    await vm.getTopics();

    expect(vm.list).toEqual({ data: [], current_page: 3, total: 0 });
    expect(vm.dataUnavailable).toBe(true);
    expect(vm.refresh_button).toBe(2);
    expect(vm.intervalId).toBeNull();
    expect(vm.loading).toBe(false);
  });

  test("successful manual retry clears the unavailable state", async () => {
    const vm = context(component);
    const page = { data: [{ id: 10 }], current_page: 1, total: 1 };
    vm.dataUnavailable = true;
    mockGetMarketChange.mockResolvedValueOnce({ data: page });

    await vm.retryTopics();

    expect(vm.list).toBe(page);
    expect(vm.dataUnavailable).toBe(false);
    expect(vm.loading).toBe(false);
  });

  test("an older failure cannot overwrite a newer success", async () => {
    const vm = context(component);
    const older = deferred();
    const newer = deferred();
    const page = { data: [{ id: 11 }], current_page: 1, total: 1 };
    vm.refresh_button = 1;
    mockGetMarketChange
      .mockReturnValueOnce(older.promise)
      .mockReturnValueOnce(newer.promise);

    const olderRequest = vm.getTopics();
    const newerRequest = vm.getTopics();
    newer.resolve({ data: page });
    await newerRequest;
    older.reject(new Error("late failure"));
    await olderRequest;

    expect(vm.list).toBe(page);
    expect(vm.dataUnavailable).toBe(false);
    expect(vm.refresh_button).toBe(1);
  });
});
