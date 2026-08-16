<style scoped>
.lr-flex {
  display: flex;
  flex-direction: row;
  justify-content: space-between;
}
.market-change-window-bar {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}
.market-change-window-label {
  margin-right: 10px;
  color: #303133;
  font-weight: 600;
}
.market-change-window-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
.lft,
.lfr {
  width: 49.5%;
}
@media (max-width: 768px) {
  .market-change-window-bar {
    align-items: flex-start;
    flex-direction: column;
  }
  .market-change-window-tip {
    margin-top: 8px;
    margin-left: 0;
  }
  .lr-flex {
    display: block;
  }
  .lft {
    margin-bottom: 20px;
  }
  .lft,
  .lfr {
    width: 100%;
  }
}
.app-container {
  padding-bottom: 0;
}
</style>
<template>
  <div class="app-container">
    <div class="market-change-window-bar" aria-label="极端行情时间窗口">
      <span class="market-change-window-label">行情时间窗口</span>
      <el-radio-group
        v-model="windowSeconds"
        size="small"
        @change="handleWindowSecondsChange"
      >
        <el-radio-button :label="30">30秒</el-radio-button>
        <el-radio-button :label="300">5分钟</el-radio-button>
      </el-radio-group>
      <span class="market-change-window-tip">
        此处控制涨跌统计窗口，与两侧“自动刷新”频率无关
      </span>
    </div>
    <div class="lr-flex">
      <Left class="lft" :window-seconds="windowSeconds" />
      <Right class="lfr" :window-seconds="windowSeconds" />
    </div>
  </div>
</template>
<script>
import Left from "./left.vue";
import Right from "./right.vue";
import {
  MARKET_CHANGE_WINDOW_SECONDS,
  normalizeMarketChangeWindowSeconds,
} from "@/utils/marketChangeWindow";

export default {
  components: {
    Left,
    Right,
  },
  data() {
    return {
      windowSeconds: MARKET_CHANGE_WINDOW_SECONDS.LONG,
    };
  },
  methods: {
    handleWindowSecondsChange(value) {
      this.windowSeconds = normalizeMarketChangeWindowSeconds(value);
    },
  },
};
</script>
