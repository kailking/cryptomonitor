<template>
  <span class="change-percent" :class="percentClass">{{ displayText }}</span>
</template>

<script>
import { createWebSocket } from "@/utils/websocket";
import { formatDecimal } from "@/utils/index";
export default {
  name: "ChangePercent",
  props: {
    isShow: {
      type: Boolean,
      default: false,
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
  },
  data() {
    return {
      timer: null,
      wsManager: null,
      percentValue: "",
    };
  },
  computed: {
    displayText() {
      if (this.percentValue === "") {
        return "--";
      }
      return `${this.percentValue}%`;
    },
    percentClass() {
      const num = Number(this.percentValue);
      if (!Number.isFinite(num)) return "";
      if (num > 0) return "buy";
      if (num < 0) return "sell";
      return "";
    },
  },
  watch: {
    platform() {
      this.initWebSocket();
    },
    isShow(val) {
      if (val === false) this.wsManager.disconnect();
    },
  },
  destroyed() {
    clearInterval(this.timer);
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
      if (this.wsManager) {
        this.wsManager.disconnect();
      }

      if (
        this.platform != 1 &&
        this.platform != 2 &&
        this.platform != 3 &&
        this.platform != 4 &&
        this.platform != 5 &&
        this.platform != 8 &&
        this.platform != 15 &&
        this.platform != 16 &&
        this.platform != 17
      ) {
        this.percentValue = "";
        return;
      }

      let wsUrl = "";
      let huobiSub = null;
      let gateSub = null;
      let okxSub = null;
      let mexcSub = null;
      const kucoinSub = null;
      let bitgetSub = null;
      let bybitSub = null;
      let bitmartSub = null;

      if (this.platform == 1) {
        const { HUOBI_WS_BASE_URL, getHuobiDetailSub } = await import(
          "@/utils/HUOBI"
        );
        wsUrl = HUOBI_WS_BASE_URL;
        huobiSub = getHuobiDetailSub(this.currenyName + this.quoteName);
      }

      if (this.platform == 2) {
        const { getBinance24HrTickerUrl } = await import("@/utils/BINANCE");
        wsUrl = getBinance24HrTickerUrl(this.currenyName + this.quoteName);
      }

      if (this.platform == 3) {
        const { OKX_WS_BASE_PUBLIC_URL, getOkxTickerSub } = await import(
          "@/utils/OKEX"
        );
        wsUrl = OKX_WS_BASE_PUBLIC_URL;
        okxSub = getOkxTickerSub(this.currenyName + this.quoteName);
      }

      if (this.platform == 4) {
        const { GATE_WS_BASE_URL, getGateTickerSub } = await import(
          "@/utils/GATE"
        );
        wsUrl = GATE_WS_BASE_URL;
        gateSub = getGateTickerSub(this.currenyName + this.quoteName);
      }

      if (this.platform == 5) {
        const { MEXC_WS_BASE_URL, getMexcTickerSub } = await import(
          "@/utils/MEXC"
        );
        wsUrl = MEXC_WS_BASE_URL;
        mexcSub = getMexcTickerSub(this.currenyName + this.quoteName);
      }

      if (this.platform == 8) {
        clearInterval(this.timer);
        const { fetchKucoin24hStats } = await import("@/utils/KUCOIN");
        fetchKucoin24hStats(this.currenyName + this.quoteName).then((res) => {
          this.percentValue = formatDecimal(res.changePercent);
        });
        this.timer = setInterval(() => {
          fetchKucoin24hStats(this.currenyName + this.quoteName).then((res) => {
            this.percentValue = formatDecimal(res.changePercent);
          });
        }, 5000);
        return;
      }
      if (this.platform == 15) {
        const { BITGET_WS_BASE_URL, getBitgetTickerSub } = await import(
          "@/utils/BITGET"
        );
        wsUrl = BITGET_WS_BASE_URL;
        bitgetSub = getBitgetTickerSub(this.currenyName + this.quoteName);
      }
      if (this.platform == 16) {
        const { BYBIT_WS_BASE_URL, getBybitTickerSub } = await import(
          "@/utils/BYBIT"
        );
        wsUrl = BYBIT_WS_BASE_URL;
        bybitSub = getBybitTickerSub(this.currenyName + this.quoteName);
      }
      if (this.platform == 17) {
        const { BITMART_WS_BASE_URL, getBitmartTickerSub } = await import(
          "@/utils/BITMART"
        );
        wsUrl = BITMART_WS_BASE_URL;
        bitmartSub = getBitmartTickerSub(this.currenyName + this.quoteName);
      }

      if (!wsUrl) {
        this.percentValue = "";
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
        if (okxSub) {
          this.wsManager.send(okxSub);
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
      });

      this.wsManager.on("message", (data) => {
        this.handleWebSocketMessage(data);
      });

      this.wsManager.on("error", (error) => {
        console.error("ChangePercent WebSocket error:", error);
      });

      this.wsManager.connect().catch((error) => {
        console.error("ChangePercent WebSocket connect failed:", error);
      });
    },
    handleWebSocketMessage(data) {
      try {
        if (this.platform == 1) {
          if (data.ping) {
            if (this.wsManager) {
              this.wsManager.send({ pong: data.ping });
            }
            return;
          }

          if (data.tick && data.ch && data.ch.includes(".detail")) {
            const open = Number(data.tick.open);
            const close = Number(data.tick.close);
            this.updatePercent(open, close);
          }
        } else if (this.platform == 2) {
          const payload = data && data.data ? data.data : data;
          if (payload && payload.e === "24hrTicker") {
            const percent = Number(payload.P);
            this.percentValue = Number.isFinite(percent)
              ? percent.toFixed(2)
              : "";
          }
        } else if (this.platform == 3) {
          if (data === "ping" || data.event === "ping") {
            if (this.wsManager) {
              this.wsManager.send("pong");
            }
            return;
          }

          if (
            data.arg &&
            data.arg.channel === "tickers" &&
            Array.isArray(data.data) &&
            data.data.length
          ) {
            const item = data.data[0];
            const open = Number(item.open24h);
            const last = Number(item.last);
            this.updatePercent(open, last);
          }
        } else if (this.platform == 4) {
          if (data && data.event === "pong") {
            return;
          }
          if (data && data.channel === "spot.tickers" && data.result) {
            const result = Array.isArray(data.result)
              ? data.result[0]
              : data.result;
            if (!result) return;
            const change = Number(result.change_percentage);
            if (Number.isFinite(change)) {
              this.percentValue = change.toFixed(2);
              return;
            }
            const open = Number(result.open_24h || result.open);
            const last = Number(result.last);
            this.updatePercent(open, last);
          }
        } else if (this.platform == 5) {
          if (data.ticker) {
            const ticker = data.ticker;
            this.percentValue = Number(ticker.changePercent);
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
            const rate = Number(payload.change24h || payload.changeUtc24h);
            if (Number.isFinite(rate)) {
              const percent = Math.abs(rate) < 1 ? rate * 100 : rate;
              this.percentValue = percent.toFixed(2);
              return;
            }
          }
        } else if (this.platform == 16) {
          if (data && data.op === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ op: "pong" });
            }
            return;
          }
          if (data && data.topic && data.data) {
            const payload = data.data;
            const rate = Number(payload.price24hPcnt);
            if (Number.isFinite(rate)) {
              this.percentValue = (rate * 100).toFixed(2);
              return;
            }
          }
        } else if (this.platform == 17) {
          if (data && data.op === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ op: "pong" });
            }
            return;
          }
          const payload = Array.isArray(data.data) ? data.data[0] : data.data;
          if (payload) {
            const rate = Number(payload.fluctuation);
            if (Number.isFinite(rate)) {
              this.percentValue = rate.toFixed(2);
              return;
            }
          }
        }
      } catch (error) {
        console.error("ChangePercent message error:", error);
      }
    },
    updatePercent(open, close) {
      if (!Number.isFinite(open) || !Number.isFinite(close) || open === 0) {
        this.percentValue = "";
        return;
      }
      this.percentValue = (((close - open) / open) * 100).toFixed(2);
    },
  },
};
</script>

<style scoped>
.change-percent {
  font-weight: 600;
}
</style>
