<template>
  <span class="market-volume-cell">
    <span v-if="display === emptyText">{{ emptyText }}</span>
    <el-tooltip
      v-else-if="mode === 'amount'"
      :content="exact"
      placement="top"
    >
      <span>{{ display }}</span>
    </el-tooltip>
    <span v-else>{{ display }}</span>
  </span>
</template>

<script>
import {
  MARKET_VOLUME_EMPTY_TEXT,
  getMarketVolumeDisplay,
  getMarketVolumeExact,
  getMarketVolumeTimeDisplay,
} from "@/utils/marketVolume";

export default {
  name: "MarketVolumeCell",
  props: {
    row: {
      type: Object,
      required: true,
    },
    valueKey: {
      type: String,
      required: true,
    },
    timestampKey: {
      type: String,
      required: true,
    },
    mode: {
      type: String,
      default: "amount",
      validator(value) {
        return ["amount", "time"].includes(value);
      },
    },
  },
  data() {
    return {
      emptyText: MARKET_VOLUME_EMPTY_TEXT,
    };
  },
  computed: {
    display() {
      if (this.mode === "time") {
        return getMarketVolumeTimeDisplay(
          this.row,
          this.valueKey,
          this.timestampKey
        );
      }
      return getMarketVolumeDisplay(
        this.row,
        this.valueKey,
        this.timestampKey
      );
    },
    exact() {
      return getMarketVolumeExact(
        this.row,
        this.valueKey,
        this.timestampKey
      );
    },
  },
};
</script>
