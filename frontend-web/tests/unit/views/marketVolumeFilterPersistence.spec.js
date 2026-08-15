const mockSetFilter = jest.fn(() => Promise.resolve({ code: 200 }));
const mockSetCommonFilter = jest.fn(() => Promise.resolve({ code: 200 }));

jest.mock("@/api/table", () => ({
  getQuotationPrice: jest.fn(),
  getQuotationPricePlus: jest.fn(),
  getPlatformList: jest.fn(),
  getSymbolOption: jest.fn(),
  getWithdrawInfo: jest.fn(),
  refreshPlatformAddress: jest.fn(),
  configPlatformAddress: jest.fn(),
  postCollect: jest.fn(),
  postRemark: jest.fn(),
  getMarketChange: jest.fn(),
}));

jest.mock("@/api/user", () => ({
  setFilter: mockSetFilter,
  getFilter: jest.fn(),
  setPlatformFilter: jest.fn(),
  getPlatformFilter: jest.fn(),
  setCommonFilter: mockSetCommonFilter,
  getCommonFilter: jest.fn(),
  blockId: jest.fn(),
  changeBlockId: jest.fn(),
  getInfo: jest.fn(),
}));

const ChangeLeft = require("@/views/change/left.vue").default;
const ChangeRight = require("@/views/change/right.vue").default;

window.matchMedia = jest.fn(() => ({ matches: false }));

describe.each([
  ["change_left", ChangeLeft, "change_left_filter"],
  ["change_right", ChangeRight, "change_right_filter"],
])("%s volume filter persistence", (name, component, key) => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.clear();
  });

  test("labels the threshold as 24h USDT turnover", () => {
    expect(component.render.toString()).toContain("24h成交额大于多少U");
  });

  test("offers the requested quick USDT turnover thresholds", () => {
    const vm = component.data();

    expect(vm.volumeQuickOptions).toEqual([
      { label: "0", value: "" },
      { label: "10万", value: "100000" },
      { label: "50万", value: "500000" },
      { label: "100万", value: "1000000" },
      { label: "300万", value: "3000000" },
    ]);
  });

  test.each([
    ["0", ""],
    ["10万", "100000"],
    ["50万", "500000"],
    ["100万", "1000000"],
    ["300万", "3000000"],
  ])("applies the %s quick threshold", (label, value) => {
    const vm = {
      query: { min_volume_24h_usdt: "750000" },
      handleFilterSave: jest.fn(),
    };

    component.methods.applyVolumeQuickFilter.call(vm, value);

    expect(vm.query.min_volume_24h_usdt).toBe(value);
    expect(vm.handleFilterSave).toHaveBeenCalledTimes(1);
  });

  test("highlights only the active quick threshold", () => {
    const vm = { query: { min_volume_24h_usdt: "" } };

    expect(component.methods.isVolumeQuickFilterActive.call(vm, "")).toBe(
      true
    );
    expect(
      component.methods.isVolumeQuickFilterActive.call(vm, "100000")
    ).toBe(false);

    vm.query.min_volume_24h_usdt = "100000";
    expect(
      component.methods.isVolumeQuickFilterActive.call(vm, "100000")
    ).toBe(true);

    vm.query.min_volume_24h_usdt = "750000";
    expect(
      component.methods.isVolumeQuickFilterActive.call(vm, "500000")
    ).toBe(false);
  });

  test("sends the exact threshold through common filter storage", async () => {
    const vm = component.data();
    vm.query.min_volume_24h_usdt = "750000.125";

    await component.methods.saveFilter.call(vm);

    expect(mockSetCommonFilter).toHaveBeenCalledWith({
      key,
      object: {
        change: vm.query.change,
        second: vm.second,
        refresh_button: vm.refresh_button,
        min_volume_24h_usdt: "750000.125",
      },
    });
  });

  test("normalizes a zero threshold before applying the filter", () => {
    const vm = {
      query: { page: 5, min_volume_24h_usdt: "0.000" },
      getTopics: jest.fn(),
      saveFilter: jest.fn(),
    };

    component.methods.handleFilterSave.call(vm);

    expect(vm.query.min_volume_24h_usdt).toBe("");
    expect(vm.query.page).toBe(1);
    expect(vm.getTopics).toHaveBeenCalledTimes(1);
    expect(vm.saveFilter).toHaveBeenCalledTimes(1);
  });
});
