import {
  getChainDisplayItems,
  moveEmptyChainLast,
  normalizeChainLayoutMode,
  simplifyChainName,
} from "@/utils/chainDisplay";

describe("chain display helpers", () => {
  it.each([
    ["ETHEREUM(ERC20)", "ETH"],
    ["ETHEREUM", "ETH"],
    ["ERC20", "ETH"],
    ["BNB SMART CHAIN(BEP20)", "BEP20(BSC)"],
    ["BEP20", "BEP20(BSC)"],
    ["BNB SMART CHAIN", "BEP20(BSC)"],
    ["BSC", "BEP20(BSC)"],
    ["SOLANA(SOL)", "SOL"],
    ["SOLANA", "SOL"],
  ])("simplifies the exact chain name %s", (source, expected) => {
    expect(simplifyChainName(source)).toBe(expected);
  });

  it.each([
    "ethereum",
    "ERC20 ",
    "BEP20(BSC)",
    "SOL",
    "SOLANA(SOLANA)",
    "ETHEREUM(ERC20),BSC",
    "空",
  ])("keeps every non-exact chain name unchanged: %s", (network) => {
    expect(simplifyChainName(network)).toBe(network);
  });

  it("moves exact empty items to the end without mutating the source", () => {
    const firstEmpty = { network: "空", is_withdraw: 0 };
    const eth = { network: "ETHEREUM", is_withdraw: 1 };
    const spacedEmpty = { network: "空 ", is_withdraw: 0 };
    const sol = { network: "SOLANA", is_withdraw: 1 };
    const lastEmpty = { network: "空", is_withdraw: 1 };
    const source = [firstEmpty, eth, spacedEmpty, sol, lastEmpty];

    const result = moveEmptyChainLast(source);

    expect(result).toEqual([eth, spacedEmpty, sol, firstEmpty, lastEmpty]);
    expect(source).toEqual([firstEmpty, eth, spacedEmpty, sol, lastEmpty]);
    expect(result[0]).toBe(eth);
  });

  it("only reorders items when the simple layout is active", () => {
    const source = [{ network: "空" }, { network: "ERC20" }];

    expect(getChainDisplayItems(source, false)).toBe(source);
    expect(getChainDisplayItems(source, true)).toEqual([
      { network: "ERC20" },
      { network: "空" },
    ]);
    expect(getChainDisplayItems(null, true)).toEqual([]);
  });

  it.each([
    ["original", "original"],
    ["split", "split"],
    ["splitSimple", "splitSimple"],
    ["unknown", "original"],
    [null, "original"],
  ])("normalizes the stored layout mode %s", (source, expected) => {
    expect(normalizeChainLayoutMode(source)).toBe(expected);
  });
});
