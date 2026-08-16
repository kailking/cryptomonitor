import { createLatestRequestGuard } from "@/utils/latestRequest";
import { shallowMount } from "@vue/test-utils";
import {
  formatMarketChangeWindow,
  isMarketChangeWindowResponseValid,
} from "@/utils/marketChangeWindow";

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

const List = require("@/views/change/list.vue").default;
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

function context(component, windowSeconds) {
  const vm = {
    ...component.data(),
    windowSeconds: windowSeconds == null ? 300 : windowSeconds,
    topicsRequestGuard: createLatestRequestGuard(),
  };
  Object.keys(component.methods).forEach((name) => {
    vm[name] = component.methods[name].bind(vm);
  });
  return vm;
}

describe("market change page window selector", () => {
  const LeftRankingStub = {
    name: "LeftRankingStub",
    props: ["windowSeconds"],
    render(createElement) {
      return createElement("div", { class: "left-ranking-stub" });
    },
  };
  const RightRankingStub = {
    name: "RightRankingStub",
    props: ["windowSeconds"],
    render(createElement) {
      return createElement("div", { class: "right-ranking-stub" });
    },
  };

  test("one parent selection changes both ranking props", async () => {
    const wrapper = shallowMount(List, {
      stubs: {
        Left: LeftRankingStub,
        Right: RightRankingStub,
        "el-radio-group": true,
        "el-radio-button": true,
      },
    });

    expect(wrapper.vm.windowSeconds).toBe(300);
    expect(wrapper.find(LeftRankingStub).props("windowSeconds")).toBe(300);
    expect(wrapper.find(RightRankingStub).props("windowSeconds")).toBe(300);

    wrapper.vm.handleWindowSecondsChange(30);
    await wrapper.vm.$nextTick();

    expect(wrapper.find(LeftRankingStub).props("windowSeconds")).toBe(30);
    expect(wrapper.find(RightRankingStub).props("windowSeconds")).toBe(30);
    wrapper.destroy();
  });

  test("normalizes selector input without changing refresh frequency", () => {
    const vm = { windowSeconds: 300, second: 5000 };

    List.methods.handleWindowSecondsChange.call(vm, 30);

    expect(vm.windowSeconds).toBe(30);
    expect(vm.second).toBe(5000);
  });
});

describe.each(pages)("$name market change window", ({ component }) => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers();
    localStorage.clear();
  });

  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  test("requests the default 300-second window", async () => {
    const vm = context(component, 300);
    const page = {
      window_seconds: 300,
      data: [],
      current_page: 1,
      total: 0,
    };
    mockGetMarketChange.mockResolvedValueOnce({ data: page });

    await vm.getTopics();

    expect(mockGetMarketChange).toHaveBeenCalledWith(
      expect.objectContaining({ window_seconds: 300 })
    );
    expect(vm.list).toBe(page);
  });

  test("switches to 30 seconds, resets page, and preserves filters", async () => {
    const vm = context(component, 30);
    vm.query.page = 7;
    vm.query.symbol = "BTC";
    vm.query.platform = [1, 3];
    vm.query.min_volume_24h_usdt = "100000";
    const page = {
      window_seconds: 30,
      data: [{ id: 1, window_seconds: 30 }],
      current_page: 1,
      total: 1,
    };
    mockGetMarketChange.mockResolvedValueOnce({ data: page });

    await vm.changeWindowSeconds(30);

    expect(vm.query).toEqual(
      expect.objectContaining({
        page: 1,
        symbol: "BTC",
        platform: [1, 3],
        min_volume_24h_usdt: "100000",
        window_seconds: 30,
      })
    );
    expect(mockGetMarketChange).toHaveBeenCalledWith(
      expect.objectContaining({ page: 1, window_seconds: 30 })
    );
    expect(vm.list).toBe(page);
  });

  test("an older five-minute response cannot overwrite 30-second data", async () => {
    const vm = context(component, 300);
    const older = deferred();
    const newer = deferred();
    const oldPage = {
      window_seconds: 300,
      data: [{ id: 300, window_seconds: 300 }],
      current_page: 2,
      total: 1,
    };
    const newPage = {
      window_seconds: 30,
      data: [{ id: 30, window_seconds: 30 }],
      current_page: 1,
      total: 1,
    };
    vm.query.page = 2;
    mockGetMarketChange
      .mockReturnValueOnce(older.promise)
      .mockReturnValueOnce(newer.promise);

    const olderRequest = vm.getTopics();
    vm.windowSeconds = 30;
    const newerRequest = vm.changeWindowSeconds(30);
    newer.resolve({ data: newPage });
    await newerRequest;
    older.resolve({ data: oldPage });
    await olderRequest;

    expect(mockGetMarketChange.mock.calls[0][0]).toEqual(
      expect.objectContaining({ page: 2, window_seconds: 300 })
    );
    expect(mockGetMarketChange.mock.calls[1][0]).toEqual(
      expect.objectContaining({ page: 1, window_seconds: 30 })
    );
    expect(vm.list).toBe(newPage);
  });

  test("fails closed when response rows belong to another window", async () => {
    const vm = context(component, 30);
    vm.query.window_seconds = 30;
    vm.list = {
      data: [{ id: 9, window_seconds: 300 }],
      current_page: 1,
      total: 1,
    };
    vm.refresh_button = 1;
    vm.intervalId = setInterval(jest.fn(), 1000);
    mockGetMarketChange.mockResolvedValueOnce({
      data: {
        window_seconds: 30,
        data: [{ id: 10, window_seconds: 300 }],
        current_page: 1,
        total: 1,
      },
    });

    await vm.getTopics();

    expect(vm.list).toEqual({ data: [], current_page: 1, total: 0 });
    expect(vm.dataUnavailable).toBe(true);
    expect(vm.refresh_button).toBe(2);
    expect(vm.intervalId).toBeNull();
  });
});

describe("market change window response helpers", () => {
  test("formats only supported response windows", () => {
    expect(formatMarketChangeWindow(30)).toBe("30秒");
    expect(formatMarketChangeWindow("300")).toBe("5分钟");
    expect(formatMarketChangeWindow(10)).toBe("--");
  });

  test("rejects missing, mixed, and top-level mismatched windows", () => {
    expect(
      isMarketChangeWindowResponseValid(
        {
          window_seconds: 30,
          data: [{ window_seconds: 30 }, { window_seconds: "30" }],
        },
        30
      )
    ).toBe(true);
    expect(
      isMarketChangeWindowResponseValid({ data: [{ id: 1 }] }, 30)
    ).toBe(false);
    expect(
      isMarketChangeWindowResponseValid(
        { window_seconds: 300, data: [{ window_seconds: 30 }] },
        30
      )
    ).toBe(false);
  });

  test("requires an exact top-level window even for empty pages", () => {
    expect(isMarketChangeWindowResponseValid({ data: [] }, 30)).toBe(false);
    expect(
      isMarketChangeWindowResponseValid(
        { window_seconds: 300, data: [] },
        30
      )
    ).toBe(false);
    expect(
      isMarketChangeWindowResponseValid(
        { window_seconds: 30, data: [] },
        30
      )
    ).toBe(true);
  });
});
