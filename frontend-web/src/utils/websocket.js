/**
 * WebSocket connection manager
 */

import * as protobuf from "protobufjs";

const MEXC_FIELD_MAP = {
  channel: 1,
  symbol: 3,
  session_id: 4,
  start_time: 5,
  end_time: 11,
  version: 12
};

class WebSocketManager {
  constructor(url, options = {}) {
    this.url = url;
    this.ws = null;
    this.reconnectDelay = options.reconnectDelay || 5000;
    this.maxReconnectAttempts = options.maxReconnectAttempts || 10;
    this.reconnectAttempts = 0;
    this.reconnectTimer = null;
    this.listeners = {};
    this.isIntentionallyClosed = false;
  }

  /**
   * Connect WebSocket
   */
  connect() {
    return new Promise((resolve, reject) => {
      try {
        this.ws = new WebSocket(this.url);
        this.ws.binaryType = "arraybuffer";
        this.isIntentionallyClosed = false;

        this.ws.onopen = () => {
          console.log("WebSocket connected:", this.url);
          this.reconnectAttempts = 0;
          this.emit("open");
          resolve();
        };

        this.ws.onmessage = event => {
          this.decodeMessage(event.data)
            .then(payload => {
              if (payload && payload.text) {
                try {
                  const data = JSON.parse(payload.text);
                  this.emit("message", data);
                  return;
                } catch (error) {
                  console.warn("WebSocket message parse failed:", error);
                }
              }

              if (payload && payload.bytes) {
                const decoded = this.decodeMexcProtobuf(payload.bytes);
                if (decoded) {
                  this.emit("message", decoded);
                  return;
                }
              }

              this.emit(
                "message",
                payload && payload.text ? payload.text : event.data
              );
            })
            .catch(error => {
              console.warn("WebSocket message decode failed:", error);
              this.emit("message", event.data);
            });
        };

        this.ws.onerror = error => {
          console.error("WebSocket error:", error);
          this.emit("error", error);
          reject(error);
        };

        this.ws.onclose = () => {
          console.log("WebSocket closed");
          this.emit("close");

          this.scheduleReconnect();
        };
      } catch (error) {
        console.error("WebSocket create failed:", error);
        reject(error);
      }
    });
  }

  scheduleReconnect() {
    if (
      this.isIntentionallyClosed ||
      this.reconnectAttempts >= this.maxReconnectAttempts ||
      this.reconnectTimer
    ) {
      return;
    }
    this.reconnectAttempts += 1;
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      if (this.isIntentionallyClosed) return;
      this.connect().catch(error => this.emit("error", error));
    }, this.reconnectDelay);
  }

  decodeMessage(data) {
    if (typeof data === "string") {
      return Promise.resolve({ text: data, bytes: null });
    }

    if (data instanceof Blob) {
      return data.arrayBuffer().then(buffer => this.decodeArrayBuffer(buffer));
    }

    if (data instanceof ArrayBuffer) {
      return this.decodeArrayBuffer(data);
    }

    if (ArrayBuffer.isView(data)) {
      const bytes = new Uint8Array(
        data.buffer,
        data.byteOffset,
        data.byteLength
      );
      return this.decodeArrayBuffer(bytes.slice().buffer);
    }

    return Promise.resolve({ text: String(data), bytes: null });
  }

  decodeArrayBuffer(buffer) {
    const bytes = new Uint8Array(buffer);
    const isGzip = bytes.length >= 2 && bytes[0] === 0x1f && bytes[1] === 0x8b;

    const DecompressionStreamImpl = window.DecompressionStream;
    if (isGzip && typeof DecompressionStreamImpl !== "undefined") {
      const stream = new Blob([bytes])
        .stream()
        .pipeThrough(new DecompressionStreamImpl("gzip"));
      return new Response(stream).arrayBuffer().then(buf => {
        const outBytes = new Uint8Array(buf);
        const text = new TextDecoder("utf-8").decode(outBytes);
        return { text, bytes: outBytes };
      });
    }

    return Promise.resolve({
      text: new TextDecoder("utf-8").decode(bytes),
      bytes
    });
  }

  decodeMexcProtobuf(bytes) {
    const reader = protobuf.Reader.create(bytes);
    const raw = {};

    while (reader.pos < reader.len) {
      const tag = reader.uint32();
      const fieldNum = tag >>> 3;
      const wireType = tag & 7;
      let value;

      try {
        switch (wireType) {
          case 0:
            value = reader.uint64();
            break;
          case 1:
            value = reader.fixed64();
            break;
          case 2: {
            const buf = reader.bytes();
            const text = new TextDecoder("utf-8").decode(buf);
            value = this.isMostlyPrintable(text) ? text : buf;
            break;
          }
          case 5:
            value = reader.fixed32();
            break;
          default:
            reader.skipType(wireType);
            continue;
        }
      } catch (error) {
        reader.skipType(wireType);
        continue;
      }

      if (raw[fieldNum] === undefined) {
        raw[fieldNum] = value;
      } else if (Array.isArray(raw[fieldNum])) {
        raw[fieldNum].push(value);
      } else {
        raw[fieldNum] = [raw[fieldNum], value];
      }
    }

    if (Object.keys(raw).length === 0) {
      return null;
    }
    const nested = this.decodeMexcNested(raw[308]);
    const depthData = this.decodeDepthData(raw[303]); // 深度数据字段 303
    const tickerData = this.decodeTickerData(raw[309]); // 24h Ticker 数据

    const nestedValue = key =>
      this.normalizePbValue(nested ? nested[key] : undefined);
    const getField = name => {
      const val = raw[MEXC_FIELD_MAP[name]];
      return this.normalizePbValue(val);
    };

    return {
      _pb: true,
      raw,
      nested,
      depth: depthData, // 添加深度数据
      ticker: tickerData, // 24h Ticker 数据（新增）
      channel: getField("channel"),
      symbol: getField("symbol"),
      interval: nestedValue(1),
      open: nestedValue(3),
      close: nestedValue(4),
      high: nestedValue(5),
      low: nestedValue(6),
      volume: nestedValue(7),
      amount: nestedValue(8),
      start_time: nestedValue(2) || getField("start_time"),
      end_time: getField("end_time"),
      version: getField("version"),
      session_id: getField("session_id")
    };
  }
  /**
   * 解码 24h Ticker 数据 (MiniTicker / Ticker)
   * 对应 raw[309] 字段 - PublicMiniTickerV3Api / PublicTickerV3Api
   */
  decodeTickerData(bytes) {
    if (!bytes || !(bytes instanceof Uint8Array)) {
      return null;
    }

    const reader = protobuf.Reader.create(bytes);
    const fields = {};

    try {
      while (reader.pos < reader.len) {
        const tag = reader.uint32();
        const fieldNum = tag >>> 3;
        const wireType = tag & 7;

        try {
          if (wireType === 2) {
            const buf = reader.bytes();
            const text = new TextDecoder("utf-8").decode(buf);
            fields[fieldNum] = text;
          } else {
            reader.skipType(wireType);
          }
        } catch (e) {
          reader.skipType(wireType);
        }
      }
    } catch (error) {
      console.error("Parse error:", error);
    }
    const rate = parseFloat(Number(fields[3] * 100).toFixed(2)); // 原始小数，如 -0.0069
    const zonedRate = parseFloat(Number(fields[4] * 100).toFixed(2)); // 原始小数，如 -0.009
    // 根据文档的正确字段映射
    const ticker = {
      symbol: fields[1], // symbol: 交易对
      price: fields[2], // price: 最新价
      rate: rate, // rate: UTC+8涨跌幅%
      zonedRate: zonedRate, // zonedRate: 本地时区涨跌幅%
      high: fields[5], // high: 最高价
      low: fields[6], // low: 最低价
      volume: fields[7], // volume: 成交额
      quantity: fields[8], // quantity: 成交量
      lastCloseRate: fields[9], // lastCloseRate: 昨收涨跌幅(UTC+8)
      lastCloseZonedRate: fields[10], // lastCloseZonedRate: 昨收涨跌幅(本地)
      lastCloseHigh: fields[11], // lastCloseHigh: 昨收最高价
      lastCloseLow: fields[12], // lastCloseLow: 昨收最低价
      ts: fields[13] // 时间戳（可能）
    };

    return {
      symbol: ticker.symbol,
      price: ticker.price,
      high24h: parseFloat(ticker.high),
      low24h: parseFloat(ticker.low),
      volume24h: parseFloat(ticker.volume), // 成交额
      quantity24h: parseFloat(ticker.quantity), // 成交量

      // 涨跌幅 - 直接用服务器返回的，不需要计算
      changePercent: rate, // UTC+8时区涨跌幅
      zonedChangePercent: parseFloat(ticker.zonedRate), // 本地时区涨跌幅

      isUp: rate >= 0,
      ts: parseInt(ticker.ts),
      _fields: fields
    };
  }
  /**
   * 解码深度数据 (PublicAggreDepthsV3Api)
   * 对应 raw[303] 字段
   */
  decodeDepthData(bytes) {
    if (!bytes || !(bytes instanceof Uint8Array)) {
      return null;
    }

    const reader = protobuf.Reader.create(bytes);
    const depth = {
      asks: [],
      bids: [],
      eventType: null,
      fromVersion: null,
      toVersion: null
    };

    try {
      while (reader.pos < reader.len) {
        const tag = reader.uint32();
        const fieldNum = tag >>> 3;
        const wireType = tag & 7;

        try {
          switch (fieldNum) {
            case 1: // asks - repeated PublicAggreDepthV3ApiItem
              if (wireType === 2) {
                const itemBytes = reader.bytes();
                const item = this.decodeDepthItem(itemBytes);
                if (item) depth.asks.push(item);
              } else {
                reader.skipType(wireType);
              }
              break;

            case 2: // bids - repeated PublicAggreDepthV3ApiItem
              if (wireType === 2) {
                const itemBytes = reader.bytes();
                const item = this.decodeDepthItem(itemBytes);
                if (item) depth.bids.push(item);
              } else {
                reader.skipType(wireType);
              }
              break;

            case 3: // eventType - string
              if (wireType === 2) {
                const buf = reader.bytes();
                depth.eventType = new TextDecoder("utf-8").decode(buf);
              } else {
                reader.skipType(wireType);
              }
              break;

            case 4: // fromVersion - string
              if (wireType === 2) {
                const buf = reader.bytes();
                depth.fromVersion = new TextDecoder("utf-8").decode(buf);
              } else {
                reader.skipType(wireType);
              }
              break;

            case 5: // toVersion - string
              if (wireType === 2) {
                const buf = reader.bytes();
                depth.toVersion = new TextDecoder("utf-8").decode(buf);
              } else {
                reader.skipType(wireType);
              }
              break;

            default:
              reader.skipType(wireType);
              break;
          }
        } catch (e) {
          reader.skipType(wireType);
        }
      }
    } catch (error) {
      console.error("Depth data decode error:", error);
      return null;
    }

    // 如果没有解析到数据，返回 null
    if (
      depth.asks.length === 0 &&
      depth.bids.length === 0 &&
      !depth.eventType
    ) {
      return null;
    }

    return depth;
  }

  /**
   * 解码单个深度档位 (PublicAggreDepthV3ApiItem)
   * 结构: message { string price = 1; string quantity = 2; }
   */
  decodeDepthItem(bytes) {
    if (!bytes || !(bytes instanceof Uint8Array)) {
      return null;
    }

    const reader = protobuf.Reader.create(bytes);
    const item = {
      price: null,
      amount: null
    };

    try {
      while (reader.pos < reader.len) {
        const tag = reader.uint32();
        const fieldNum = tag >>> 3;
        const wireType = tag & 7;

        try {
          if (wireType === 2) {
            // Length-delimited (string/bytes)
            const buf = reader.bytes();
            const text = new TextDecoder("utf-8").decode(buf);

            if (fieldNum === 1) {
              item.price = text;
            } else if (fieldNum === 2) {
              item.amount = text;
            }
          } else {
            reader.skipType(wireType);
          }
        } catch (e) {
          reader.skipType(wireType);
        }
      }
    } catch (error) {
      return null;
    }

    // 验证必要字段
    if (item.price === null || item.amount === null) {
      return null;
    }

    return item;
  }
  decodeMexcNested(value) {
    if (!value || !(value instanceof Uint8Array)) {
      return null;
    }
    const reader = protobuf.Reader.create(value);
    const raw = {};

    while (reader.pos < reader.len) {
      const tag = reader.uint32();
      const fieldNum = tag >>> 3;
      const wireType = tag & 7;
      let val;

      try {
        switch (wireType) {
          case 0:
            val = reader.uint64();
            break;
          case 1:
            val = reader.fixed64();
            break;
          case 2: {
            const buf = reader.bytes();
            const text = new TextDecoder("utf-8").decode(buf);
            val = this.isMostlyPrintable(text) ? text : buf;
            break;
          }
          case 5:
            val = reader.fixed32();
            break;
          default:
            reader.skipType(wireType);
            continue;
        }
      } catch (error) {
        reader.skipType(wireType);
        continue;
      }

      raw[fieldNum] = val;
    }

    return raw;
  }

  normalizePbValue(value) {
    if (value === undefined || value === null) {
      return null;
    }
    if (Array.isArray(value)) {
      return value.map(item => this.normalizePbValue(item));
    }
    if (typeof value === "object" && typeof value.toString === "function") {
      const asString = value.toString();
      const asNumber = Number(asString);
      return Number.isNaN(asNumber) ? asString : asNumber;
    }
    return value;
  }

  isMostlyPrintable(text) {
    if (!text) return false;
    let printable = 0;
    let control = 0;
    for (let i = 0; i < text.length; i++) {
      const code = text.charCodeAt(i);
      if (code === 9 || code === 10 || code === 13) {
        printable += 1;
      } else if (code >= 32 && code <= 126) {
        printable += 1;
      } else {
        control += 1;
      }
    }
    if (control > 0) {
      return false;
    }
    return printable / text.length > 0.9;
  }

  /**
   * Send message
   */
  send(data) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(typeof data === "string" ? data : JSON.stringify(data));
      return true;
    }

    console.warn("WebSocket not connected, send failed");
    return false;
  }

  /**
   * Subscribe event
   */
  on(event, callback) {
    if (!this.listeners[event]) {
      this.listeners[event] = [];
    }
    this.listeners[event].push(callback);
  }

  /**
   * Unsubscribe event
   */
  off(event, callback) {
    if (this.listeners[event]) {
      this.listeners[event] = this.listeners[event].filter(
        cb => cb !== callback
      );
    }
  }

  /**
   * Emit event
   */
  emit(event, data) {
    if (this.listeners[event]) {
      this.listeners[event].forEach(callback => {
        try {
          callback(data);
        } catch (error) {
          console.error(`Event handler error (${event}):`, error);
        }
      });
    }
  }

  /**
   * Disconnect WebSocket
   */
  disconnect() {
    this.isIntentionallyClosed = true;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }
  }

  /**
   * Get connection status
   */
  isConnected() {
    return this.ws && this.ws.readyState === WebSocket.OPEN;
  }
}

/**
 * Create custom WebSocket manager
 * @param {string} url
 * @param {object} options
 * @returns {WebSocketManager}
 */
export function createWebSocket(url, options = {}) {
  return new WebSocketManager(url, options);
}

export default WebSocketManager;
