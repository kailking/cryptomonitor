import {
  getSystemLogPresentation,
  systemLogRowClass,
} from "@/utils/systemLog";

describe("system log presentation", () => {
  it.each([
    ["[注意] 新设备首次登录 · Windows / Chrome · 设备 A0FA", "注意", ""],
    ["[风险] 10分钟内切换设备 · 当前设备 FFE0", "风险", "warning"],
    [
      "[高风险] 同一设备关联2个账号 · 设备 D913",
      "高风险",
      "danger",
    ],
  ])("renders compact structured security logs", (remark, label, tagType) => {
    const result = getSystemLogPresentation({ type: 2, remark });

    expect(result.label).toBe(label);
    expect(result.tagType).toBe(tagType);
    expect(result.summary).not.toContain("[");
    expect(result.showDetails).toBe(false);
  });

  it("collapses legacy IP and fingerprint noise behind a detail action", () => {
    const remark =
      "用户catt登陆异常，ip或指纹 更新。(上次登录ip:172.16.0.92,本次登录ip:172.16.0.92,上次登录指纹abc,本次登录指纹:def)";
    const result = getSystemLogPresentation({ type: "2", remark });

    expect(result).toMatchObject({
      label: "历史异常",
      tagType: "info",
      summary: "IP或浏览器指纹发生变化（历史记录）",
      details: remark,
      showDetails: true,
      rowClass: "security-log-legacy",
    });
  });

  it("keeps ordinary operation logs readable", () => {
    const result = getSystemLogPresentation({
      type: 3,
      type_text: "重启全部行情服务",
      remark: "操作成功",
    });

    expect(result).toMatchObject({
      label: "系统操作",
      tagType: "info",
      summary: "操作成功",
      showDetails: false,
      rowClass: "",
    });
  });

  it("returns the row class for table highlighting", () => {
    expect(
      systemLogRowClass({ row: { type: 2, remark: "[高风险] 多设备" } })
    ).toBe("security-log-high");
    expect(systemLogRowClass({ row: { type: 1, remark: "续费" } })).toBe("");
  });
});
