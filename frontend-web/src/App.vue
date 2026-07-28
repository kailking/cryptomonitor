<template>
  <div id="app">
    <router-view />
  </div>
</template>

<script>
export default {
  name: "App",
};
</script>
<style>
.el-table td {
  padding: 0;
}
.el-table .cell {
  line-height: inherit;
  padding: 5px;
}
body {
  font-family: Helvetica Neue, Helvetica, PingFang SC, Tahoma, Arial, sans-serif;
}
.el-table {
  color: #666;
}
.el-table thead {
  color: #666;
}
.el-table th {
  background: #fafafa;
  font-weight: 400;
  color: #666;
  padding: 8px 0;
}
.filter-container {
  display: flex; /* 开启 flex 布局 */
  flex-wrap: nowrap; /* 不换行（默认就是 nowrap，可省略） */
  overflow-x: auto; /* 超出显示横向滚动条 */
  align-items: center; /* 垂直居中 */
  gap: 10px; /* 元素间距 */
}
.filter-container > * {
  flex-shrink: 0 !important; /* 防止被压缩 */
}

.price-diff-red-link {
  font-size: 18px;
  color: red;
  font-weight: 600;
}
.price-diff-green-link {
  font-size: 18px;
  color: green;
  font-weight: 600;
}
.symbol-link {
  font-size: 18px;
  color: #009688;
  font-weight: 600;
}
.price_diff .notMobile-mt10 {
  margin-top: 0px;
}
.chain-red {
  color: red;
}
.chain-green {
  color: green;
}
@media (min-width: 768px) {
  .filter-container {
    display: block;
  }
  .notMobile-mt10 {
    margin-top: 10px;
  }
}
</style>
