<template>
  <div class="kline-container">
    <div class="kline-header">
      <div class="kline-controls">
        <span
          class="kline-title"
          :class="{
            buy: isBuy,
            sell: !isBuy,
          }"
          >{{ currenyName.toLocaleUpperCase() }}({{ platform_name | toName
          }}{{ isBuy ? "买" : "卖" }})
        </span>
        <ChangePercent
          :is-show="isShow"
          class="kline-title"
          :platform="platform"
          :curreny-name="currenyName"
          :quote-name="quoteName"
        />
        <!-- <el-select
          v-model="interval"
          placeholder="选择时间周期"
          size="small"
          style="width: 120px"
          @change="handleIntervalChange"
        >
          <el-option label="1分钟" value="1m" />
          <el-option label="5分钟" value="5m" />
          <el-option label="15分钟" value="15m" />
          <el-option label="30分钟" value="30m" />
          <el-option label="1小时" value="1h" />
          <el-option label="4小时" value="4h" />
          <el-option label="1天" value="1d" />
        </el-select> -->
        <!-- <el-button
          size="small"
          type="primary"
          style="margin-left: 10px"
          @click="refreshData"
        >
          刷新
        </el-button> -->
      </div>
    </div>
    <div>
      <el-button
        v-for="item in intervalList"
        :key="item.value"
        size="mini"
        :type="interval == item.value ? 'primary' : 'default'"
        @click="handleIntervalChange(item)"
        >{{ item.label }}</el-button
      >
    </div>
    <div class="kline-info">
      <span v-if="newKline.time" class="price-info"
        >时间: {{ newKline.time }}</span
      >
      <span v-if="newKline.high" class="price-info"
        >高: {{ newKline.high }}</span
      >
      <span v-if="newKline.open" class="price-info"
        >开: {{ newKline.open }}</span
      >
      <span v-if="newKline.low" class="price-info">低: {{ newKline.low }}</span>
      <span v-if="newKline.close" class="price-info"
        >收: {{ newKline.close }}</span
      >
    </div>
    <div ref="chartContainer" class="kline-chart" />
  </div>
</template>

<script>
import * as echarts from "echarts";
import { createWebSocket } from "@/utils/websocket";
import { getBuyKlineData, getSellKlineData } from "@/api/kline";
import { covertime } from "@/utils/index";
import ChangePercent from "./changePercent.vue";
export default {
  name: "KlineChart",
  components: {
    ChangePercent,
  },
  props: {
    isShow: {
      type: Boolean,
      default: false,
    },
    id: {
      type: Number,
      required: true,
      default: 0,
    },
    isBuy: {
      type: Boolean,
      default: false,
    },
    platform: {
      type: String,
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
    platformList: {
      type: Array,
      default: () => [],
    },
    // 是否自动加载历史数据
    autoLoadHistory: {
      type: Boolean,
      default: true,
    },
  },
  data() {
    return {
      intervalList: [
        {
          label: "1分钟",
          value: "1m",
        },
        {
          label: "5分钟",
          value: "5m",
        },
        {
          label: "15分钟",
          value: "15m",
        },
        {
          label: "30分钟",
          value: "30m",
        },
        {
          label: "1小时",
          value: "1h",
        },
        {
          label: "4小时",
          value: "4h",
        },
        {
          label: "1天",
          value: "1d",
        },
      ],
      chart: null,
      interval: "1m",
      klineData: [],
      wsManager: null,
      currentBarIndex: -1,
      loading: false,
      platform_name: "",
      newKline: {
        time: "",
        open: "",
        close: "",
        low: "",
        high: "",
      },
    };
  },
  watch: {
    id(newVal) {
      this.handleSymbolChange(newVal);
    },
    platformList: {
      handler(val) {
        for (const i in val) {
          if (val[i]["key"] == this.platform) {
            this.platform_name = val[i]["item"];
            break;
          }
        }
      },
      immediate: true,
    },
  },
  mounted() {
    this.initChart();
    if (this.autoLoadHistory) {
      this.loadHistoricalData();
    }
    this.initWebSocket();

    // 监听chartContainer的鼠标离开事件
    if (this.$refs.chartContainer) {
      this.$refs.chartContainer.addEventListener(
        "mouseleave",
        this.handleChartMouseLeave
      );
    }
  },
  beforeDestroy() {
    if (this.wsManager) {
      this.wsManager.disconnect();
    }
    if (this.chart) {
      this.chart.dispose();
    }
    // 移除事件监听器
    if (this.$refs.chartContainer) {
      this.$refs.chartContainer.removeEventListener(
        "mouseleave",
        this.handleChartMouseLeave
      );
    }
    window.removeEventListener("resize", this.handleResize);
  },
  methods: {
    initChart() {
      if (!this.$refs.chartContainer) return;
      this.chart = echarts.init(this.$refs.chartContainer, null, {
        renderer: "canvas",
      });
      this.updateChart();
      window.addEventListener("resize", this.handleResize);
    },
    handleResize: function () {
      if (this.chart) {
        this.chart.resize();
      }
    },
    async initWebSocket() {
      let wsUrl = "";
      let huobiSub = null;
      let okxSub = null;
      let gateSub = null;
      let mexcSub = null;
      let kucoinSub = null;
      let bitgetSub = null;
      let bybitSub = null;
      let bitmartSub = null;
      if (this.platform == 2) {
        const { getBinanceKlineUrl } = await import("@/utils/BINANCE");
        wsUrl = getBinanceKlineUrl(
          this.currenyName + this.quoteName,
          this.interval
        );
      }
      if (this.platform == 1) {
        const { HUOBI_WS_BASE_URL, getHuobiKlineSub } = await import(
          "@/utils/HUOBI"
        );
        wsUrl = HUOBI_WS_BASE_URL;
        huobiSub = getHuobiKlineSub(
          this.currenyName + this.quoteName,
          this.interval
        );
      }
      if (this.platform == 3) {
        const { OKX_WS_BASE_URL, getOkxKlineSub } = await import(
          "@/utils/OKEX"
        );
        wsUrl = OKX_WS_BASE_URL;
        okxSub = getOkxKlineSub(
          this.currenyName + this.quoteName,
          this.interval
        );
      }
      if (this.platform == 4) {
        const { GATE_WS_BASE_URL, getGateKlineSub } = await import(
          "@/utils/GATE"
        );
        wsUrl = GATE_WS_BASE_URL;
        gateSub = getGateKlineSub(
          this.currenyName + this.quoteName,
          this.interval
        );
      }
      if (this.platform == 5) {
        const { MEXC_WS_BASE_URL, getMexcKlineSub } = await import(
          "@/utils/MEXC"
        );
        wsUrl = MEXC_WS_BASE_URL;
        mexcSub = getMexcKlineSub(
          this.currenyName + this.quoteName,
          this.interval
        );
      }
      if (this.platform == 8) {
        const { fetchKucoinWsToken, getKucoinWsUrl, getKucoinKlineSub } =
          await import("@/utils/KUCOIN");
        const tokenData = await fetchKucoinWsToken();
        if (tokenData && tokenData.token && tokenData.instanceServers) {
          const endpoint = tokenData.instanceServers[0].endpoint;
          wsUrl = getKucoinWsUrl(tokenData.token, endpoint);
          kucoinSub = getKucoinKlineSub(
            this.currenyName + this.quoteName,
            this.interval
          );
        }
      }
      if (this.platform == 15) {
        const { BITGET_WS_BASE_URL, getBitgetKlineSub } = await import(
          "@/utils/BITGET"
        );
        wsUrl = BITGET_WS_BASE_URL;
        bitgetSub = getBitgetKlineSub(
          this.currenyName + this.quoteName,
          this.interval
        );
      }
      if (this.platform == 16) {
        const { BYBIT_WS_BASE_URL, getBybitKlineSub } = await import(
          "@/utils/BYBIT"
        );
        wsUrl = BYBIT_WS_BASE_URL;
        bybitSub = getBybitKlineSub(
          this.currenyName + this.quoteName,
          this.interval
        );
      }
      if (this.platform == 17) {
        const { BITMART_WS_BASE_URL, getBitmartKlineSub } = await import(
          "@/utils/BITMART"
        );
        wsUrl = BITMART_WS_BASE_URL;
        bitmartSub = getBitmartKlineSub(
          this.currenyName + this.quoteName,
          this.interval
        );
      }

      this.wsManager = createWebSocket(wsUrl);

      this.wsManager.on("open", () => {
        if (huobiSub) {
          this.wsManager.send(huobiSub);
        }
        if (okxSub) {
          this.wsManager.send(okxSub);
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
      });

      this.wsManager.on("message", (data) => {
        this.handleWebSocketMessage(data);
      });

      this.wsManager.on("error", (error) => {
        console.error("WebSocket error:", error);
        this.$message.error("Kline connect failed");
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
          const payload = data && data.data ? data.data : data;
          if (payload && payload.k) {
            const kline = payload.k;
            this.updateKlineData(kline);
          } else if (payload && payload.type === "kline") {
            this.updateKlineData(payload.data);
          }
        } else if (this.platform == 1) {
          if (data.ping) {
            if (this.wsManager) {
              this.wsManager.send({ pong: data.ping });
            }
            return;
          }
          if (data.tick && data.ch) {
            const tick = data.tick;
            this.updateKlineData({
              t: tick.id ? tick.id * 1000 : tick.ts,
              o: parseFloat(tick.open),
              c: parseFloat(tick.close),
              h: parseFloat(tick.high),
              l: parseFloat(tick.low),
              v: parseFloat(tick.vol),
            });
          }
        } else if (this.platform == 3) {
          if (data === "ping" || data.event === "ping") {
            if (this.wsManager) {
              this.wsManager.send("pong");
            }
            return;
          }

          if (data.arg && Array.isArray(data.data) && data.data.length) {
            const item = data.data[0];
            if (item && item.length >= 5) {
              this.updateKlineData({
                t: Number(item[0]),
                o: parseFloat(item[1]),
                h: parseFloat(item[2]),
                l: parseFloat(item[3]),
                c: parseFloat(item[4]),
                v: parseFloat(item[5]),
              });
            }
          }
        } else if (this.platform == 4) {
          if (data.event === "pong") {
            return;
          }

          if (
            data.channel === "spot.candlesticks" &&
            data.result &&
            !data.result["status"]
          ) {
            const item = data.result;
            this.updateKlineData({
              t: Number(item.t) * 1000,
              v: parseFloat(item.v),
              c: parseFloat(item.c),
              h: parseFloat(item.h),
              l: parseFloat(item.l),
              o: parseFloat(item.o),
            });
          }
        } else if (this.platform == 5) {
          if (data && data._pb) {
            const rawTime = Number(data.start_time || data.end_time || 0);
            const time = rawTime > 1000000000000 ? rawTime : rawTime * 1000;
            this.updateKlineData({
              t: time,
              o: parseFloat(data.open),
              c: parseFloat(data.close),
              h: parseFloat(data.high),
              l: parseFloat(data.low),
              v: parseFloat(data.volume),
            });
            return;
          }
        } else if (this.platform == 8) {
          if (data && data.type === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ type: "pong", id: data.id });
            }
            return;
          }

          if (data && data.type === "message" && data.data) {
            const candles = data.data.candles;
            if (candles && candles.length >= 6) {
              this.updateKlineData({
                t: Number(candles[0]) * 1000,
                o: parseFloat(candles[1]),
                c: parseFloat(candles[2]),
                h: parseFloat(candles[3]),
                l: parseFloat(candles[4]),
                v: parseFloat(candles[5]),
              });
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
            const item = data.data[0];
            if (item && item.length >= 6) {
              this.updateKlineData({
                t: Number(item[0]),
                o: parseFloat(item[1]),
                h: parseFloat(item[2]),
                l: parseFloat(item[3]),
                c: parseFloat(item[4]),
                v: parseFloat(item[5]),
              });
            }
          }
        } else if (this.platform == 16) {
          if (data && data.op === "ping") {
            if (this.wsManager) {
              this.wsManager.send({ op: "pong" });
            }
            return;
          }

          if (
            data &&
            data.topic &&
            data.data &&
            data.topic.startsWith("kline.")
          ) {
            const item = Array.isArray(data.data) ? data.data[0] : data.data;
            if (item) {
              this.updateKlineData({
                t: Number(item.timestamp),
                o: parseFloat(item.open),
                h: parseFloat(item.high),
                l: parseFloat(item.low),
                c: parseFloat(item.close),
                v: parseFloat(item.volume),
              });
            }
          }
        } else if (this.platform == 17) {
          if (data && data.op === "pong") {
            return;
          }

          if (data && Array.isArray(data.data)) {
            const item = data.data[0]["candle"];
            if (item && item.length >= 6) {
              this.updateKlineData({
                t: Number(item[0]) * 1000,
                o: parseFloat(item[1]),
                c: parseFloat(item[4]),
                h: parseFloat(item[2]),
                l: parseFloat(item[3]),
                v: parseFloat(item[5]),
              });
            }
          }
        }
      } catch (error) {
        console.error("WebSocket message error:", error);
      }
    },
    updateKlineData(kline) {
      const time = kline.t || kline.timestamp;
      const open = parseFloat(kline.o || kline.open);
      const close = parseFloat(kline.c || kline.close);
      const high = parseFloat(kline.h || kline.high);
      const low = parseFloat(kline.l || kline.low);
      // const vol = parseFloat(kline.v || kline.vol);
      this.newKline = {
        time: covertime(time),
        open: open.toFixed(2),
        close: close.toFixed(2),
        low: low.toFixed(2),
        high: high.toFixed(2),
      };

      if (this.klineData.length === 0) {
        this.klineData.push([time, open, close, low, high]);
      } else {
        const lastBar = this.klineData[this.klineData.length - 1];
        if (lastBar[0] === time) {
          // 更新最后一根K线
          this.klineData[this.klineData.length - 1] = [
            time,
            lastBar[1],
            close,
            low,
            high,
          ];
        } else {
          // 新增K线
          this.klineData.push([time, open, close, low, high]);
        }
      }

      // 只保留最近100条数据
      if (this.klineData.length > 100) {
        this.klineData.shift();
      }

      this.updateChart();
    },
    async loadHistoricalData() {
      let getKlineData = "";

      if (this.platform == 1) {
        const { fetchKline } = await import("@/utils/HUOBI");
        getKlineData = fetchKline;
      }
      if (this.platform == 2) {
        const { fetchKline } = await import("@/utils/BINANCE");
        getKlineData = fetchKline;
      }
      if (this.platform == 3) {
        const { fetchKline } = await import("@/utils/OKEX");
        getKlineData = fetchKline;
      }
      if (this.platform == 4) {
        const { fetchKline } = await import("@/utils/GATE");
        getKlineData = fetchKline;
      }
      if (this.platform == 5) {
        const { fetchKline } = await import("@/utils/MEXC");
        getKlineData = fetchKline;
      }
      if (this.platform == 8) {
        const { fetchKline } = await import("@/utils/KUCOIN");
        getKlineData = fetchKline;
      }
      if (this.platform == 15) {
        const { fetchKline } = await import("@/utils/BITGET");
        getKlineData = fetchKline;
      }
      if (this.platform == 16) {
        const { fetchKline } = await import("@/utils/BYBIT");
        getKlineData = fetchKline;
      }
      if (this.platform == 17) {
        const { fetchKline } = await import("@/utils/BITMART");
        getKlineData = fetchKline;
      }
      if (!getKlineData) {
        return;
      }

      getKlineData(this.currenyName + this.quoteName, this.interval).then(
        (data) => {
          if (data) {
            this.klineData = data;
            this.updateChart();
          }
        }
      );
    },
    // async loadHistoricalData() {
    //   try {
    //     this.loading = true;
    //     let klineUrl = "";

    //     if (this.isBuy) {
    //       klineUrl = getBuyKlineData;
    //     } else {
    //       klineUrl = getSellKlineData;
    //     }
    //     const res = await klineUrl({
    //       id: this.id,
    //     });
    //     if (res && res.data) {
    //       // 数据格式: [time, open, high, low, close]
    //       // ECharts candlestick 需要: [time, open, close, low, high]
    //       this.klineData = res.data.map((item) => [
    //         item.id.length == 10 ? time.id * 1000 : item.id, // 时间
    //         parseFloat(item.open), // 开盘价
    //         parseFloat(item.close), // 收盘价
    //         parseFloat(item.low), // 最低价
    //         parseFloat(item.high), // 最高价
    //       ]);
    //       for (const i in this.klineData) {
    //         console.log(covertime(this.klineData[i]["id"], "ymdhis"));
    //       }
    //       this.updateChart();
    //     }
    //   } catch (error) {
    //   } finally {
    //     this.loading = false;
    //   }
    // },
    updateChart() {
      if (!this.chart) return;

      // 获取最后一条数据用于markLine
      let lastClosePrice = 0;
      let isLastUp = true;

      if (this.klineData.length > 0) {
        const lastData = this.klineData[this.klineData.length - 1];
        lastClosePrice = lastData[2]; // 收盘价

        // 判断最后一条数据是否上涨
        if (this.klineData.length > 1) {
          const prevData = this.klineData[this.klineData.length - 2];
          isLastUp = lastData[2] >= prevData[2];
        } else {
          isLastUp = lastData[2] >= lastData[1];
        }
      }

      // 根据涨跌设置颜色
      const lineColor = isLastUp ? "#2dc08e" : "#f92855";
      const textColor = isLastUp ? "#2dc08e" : "#f92855";

      const option = {
        animation: false,
        backgroundColor: "#ffffff",
        textStyle: {
          fontFamily: "Arial, sans-serif",
        },
        grid: {
          left: "0%",
          right: "3%",
          top: "5%",
          bottom: "5%",
          containLabel: true, // 让 ECharts 自动再算一次，确保标签完整
          borderWidth: 1,
        },
        xAxis: {
          type: "time",
          scale: true,
          boundaryGap: false,
          axisLine: {
            lineStyle: {
              color: "#999",
              width: 1,
            },
          },
          axisTick: {
            show: false,
            lineStyle: {
              color: "#999",
            },
          },
          axisLabel: {
            color: "#666",
            fontSize: 11,
            formatter: (value) => {
              return this.formatTimeShort(value);
            },
          },
          splitLine: {
            show: true,
            lineStyle: {
              color: "#f0f0f0",
              type: "dashed",
            },
          },
        },
        yAxis: {
          type: "value",
          position: "right",
          scale: true,
          axisLine: {
            lineStyle: {
              color: "#999",
              width: 1,
            },
          },
          axisTick: {
            show: true,
            lineStyle: {
              color: "#999",
            },
          },
          axisLabel: {
            color: "#666",
            fontSize: 11,
            formatter: (value) => {
              return parseFloat(value);
            },
          },
          splitLine: {
            show: true,
            lineStyle: {
              color: "#f0f0f0",
              type: "solid",
            },
          },
        },
        // 数据缩放组件 - 关键配置
        dataZoom: [
          {
            type: "inside", // 内置型数据区域缩放（支持滚轮）
            xAxisIndex: [0], // 控制x轴
            zoomOnMouseWheel: true, // 启用滚轮缩放（默认true）
            moveOnMouseWheel: true, // 启用滚轮平移（默认true）
            moveOnMouseMove: true, // 启用鼠标拖拽平移
          },
        ],
        series: [
          {
            name: "蜡烛图",
            type: "candlestick",
            data: this.klineData,
            itemStyle: {
              color: "#2dc08e", // 上升绿色
              color0: "#f92855", // 下降红色
              borderColor: "#24a133",
              borderColor0: "#c1232b",
              borderWidth: 1,
            },

            markLine: {
              symbol: ["none", "none"],
              label: {
                fontSize: 11,
                formatter: `${lastClosePrice.toFixed(2)}`,
                color: "#fff",
                backgroundColor: lineColor,
                padding: [4, 8],
                borderRadius: 3,
              },
              lineStyle: {
                type: "dashed",
                color: lineColor,
                width: 1,
              },
              data: [
                {
                  yAxis: lastClosePrice,
                  name: "最新价",
                  lineStyle: { color: lineColor },
                  label: { color: "#fff", backgroundColor: lineColor },
                },
              ],
            },
          },
        ],
        tooltip: {
          trigger: "axis",
          axisPointer: {
            type: "cross",
            lineStyle: {
              color: "#ccc",
              width: 1,
            },
          },
          backgroundColor: "rgba(0, 0, 0, 0.8)",
          borderColor: "#555",
          borderWidth: 1,
          textStyle: {
            color: "#fff",
            fontSize: 12,
          },
          formatter: (params) => {
            if (params.length > 0) {
              const data = params[0].value;
              const timeStr = covertime(data[0]);
              const open = data[1].toFixed(2);
              const close = data[2].toFixed(2);
              const low = data[3].toFixed(2);
              const high = data[4].toFixed(2);

              this.newKline = {
                time: timeStr,
                open,
                close,
                low,
                high,
              };
              return `
                <div style="padding: 10px;  border-radius: 4px;">
                  <div style="font-weight: bold; margin-bottom: 6px; font-size: 13px; color: white;">${timeStr}</div>
                  <div style="line-height: 1.8; color: white;">
                    <div>开: <span style="color: #fff">${open}</span></div>
                    <div>高: <span style="color: #fff">${high}</span></div>
                    <div>低: <span style="color: #fff">${low}</span></div>
                    <div>收: <span style="color: #fff">${close}</span></div>

                  </div>
                </div>
              `;
            }
            return "";
          },
          hideDelay: 100,
        },
      };

      this.chart.setOption(option);
    },
    handleChartMouseLeave() {
      // 鼠标离开图表时，用最后一条数据填充newKline
      if (this.klineData.length > 0) {
        const lastData = this.klineData[this.klineData.length - 1];
        const timeStr = covertime(lastData[0]);
        const open = lastData[1].toFixed(2);
        const close = lastData[2].toFixed(2);
        const low = lastData[3].toFixed(2);
        const high = lastData[4].toFixed(2);
        this.newKline = {
          time: timeStr,
          open,
          close,
          low,
          high,
        };
      }
    },
    formatTimeShort(timestamp) {
      const date = new Date(timestamp);
      const hours = String(date.getHours()).padStart(2, "0");
      const minutes = String(date.getMinutes()).padStart(2, "0");
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const day = String(date.getDate()).padStart(2, "0");

      // 如果是新的一天就显示日期，否则只显示时间
      const now = new Date();
      if (date.toDateString() === now.toDateString()) {
        return `${hours}:${minutes}`;
      } else {
        return `${month}-${day}`;
      }
    },
    formatTime(timestamp) {
      const date = new Date(timestamp);
      return date.toLocaleString("zh-CN", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
      });
    },
    handleIntervalChange(data) {
      this.interval = data.value;
      // 时间间隔改变时，重新加载数据
      this.klineData = [];
      if (this.wsManager) {
        this.wsManager.disconnect();
      }
      this.loadHistoricalData();
      this.initWebSocket();
    },
    handleSymbolChange(newSymbol) {
      console.log("币种改变为:", newSymbol);
      // 重新初始化 WebSocket
      if (this.wsManager) {
        this.wsManager.disconnect();
      }
      this.klineData = [];
      this.loadHistoricalData();
      this.initWebSocket();
    },
    refreshData() {
      this.klineData = [];
      this.loadHistoricalData();
      this.$message.success("数据已刷新");
    },
  },
};
</script>

<style lang="scss" scoped>
.buy {
  color: #2dc08e;
}
.sell {
  color: #f92855;
}
.kline-container {
  width: 100%;
  height: 450px;
  display: flex;
  flex-direction: column;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  transition: all 0.3s ease;

  &.is-up {
    background: linear-gradient(
      135deg,
      rgba(47, 178, 93, 0.08) 0%,
      rgba(47, 178, 93, 0.04) 100%
    );
  }

  &.is-down {
    background: linear-gradient(
      135deg,
      rgba(230, 68, 58, 0.08) 0%,
      rgba(230, 68, 58, 0.04) 100%
    );
  }

  .kline-header {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 0 0 10px;

    .kline-title {
      font-size: 24px;
      font-weight: 600;
      letter-spacing: 0.5px;
    }

    .kline-controls {
      display: flex;
      align-items: center;
      gap: 12px;

      /deep/ .el-select {
        min-width: 130px;
      }

      /deep/ .el-button {
        padding: 7px 16px;
        font-size: 12px;
        border-radius: 4px;
        font-weight: 500;

        &:hover {
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
      }
    }
  }

  .kline-chart {
    height: 380px;
    flex: 1;
    width: 100%;
    background-color: #ffffff;
    border-radius: 4px;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
  }

  .kline-info {
    display: flex;
    gap: 30px;
    padding-top: 12px;
    font-size: 14px;
    margin-top: 0;
    border-radius: 0 0 4px 4px;

    .price-info {
      display: flex;
      align-items: center;
      color: #89919f;
      font-weight: 500;
    }
  }
}

// 响应式设计
@media (max-width: 768px) {
  .kline-container {
    padding: 10px;

    .kline-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 10px;

      .kline-controls {
        width: 100%;
        justify-content: space-between;
      }
    }

    .kline-info {
      flex-direction: column;
      gap: 8px;
    }
  }
}
</style>
