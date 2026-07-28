<template>
  <div class="depth-container">
    <!-- <div class="depth-header">
      <span class="depth-title">{{ currenyName }}/{{ quoteName }}</span>
      <span class="depth-subtitle">深度</span>
    </div> -->
    <div class="depth-tables">
      <div class="depth-table">
        <!-- <div class="table-title sell">卖盘</div> -->
        <el-table
          :data="isBuy ? bidsTable : asksTable"
          size="mini"
          stripe
          :empty-text="emptyText"
        >
          <el-table-column label="价格" min-width="110">
            <template slot-scope="{ row }">
              <span
                class="price"
                :class="{
                  buy: isBuy,
                  sell: !isBuy,
                }"
                >{{ formatDecimal(row.price, priceDecimals) }}</span
              >
            </template>
          </el-table-column>
          <el-table-column label="数量" min-width="110">
            <template slot-scope="{ row }">
              <span class="amount">{{
                formatDecimal(row.amount, amountDecimals)
              }}</span>
            </template>
          </el-table-column>
          <el-table-column label="统计" min-width="120">
            <template slot-scope="{ row }">
              <span class="total">{{ formatFixed(row.total, 2) }}</span>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <!-- <div class="depth-table">
        <div class="table-title buy">买盘</div>
        <el-table :data="bidsTable" size="mini" stripe :empty-text="emptyText">
          <el-table-column label="价格" min-width="110">
            <template slot-scope="{ row }">
              <span class="price buy">{{
                formatDecimal(row.price, priceDecimals)
              }}</span>
            </template>
          </el-table-column>
          <el-table-column label="数量" min-width="110">
            <template slot-scope="{ row }">
              <span class="amount">{{
                formatDecimal(row.amount, amountDecimals)
              }}</span>
            </template>
          </el-table-column>
          <el-table-column label="统计" min-width="120">
            <template slot-scope="{ row }">
              <span class="total">{{ formatFixed(row.total, 2) }}</span>
            </template>
          </el-table-column>
        </el-table>
      </div> -->
    </div>
  </div>
</template>

<script>
import { createWebSocket } from "@/utils/websocket";

export default {
  name: "DepthTable",
  props: {
    isShow: {
      type: Boolean,
      default: false,
    },
    isBuy: {
      type: Boolean,
      default: true,
    },
    id: {
      type: Number,
      required: true,
      default: 0,
    },
    platform: {
      type: [String, Number],
      required: true,
      default: "",
    },
    quoteName: {
      type: String,
      required: true,
      default: "",
    },
    currenyName: {
      type: String,
      required: true,
      default: "",
    },
    depthLimit: {
      type: Number,
      default: 10,
    },
    priceDecimals: {
      type: Number,
      default: 8,
    },
    amountDecimals: {
      type: Number,
      default: 8,
    },
  },
  data() {
    return {
      wsManager: null,
      bids: [],
      asks: [],
      // 新增：用Map存储完整深度数据，key为价格字符串，避免浮点精度问题
      bidsMap: new Map(),
      asksMap: new Map(),
      emptyText: "暂无数据",
    };
  },
  computed: {
    bidsTable() {
      return this.bids.slice(0, this.depthLimit);
    },
    asksTable() {
      return this.asks.slice(0, this.depthLimit);
    },
  },
  watch: {
    id() {
      this.handleSymbolChange();
    },

    // currenyName() {
    //   this.handleSymbolChange();
    // },
    // quoteName() {
    //   this.handleSymbolChange();
    // },
    // platform() {
    //   this.handleSymbolChange();
    // },
  },
  mounted() {
    this.initWebSocket();
  },
  beforeDestroy() {
    if (this.wsManager) {
      this.wsManager.disconnect();
    }
  },
  methods: {
    async initWebSocket() {
      let wsUrl = "";
      let huobiSub = null;
      let gateSub = null;
      let mexcSub = null;
      let kucoinSub = null;
      let bitgetSub = null;
      let bybitSub = null;
      let bitmartSub = null;
      let okxSub = null;

      if (this.platform == 2) {
        const { getBinanceDepthUrl } = await import("@/utils/BINANCE");
        wsUrl = getBinanceDepthUrl(this.currenyName + this.quoteName);
      }
      if (this.platform == 1) {
        const { HUOBI_WS_BASE_URL, getHuobiDepthSub } = await import(
          "@/utils/HUOBI"
        );
        wsUrl = HUOBI_WS_BASE_URL;
        huobiSub = getHuobiDepthSub(this.currenyName + this.quoteName);
      }
      if (this.platform == 4) {
        const { GATE_WS_BASE_URL, getGateDepthSub } = await import(
          "@/utils/GATE"
        );
        wsUrl = GATE_WS_BASE_URL;
        gateSub = getGateDepthSub(this.currenyName + this.quoteName);
      }
      if (this.platform == 5) {
        const { MEXC_WS_BASE_URL, getMexcDepthSub } = await import(
          "@/utils/MEXC"
        );
        wsUrl = MEXC_WS_BASE_URL;
        mexcSub = getMexcDepthSub(this.currenyName + this.quoteName);
      }
      if (this.platform == 8) {
        const { fetchKucoinWsToken, getKucoinWsUrl, getKucoinDepthSub } =
          await import("@/utils/KUCOIN");
        const tokenData = await fetchKucoinWsToken();
        if (tokenData && tokenData.token && tokenData.instanceServers) {
          const endpoint = tokenData.instanceServers[0].endpoint;
          wsUrl = getKucoinWsUrl(tokenData.token, endpoint);
          kucoinSub = getKucoinDepthSub(this.currenyName + this.quoteName);
        }
      }
      if (this.platform == 15) {
        const { BITGET_WS_BASE_URL, getBitgetDepthSub } = await import(
          "@/utils/BITGET"
        );
        wsUrl = BITGET_WS_BASE_URL;
        bitgetSub = getBitgetDepthSub(this.currenyName + this.quoteName);
      }
      if (this.platform == 16) {
        const { BYBIT_WS_BASE_URL, getBybitDepthSub } = await import(
          "@/utils/BYBIT"
        );
        wsUrl = BYBIT_WS_BASE_URL;
        bybitSub = getBybitDepthSub(this.currenyName + this.quoteName, 50);
      }
      if (this.platform == 17) {
        const { BITMART_WS_BASE_URL, getBitmartDepthSub } = await import(
          "@/utils/BITMART"
        );
        wsUrl = BITMART_WS_BASE_URL;
        bitmartSub = getBitmartDepthSub(this.currenyName + this.quoteName);
      }
      if (this.platform == 3) {
        const { OKX_WS_BASE_PUBLIC_URL, getOkxDepthSub } = await import(
          "@/utils/OKEX"
        );
        wsUrl = OKX_WS_BASE_PUBLIC_URL;
        okxSub = getOkxDepthSub(this.currenyName + this.quoteName);
      }

      if (!wsUrl) {
        this.emptyText = "当前平台暂不支持深度";
        return;
      }

      this.wsManager = createWebSocket(wsUrl);

      this.wsManager.on("open", () => {
        if (huobiSub) {
          this.wsManager.send(huobiSub);
        }
        if (gateSub) {
          this.wsManager.send(gateSub);
        }
        if (mexcSub) {
          this.wsManager.send(mexcSub);
        }
        if (kucoinSub) {
          this.wsManager.send(kucoinSub);
        }
        if (bitgetSub) {
          this.wsManager.send(bitgetSub);
        }
        if (bybitSub) {
          this.wsManager.send(bybitSub);
        }
        if (bitmartSub) {
          this.wsManager.send(bitmartSub);
        }
        if (okxSub) {
          this.wsManager.send(okxSub);
        }
      });

      this.wsManager.on("message", (data) => {
        this.handleWebSocketMessage(data);
      });

      this.wsManager.on("error", (error) => {
        console.error("WebSocket error:", error);
        this.$message.error("Depth connect failed");
      });

      this.wsManager.on("close", () => {
        console.log("WebSocket closed");
      });

      this.wsManager.connect().catch((error) => {
        console.error("WebSocket connect failed:", error);
      });
    },
    handleWebSocketMessage(data) {
      try {
        if (this.platform == 2) {
          const bids = data.bids || data.b || [];
          const asks = data.asks || data.a || [];
          this.updateDepth(bids, asks);
        } else if (this.platform == 1) {
          if (data.ping) {
            if (this.wsManager) {
              this.wsManager.send({ pong: data.ping });
            }
            return;
          }

          if (data.tick) {
            this.updateDepth(data.tick.bids || [], data.tick.asks || []);
          }
        } else if (this.platform == 3) {
          if (data === "ping" || data.event === "ping") {
            if (this.wsManager) {
              this.wsManager.send("pong");
            }
            return;
          }
          const action = data.action; // 'snapshot' 或 'update'

          if (action === "snapshot") {
            const item = data.data[0];
            // 全量数据：清空Map，重新填充
            this.bidsMap.clear();
            this.asksMap.clear();
            // 全量数据，直接替换
            item.bids.forEach(([price, amount]) => {
              this.bidsMap.set(price, amount);
            });
            item.asks.forEach(([price, amount]) => {
              this.asksMap.set(price, amount);
            });
            this.syncDepthFromMap();
          } else if (action === "update") {
            const item = data.data[0];
            // 增量更新，合并到本地
            // 处理买盘更新 (bids)
            item.bids.forEach(([price, amount]) => {
              if (parseFloat(amount) === 0) {
                this.bidsMap.delete(price); // 数量为0，删除该档位
              } else {
                this.bidsMap.set(price, amount); // 新增或更新
              }
            });

            // 处理卖盘更新 (asks)
            item.asks.forEach(([price, amount]) => {
              if (parseFloat(amount) === 0) {
                this.asksMap.delete(price);
              } else {
                this.asksMap.set(price, amount);
              }
            });

            this.syncDepthFromMap();
          }
        } else if (this.platform == 4) {
          if (
            data &&
            data.channel === "spot.order_book" &&
            data.event === "update" &&
            data.result
          ) {
            this.updateDepth(data.result.bids || [], data.result.asks || []);
          }
        } else if (this.platform == 5) {
          if (data && data.depth) {
            this.updateDepth(data.depth.bids || [], data.depth.asks || []);
          }
        } else if (this.platform == 8) {
          if (data && data.type === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ type: "pong", id: data.id });
            }
            return;
          }
          if (data && data.type === "message" && data.data) {
            const payload = data.data;
            if (payload && (payload.bids || payload.asks)) {
              this.updateDepth(payload.bids || [], payload.asks || []);
            }
          }
        } else if (this.platform == 15) {
          if (data && data.event === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ op: "pong" });
            }
            return;
          }
          if (data && Array.isArray(data.data) && data.data.length) {
            const payload = data.data[0];
            if (payload && (payload.bids || payload.asks)) {
              this.updateDepth(payload.bids || [], payload.asks || []);
            }
          }
        } else if (this.platform == 16) {
          if (data && data.op === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ op: "pong" });
            }
            return;
          }

          const action = data.type; // 'snapshot' 或 'update'

          if (action === "snapshot") {
            const payload = data.data;
            // 全量数据：清空Map，重新填充
            this.bidsMap.clear();
            this.asksMap.clear();
            const bids = payload.b || payload.bids || [];
            const asks = payload.a || payload.asks || [];
            // 全量数据，直接替换
            bids.forEach(([price, amount]) => {
              this.bidsMap.set(price, amount);
            });
            asks.forEach(([price, amount]) => {
              this.asksMap.set(price, amount);
            });
            this.syncDepthFromMap();
          } else if (action === "delta") {
            const payload = data.data;
            // 增量更新，合并到本地
            // 处理买盘更新 (bids)
            const bids = payload.b || payload.bids || [];
            const asks = payload.a || payload.asks || [];
            bids.forEach(([price, amount]) => {
              if (parseFloat(amount) === 0) {
                this.bidsMap.delete(price); // 数量为0，删除该档位
              } else {
                this.bidsMap.set(price, amount); // 新增或更新
              }
            });

            // 处理卖盘更新 (asks)
            asks.forEach(([price, amount]) => {
              if (parseFloat(amount) === 0) {
                this.asksMap.delete(price);
              } else {
                this.asksMap.set(price, amount);
              }
            });

            this.syncDepthFromMap();
          }
        } else if (this.platform == 17) {
          if (data && data.event === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ op: "pong" });
            }
            return;
          }
          if (data && data.data) {
            const payload = data.data[0];
            const bids = payload.bids || payload.b || [];
            const asks = payload.asks || payload.a || [];
            if (bids.length || asks.length) {
              this.updateDepth(bids, asks);
            }
          }
        }
      } catch (error) {
        console.error("WebSocket message error:", error);
      }
    },
    syncDepthFromMap() {
      // 买盘：价格从高到低，取前10
      const bidsArr = Array.from(this.bidsMap.entries())
        .map(([price, amount]) => ({
          price: parseFloat(price),
          amount: parseFloat(amount),
          total: parseFloat(price) * parseFloat(amount),
        }))
        .sort((a, b) => b.price - a.price)
        .slice(0, this.depthLimit);

      // 卖盘：价格从低到高，取前10
      const asksArr = Array.from(this.asksMap.entries())
        .map(([price, amount]) => ({
          price: parseFloat(price),
          amount: parseFloat(amount),
          total: parseFloat(price) * parseFloat(amount),
        }))
        .sort((a, b) => a.price - b.price)
        .slice(0, this.depthLimit);

      this.bids = bidsArr;
      this.asks = asksArr;
    },
    updateDepth(rawBids, rawAsks) {
      const bids = this.normalizeDepthList(rawBids).sort(
        (a, b) => b.price - a.price
      );
      const asks = this.normalizeDepthList(rawAsks).sort(
        (a, b) => a.price - b.price
      );
      this.bids = bids.slice(0, 10);
      this.asks = asks.slice(0, 10);
    },
    normalizeDepthList(list) {
      if (!Array.isArray(list)) return [];

      return list
        .map((item) => {
          const price = parseFloat(item[0] || item["price"]);
          const amount = parseFloat(item[1] || item["amount"]);
          if (Number.isNaN(price) || Number.isNaN(amount)) {
            return null;
          }
          return {
            price,
            amount,
            total: price * amount,
          };
        })
        .filter(Boolean);
    },
    formatDecimal(value, maxDecimals = 8) {
      const num = Number(value);
      if (Number.isNaN(num)) return value;
      const fixed = num.toFixed(maxDecimals);
      return fixed.replace(/\.?0+$/, "");
    },
    formatFixed(value, decimals) {
      const num = Number(value);
      if (Number.isNaN(num)) return value;
      return num.toFixed(decimals);
    },
    handleSymbolChange() {
      this.bids = [];
      this.asks = [];
      if (this.wsManager) {
        this.wsManager.disconnect();
      }
      this.initWebSocket();
    },
  },
};
</script>

<style lang="scss" scoped>
.depth-container {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 10px;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  background: #fff;
}

.depth-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.depth-title {
  font-size: 18px;
  font-weight: 600;
  color: #1f2d3d;
}

.depth-subtitle {
  font-size: 12px;
  color: #8c94a1;
}

.depth-tables {
  display: grid;
  //grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.depth-table {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.table-title {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.5px;
}

.table-title.sell {
  color: #f92855;
}

.table-title.buy {
  color: #2dc08e;
}

.price.sell {
  color: #f92855;
  font-weight: 600;
}

.price.buy {
  color: #2dc08e;
  font-weight: 600;
}

.amount,
.total {
  color: #666;
}

/deep/ .el-table {
  border-radius: 4px;
}

/deep/ .el-table th {
  background: #f7f8fa;
  color: #666;
  font-weight: 600;
}

/deep/ .el-table td {
  padding: 6px 0;
}

@media (max-width: 768px) {
  .depth-tables {
    grid-template-columns: 1fr;
  }
}
</style>
