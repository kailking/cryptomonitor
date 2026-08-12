import { isColumnVisible } from "@/utils/columnVisibility";

describe("isColumnVisible", () => {
  const columns = [
    { key: "buy_num", ispass: false },
    { key: "lossgiftfee", ispass: true },
  ];

  it("looks up visibility by key instead of array position", () => {
    expect(isColumnVisible(columns, "lossgiftfee")).toBe(true);
    expect(isColumnVisible(columns.slice().reverse(), "lossgiftfee")).toBe(true);
  });

  it("returns the explicit fallback when the key is absent", () => {
    expect(isColumnVisible(columns, "remark", false)).toBe(false);
    expect(isColumnVisible(columns, "remark", true)).toBe(true);
  });

  it("does not throw for malformed persisted data", () => {
    expect(isColumnVisible(null, "lossgiftfee")).toBe(false);
    expect(isColumnVisible([null], "lossgiftfee")).toBe(false);
  });
});
