const SIMPLE_CHAIN_NAME_MAP = Object.freeze({
  "ETHEREUM(ERC20)": "ETH",
  ETHEREUM: "ETH",
  ERC20: "ETH",
  "BNB SMART CHAIN(BEP20)": "BEP20(BSC)",
  BEP20: "BEP20(BSC)",
  "BNB SMART CHAIN": "BEP20(BSC)",
  BSC: "BEP20(BSC)",
  "SOLANA(SOL)": "SOL",
  SOLANA: "SOL",
});

export function simplifyChainName(network) {
  if (
    typeof network === "string" &&
    Object.prototype.hasOwnProperty.call(SIMPLE_CHAIN_NAME_MAP, network)
  ) {
    return SIMPLE_CHAIN_NAME_MAP[network];
  }

  return network;
}

export function moveEmptyChainLast(items) {
  if (!Array.isArray(items)) return [];

  const regularItems = [];
  const emptyItems = [];

  items.forEach((item) => {
    if (item && item.network === "空") {
      emptyItems.push(item);
    } else {
      regularItems.push(item);
    }
  });

  return regularItems.concat(emptyItems);
}

export function getChainDisplayItems(items, useSimpleLayout) {
  if (!Array.isArray(items)) return [];
  return useSimpleLayout ? moveEmptyChainLast(items) : items;
}

export function normalizeChainLayoutMode(value) {
  return value === "split" || value === "splitSimple" ? value : "original";
}
