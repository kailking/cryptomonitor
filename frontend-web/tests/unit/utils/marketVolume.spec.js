import { shallowMount } from "@vue/test-utils";
import MarketVolumeCell from "@/components/MarketVolumeCell";
import {
  MARKET_VOLUME_EMPTY_TEXT,
  formatCompactMarketVolume,
  getMarketVolumeDisplay,
  getMarketVolumeExact,
  getMarketVolumeFilterPayload,
  getMarketVolumeTimeDisplay,
  restoreMarketVolumeFilter,
} from "@/utils/marketVolume";
import { covertime } from "@/utils/index";

const NOW = 1786723200000;

function volumeRow(overrides = {}) {
  return Object.assign(
    {
      volume_available: true,
      volume_24h_usdt: "1500000.125",
      volume_updated_at_ms: NOW - 15 * 60 * 1000,
    },
    overrides
  );
}

describe("market volume filter persistence", () => {
  test("keeps an exact decimal threshold through save and restore", () => {
    const saved = getMarketVolumeFilterPayload({
      min_volume_24h_usdt: " 2500000.5000 ",
    });
    const query = { min_volume_24h_usdt: "" };

    restoreMarketVolumeFilter(query, saved);

    expect(saved).toEqual({ min_volume_24h_usdt: "2500000.5000" });
    expect(query.min_volume_24h_usdt).toBe("2500000.5000");
  });

  test("invalid or missing saved thresholds restore as disabled", () => {
    expect(getMarketVolumeFilterPayload({ min_volume_24h_usdt: "1e6" })).toEqual(
      { min_volume_24h_usdt: "" }
    );
    expect(getMarketVolumeFilterPayload({ min_volume_24h_usdt: "0.000" })).toEqual(
      { min_volume_24h_usdt: "" }
    );
    expect(restoreMarketVolumeFilter({}, null)).toEqual({
      min_volume_24h_usdt: "",
    });
  });
});

describe("market volume display", () => {
  test("shows a readable amount, exact tooltip value and independent time", () => {
    const row = volumeRow();

    expect(
      getMarketVolumeDisplay(
        row,
        "volume_24h_usdt",
        "volume_updated_at_ms",
        NOW
      )
    ).toBe("1.5M");
    expect(
      getMarketVolumeExact(
        row,
        "volume_24h_usdt",
        "volume_updated_at_ms",
        NOW
      )
    ).toBe("1500000.125 USDT");
    expect(
      getMarketVolumeTimeDisplay(
        row,
        "volume_24h_usdt",
        "volume_updated_at_ms",
        NOW
      )
    ).toBe(covertime(row.volume_updated_at_ms));
  });

  test.each([
    ["explicitly unavailable", { volume_available: false }],
    ["null amount", { volume_24h_usdt: null }],
    ["missing timestamp", { volume_updated_at_ms: undefined }],
    ["stale timestamp", { volume_updated_at_ms: NOW - 1800001 }],
    ["far-future timestamp", { volume_updated_at_ms: NOW + 300001 }],
  ])("renders -- when %s", (description, overrides) => {
    const row = volumeRow(overrides);

    expect(
      getMarketVolumeDisplay(
        row,
        "volume_24h_usdt",
        "volume_updated_at_ms",
        NOW
      )
    ).toBe(MARKET_VOLUME_EMPTY_TEXT);
    expect(
      getMarketVolumeTimeDisplay(
        row,
        "volume_24h_usdt",
        "volume_updated_at_ms",
        NOW
      )
    ).toBe(MARKET_VOLUME_EMPTY_TEXT);
  });

  test("formats the supported compact units without changing exact values", () => {
    expect(formatCompactMarketVolume("999.25")).toBe("999");
    expect(formatCompactMarketVolume("12500")).toBe("12.5K");
    expect(formatCompactMarketVolume("3000000000")).toBe("3B");
  });

  test("component follows the fail-closed display contract", () => {
    const now = jest.spyOn(Date, "now").mockReturnValue(NOW);
    const wrapper = shallowMount(MarketVolumeCell, {
      propsData: {
        row: volumeRow(),
        valueKey: "volume_24h_usdt",
        timestampKey: "volume_updated_at_ms",
      },
      stubs: {
        "el-tooltip": {
          template: "<span><slot /></span>",
        },
      },
    });

    expect(wrapper.text()).toBe("1.5M");
    wrapper.setProps({ row: volumeRow({ volume_available: false }) });
    expect(wrapper.text()).toBe("--");
    now.mockRestore();
  });
});
