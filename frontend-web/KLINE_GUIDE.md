# K 线图组件使用指南

## 概述

这是一个基于 ECharts 的 Vue K 线图组件，支持 WebSocket 实时数据更新，可以连接 Binance 等多个数据源。

## 功能特性

- ✅ 支持蜡烛图（K 线图）展示
- ✅ WebSocket 实时数据推送
- ✅ 支持 Binance 官方 WebSocket 接口
- ✅ 自定义 WebSocket URL 支持
- ✅ 多个时间周期切换（1 分钟、5 分钟、15 分钟、1 小时、4 小时、1 天）
- ✅ 自动重连机制
- ✅ 实时价格显示（最新价、最高价、最低价）
- ✅ 响应式布局

## 安装依赖

```bash
npm install echarts
```

## 基础使用

### 方式一：使用 Binance WebSocket（推荐）

```vue
<template>
  <div>
    <KlineChart symbol="BTC/USDT" :use-binance-ws="true" />
  </div>
</template>

<script>
import KlineChart from "@/components/kline";

export default {
  components: {
    KlineChart
  }
};
</script>
```

### 方式二：使用自定义 WebSocket

```vue
<template>
  <div>
    <KlineChart
      symbol="BTC/USDT"
      websocket-url="wss://your-websocket-server.com/kline"
      :use-binance-ws="false"
    />
  </div>
</template>

<script>
import KlineChart from "@/components/kline";

export default {
  components: {
    KlineChart
  }
};
</script>
```

### 方式三：完整配置示例

```vue
<template>
  <div>
    <KlineChart
      symbol="BTC/USDT"
      :use-binance-ws="true"
      :auto-load-history="true"
    />
  </div>
</template>

<script>
import KlineChart from "@/components/kline";

export default {
  components: {
    KlineChart
  }
};
</script>
```

## Props 属性说明

| 属性            | 类型    | 默认值     | 说明                                       |
| --------------- | ------- | ---------- | ------------------------------------------ |
| symbol          | String  | 'BTC/USDT' | 交易对，格式为 'SYMBOL/USDT'               |
| websocketUrl    | String  | null       | 自定义 WebSocket URL，如果设置则使用此 URL |
| useBinanceWs    | Boolean | true       | 是否使用 Binance WebSocket 接口            |
| autoLoadHistory | Boolean | true       | 是否自动加载历史数据                       |

## 支持的交易对（Binance）

- BTC/USDT
- ETH/USDT
- BNB/USDT
- XRP/USDT
- ADA/USDT
- DOGE/USDT
- SOL/USDT
- AVAX/USDT
- 及其他 Binance 支持的交易对

## 支持的时间周期

- 1m（1 分钟）
- 5m（5 分钟）
- 15m（15 分钟）
- 1h（1 小时）- 默认
- 4h（4 小时）
- 1d（1 天）

## Binance WebSocket 数据格式

```json
{
  "e": "kline",
  "E": 123456789,
  "s": "BTCUSDT",
  "k": {
    "t": 1234567890000,
    "T": 1234567900000,
    "s": "BTCUSDT",
    "i": "1h",
    "f": 100,
    "L": 200,
    "o": "0.0010",
    "c": "0.0020",
    "h": "0.0025",
    "l": "0.0015",
    "v": "1000",
    "n": 100,
    "x": false,
    "q": "1.0000",
    "V": "500",
    "Q": "0.5000",
    "B": "123456"
  }
}
```

## 自定义 WebSocket 数据格式

如果使用自定义 WebSocket，数据格式应为：

```json
{
  "type": "kline",
  "data": {
    "timestamp": 1234567890000,
    "open": "0.0010",
    "close": "0.0020",
    "high": "0.0025",
    "low": "0.0015"
  }
}
```

或者使用 Binance 格式：

```json
{
  "k": {
    "t": 1234567890000,
    "o": "0.0010",
    "c": "0.0020",
    "h": "0.0025",
    "l": "0.0015"
  }
}
```

## WebSocket 管理器 (WebSocketManager)

### 创建连接

```javascript
import {
  createWebSocket,
  createBinanceKlineWebSocket
} from "@/utils/websocket";

// 使用 Binance WebSocket
const ws = createBinanceKlineWebSocket("btcusdt", "1h");

// 或使用自定义 URL
const ws = createWebSocket("wss://your-server.com/kline");
```

### 连接/断开

```javascript
// 建立连接
ws.connect();

// 监听连接成功
ws.on("open", () => {
  console.log("连接成功");
});

// 监听消息
ws.on("message", data => {
  console.log("收到数据:", data);
});

// 监听错误
ws.on("error", error => {
  console.error("连接错误:", error);
});

// 断开连接
ws.disconnect();
```

### 发送消息

```javascript
// 发送 JSON 消息
ws.send({ action: "subscribe", channel: "kline" });

// 发送字符串消息
ws.send("subscribe to kline");
```

## API 接口

组件支持从后端 API 加载历史数据。需要在 `src/api/kline.js` 中实现以下接口：

### 获取 K 线数据

```javascript
GET /api/kline?symbol=BTC/USDT&interval=1h&limit=100
```

响应格式：

```json
{
  "data": [
    [timestamp, open, close, high, low],
    [timestamp, open, close, high, low]
  ]
}
```

## 整合到路由

在 `src/router/index.js` 中添加路由：

```javascript
{
  path: '/kline',
  component: () => import('@/views/kline'),
  meta: { title: 'K线图', icon: 'chart', roles: ['admin', 'editor'] }
}
```

## 常见问题

### Q: 为什么看不到数据？

A:

1. 检查网络连接和 WebSocket 服务器
2. 确认交易对名称正确（如 'BTC/USDT'）
3. 检查浏览器控制台是否有错误信息
4. 若使用自定义 WebSocket，确保数据格式正确

### Q: 如何切换不同的币种？

A: 修改 `symbol` 属性：

```vue
<KlineChart :symbol="selectedSymbol" />
```

### Q: 如何获取实时价格？

A: 组件自动在 `lastPrice`、`highPrice`、`lowPrice` 中更新实时数据，显示在图表下方

### Q: 支持多个币种同时显示吗？

A: 当前组件只支持单个币种，可以并排放置多个组件显示不同币种

## 性能优化建议

1. 限制数据条数：组件默认只保留最近 500 条数据
2. 使用较长的时间周期：减少数据更新频率
3. 禁用动画：`animation: false` 已在代码中设置
4. 使用 Canvas 渲染：比 SVG 性能更好

## 浏览器兼容性

- Chrome/Edge: 完全支持
- Firefox: 完全支持
- Safari: 完全支持
- IE 11: 不支持（需要 polyfill）

## 许可证

MIT
