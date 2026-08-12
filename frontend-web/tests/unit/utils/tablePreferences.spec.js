import {
  buildTableWidthKey,
  readJson,
  readTableWidth,
} from "@/utils/tablePreferences";

const unifiedKey = "crypto-monitor:unified:diff:right:width:remark";
const webKey = "crypto-monitor:web:diff:right:width:remark";
const web89Key = "crypto-monitor:web89:diff:right:width:remark";

function createStorage(values = {}) {
  return {
    getItem: jest.fn((key) =>
      Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null
    ),
    setItem: jest.fn(),
    removeItem: jest.fn(),
  };
}

describe("table width preferences", () => {
  it("builds the exact unified key for every page and table side", () => {
    expect(buildTableWidthKey("diff", "main", "symbol")).toBe(
      "crypto-monitor:unified:diff:main:width:symbol"
    );
    expect(buildTableWidthKey("diff_5", "right", "remark")).toBe(
      "crypto-monitor:unified:diff_5:right:width:remark"
    );
  });

  it("returns the unified value before either legacy namespace", () => {
    const storage = createStorage({
      [unifiedKey]: "168",
      [webKey]: "169",
      [web89Key]: "170",
    });

    expect(readTableWidth(storage, "diff", "right", "remark", 120)).toBe(
      168
    );
    expect(storage.getItem.mock.calls).toEqual([[unifiedKey]]);
    expect(storage.setItem).not.toHaveBeenCalled();
    expect(storage.removeItem).not.toHaveBeenCalled();
  });

  it("migrates the first valid web legacy value without deleting old keys", () => {
    const storage = createStorage({
      [webKey]: "171",
      [web89Key]: "172",
    });

    expect(readTableWidth(storage, "diff", "right", "remark", 120)).toBe(
      171
    );
    expect(storage.getItem.mock.calls).toEqual([[unifiedKey], [webKey]]);
    expect(storage.setItem).toHaveBeenCalledWith(unifiedKey, "171");
    expect(storage.removeItem).not.toHaveBeenCalled();
  });

  it("skips an invalid web value and migrates a valid web89 value", () => {
    const storage = createStorage({
      [unifiedKey]: "168px",
      [webKey]: "171.5",
      [web89Key]: "172",
    });

    expect(readTableWidth(storage, "diff", "right", "remark", 120)).toBe(
      172
    );
    expect(storage.getItem.mock.calls).toEqual([
      [unifiedKey],
      [webKey],
      [web89Key],
    ]);
    expect(storage.setItem).toHaveBeenCalledWith(unifiedKey, "172");
    expect(storage.removeItem).not.toHaveBeenCalled();
  });

  it.each([
    ["unit suffix", "168px"],
    ["decimal", "168.5"],
    ["positive sign", "+168"],
    ["negative sign", "-168"],
    ["leading whitespace", " 168"],
    ["trailing whitespace", "168 "],
    ["empty string", ""],
    ["number value", 168],
    ["object value", { width: 168 }],
  ])("falls back for an invalid %s", (_description, invalidValue) => {
    const storage = createStorage({
      [unifiedKey]: invalidValue,
      [webKey]: invalidValue,
      [web89Key]: invalidValue,
    });

    expect(readTableWidth(storage, "diff", "right", "remark", 120)).toBe(
      120
    );
    expect(storage.getItem.mock.calls).toEqual([
      [unifiedKey],
      [webKey],
      [web89Key],
    ]);
    expect(storage.setItem).not.toHaveBeenCalled();
    expect(storage.removeItem).not.toHaveBeenCalled();
  });

  it("returns a valid legacy value when migration writing fails", () => {
    const storage = createStorage({ [webKey]: "173" });
    storage.setItem.mockImplementation(() => {
      throw new Error("storage is read-only");
    });

    expect(readTableWidth(storage, "diff", "right", "remark", 120)).toBe(
      173
    );
    expect(storage.setItem).toHaveBeenCalledWith(unifiedKey, "173");
    expect(storage.removeItem).not.toHaveBeenCalled();
  });

  it("returns the fallback without throwing when storage reading fails", () => {
    const storage = createStorage();
    storage.getItem.mockImplementation(() => {
      throw new Error("storage is blocked");
    });

    expect(readTableWidth(storage, "diff", "right", "remark", 120)).toBe(
      120
    );
    expect(storage.setItem).not.toHaveBeenCalled();
    expect(storage.removeItem).not.toHaveBeenCalled();
  });
});

describe("JSON preferences", () => {
  it("falls back when persisted JSON is corrupt", () => {
    const storage = {
      getItem: jest.fn(() => "{broken"),
    };
    expect(readJson(storage, "key", [])).toEqual([]);
  });
});
