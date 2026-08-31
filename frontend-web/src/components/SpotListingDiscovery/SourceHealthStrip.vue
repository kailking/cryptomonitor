<template>
  <section
    class="source-strip"
    :class="{ 'is-snapshot': snapshotNotice }"
    aria-label="五家交易所雷达来源状态"
  >
    <button
      type="button"
      class="source-strip__summary"
      :aria-expanded="detailsVisible ? 'true' : 'false'"
      aria-controls="spot-listing-source-details"
      @click="expanded = !expanded"
    >
      <span class="source-strip__summary-title">
        <i class="el-icon-connection" aria-hidden="true" />
        <span>
          <strong>数据源状态</strong>
          <small>{{ summaryLine }}</small>
        </span>
      </span>
      <span :class="`source-strip__summary-state is-${summaryMeta.tone}`">
        {{ summaryMeta.label }}
      </span>
      <i
        :class="detailsVisible ? 'el-icon-arrow-up' : 'el-icon-arrow-down'"
        aria-hidden="true"
      />
    </button>

    <div v-if="detailsVisible" id="spot-listing-source-details" class="source-strip__details">
      <div v-if="snapshotNotice" class="source-strip__notice" role="status">
        <i class="el-icon-warning-outline" aria-hidden="true" />
        {{ snapshotNotice }}
      </div>
      <div class="source-strip__grid">
        <article
          v-for="source in normalizedSources"
          :key="source.platform_id"
          class="source-strip__item"
          :class="`is-${overall(source).tone}`"
        >
          <div class="source-strip__topline">
            <strong>{{ platform(source) }}</strong>
            <span>{{ overall(source).label }}</span>
          </div>
          <div class="source-strip__channels">
            <span :class="`is-${statusMeta(source.market_state).tone}`">现货市场 {{ statusMeta(source.market_state).label }}</span>
            <span :class="`is-${statusMeta(source.announcement_state).tone}`">上币公告 {{ statusMeta(source.announcement_state).label }}</span>
            <span :class="`is-${statusMeta(source.localization_state).tone}`">中文公告 {{ statusMeta(source.localization_state).label }}</span>
          </div>
          <small>现货市场最近扫描 {{ formatTime(source.market_last_success_at_ms) }}</small>
          <div
            v-if="specialFor(source).length"
            class="source-strip__special"
            aria-label="特殊市场扫描状态"
          >
            <div
              v-for="channel in specialFor(source)"
              :key="`${channel.platform_id}:${channel.listing_channel}`"
            >
              <listing-channel-badge :value="channel" compact />
              <span :class="`is-${statusMeta(channel.state).tone}`">
                {{ statusMeta(channel.state).label }}
              </span>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>
</template>

<script>
import {
  PLATFORM_NAMES,
  formatListingTime,
  platformName,
  sourceHealthMeta
} from "@/utils/spotListingDiscovery";
import ListingChannelBadge from "./ListingChannelBadge.vue";

const IDS = [2, 3, 4, 5, 8];

export default {
  name: "SpotListingSourceHealthStrip",
  components: { ListingChannelBadge },
  props: {
    sources: {
      type: Array,
      default: () => []
    },
    channelSources: {
      type: Array,
      default: () => []
    },
    unavailable: Boolean,
    stale: Boolean
  },
  data() {
    return { expanded: false };
  },
  computed: {
    normalizedSources() {
      const indexed = this.sources.reduce((result, item) => {
        result[item.platform_id] = item;
        return result;
      }, {});
      return IDS.map(platformId =>
        indexed[platformId] || {
          platform_id: platformId,
          platform_text: PLATFORM_NAMES[platformId],
          state: "initializing",
          market_state: "unknown",
          announcement_state: "unknown",
          localization_state: "unknown",
          market_last_success_at_ms: null
        }
      );
    },
    snapshotNotice() {
      if (!this.unavailable && !this.stale) return "";
      const prefix = this.sources.length
        ? "上次有效状态"
        : "来源状态暂不可用";
      if (this.unavailable && this.stale) {
        return `${prefix} · 更新失败且数据已过期`;
      }
      return `${prefix} · ${this.unavailable ? "更新失败" : "数据已过期"}`;
    },
    healthyPlatformCount() {
      return this.normalizedSources.filter(source => source.state === "healthy").length;
    },
    healthyChannelCount() {
      return this.channelSources.filter(source => source.state === "healthy").length;
    },
    latestSuccessfulAt() {
      return this.normalizedSources.reduce((latest, source) => {
        return Math.max(
          latest,
          source.market_last_success_at_ms || 0,
          source.announcement_last_success_at_ms || 0,
          source.localization_last_success_at_ms || 0
        );
      }, 0);
    },
    summaryLine() {
      const channelCoverage = this.channelSources.length
        ? `${this.healthyChannelCount}/${this.channelSources.length} 个专区正常`
        : "专区状态接入中";
      return `${this.healthyPlatformCount}/5 家交易所正常 · ${channelCoverage} · 最近完成 ${this.formatTime(this.latestSuccessfulAt)}`;
    },
    summaryMeta() {
      if (this.snapshotNotice) {
        return { label: "数据异常", tone: "amber" };
      }
      const sourceStates = this.normalizedSources.map(source => source.state);
      const channelStates = this.channelSources.map(source => source.state);
      if (
        sourceStates.some(state => ["degraded", "stale"].includes(state)) ||
        channelStates.some(state => ["degraded", "stale"].includes(state))
      ) {
        return { label: "部分异常", tone: "amber" };
      }
      if (
        sourceStates.every(state => state === "healthy") &&
        this.channelSources.length > 0 &&
        channelStates.every(state => state === "healthy")
      ) {
        return { label: "扫描正常", tone: "green" };
      }
      return { label: "正在接入", tone: "cyan" };
    },
    detailsVisible() {
      return this.expanded;
    }
  },
  methods: {
    formatTime: formatListingTime,
    platform(source) {
      return platformName(source.platform_id, source.platform_text);
    },
    overall(source) {
      return this.statusMeta(source.state);
    },
    statusMeta(state) {
      const current = sourceHealthMeta(state);
      if (!this.snapshotNotice || !this.sources.length) return current;
      return {
        label: `上次${current.label}`,
        tone: "amber"
      };
    },
    specialFor(source) {
      return this.channelSources.filter(
        item => item.platform_id === source.platform_id
      );
    }
  }
};
</script>

<style lang="scss" scoped>
.source-strip {
  overflow: hidden;
  border: 1px solid #1c3947;
  border-radius: 6px;
  background: #09131d;

  &__summary {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto 18px;
    align-items: center;
    gap: 14px;
    width: 100%;
    min-height: 58px;
    padding: 8px 16px;
    border: 0;
    color: inherit;
    background: #0a1721;
    text-align: left;
    cursor: pointer;
  }

  &__summary:hover,
  &__summary:focus-visible { background: #0e202c; outline: none; }

  &__summary-title {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
  }
  &__summary-title > i { color: #35dcff; font-size: 20px; }
  &__summary-title strong { display: block; color: #edf8fc; font-size: 15px; }
  &__summary-title small {
    display: block;
    overflow: hidden;
    margin-top: 3px;
    color: #7894a3;
    font-size: 12px;
    line-height: 1.4;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__summary-state {
    color: #7894a3;
    font-size: 13px;
    font-weight: 700;
  }
  &__summary-state.is-green { color: #29e59d; }
  &__summary-state.is-cyan { color: #35dcff; }
  &__summary-state.is-amber { color: #ffc857; }

  &__details { border-top: 1px solid #1c3947; }
  &__grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
  }

  &__notice {
    padding: 8px 14px;
    border-bottom: 1px solid rgba(255, 200, 87, 0.45);
    color: #ffc857;
    background: rgba(255, 200, 87, 0.08);
    font-size: 12px;
    line-height: 1.4;
    letter-spacing: 0.04em;
  }

  &__notice i { margin-right: 5px; }

  &__item {
    min-width: 0;
    padding: 15px 16px;
    border-left: 2px solid #476171;
  }

  &__item + &__item {
    border-top: 0;
    border-left-width: 1px;
  }

  &__item.is-green { border-left-color: #29e59d; }
  &__item.is-cyan { border-left-color: #35dcff; }
  &__item.is-amber { border-left-color: #ffc857; }
  &__item.is-red { border-left-color: #ff657b; }

  &__topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }

  strong {
    color: #e9f7ff;
    font-size: 17px;
    line-height: 1.3;
  }

  &__topline > span {
    color: #a5bbc6;
    font-size: 13px;
    line-height: 1.35;
  }

  &__channels {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 10px;
    margin-top: 10px;
    font-size: 13px;
    line-height: 1.45;
  }

  &__channels span { color: #6f8797; }
  &__channels .is-green { color: #29e59d; }
  &__channels .is-amber { color: #ffc857; }
  &__channels .is-red { color: #ff657b; }

  small {
    display: block;
    overflow: hidden;
    margin-top: 8px;
    color: #6f8998;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 12px;
    line-height: 1.4;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__special {
    display: grid;
    gap: 7px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid rgba(53, 220, 255, 0.12);
  }

  &__special > div {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 7px;
  }

  &__special > div > span {
    flex: 0 0 auto;
    color: #6f8797;
    font-size: 12px;
    line-height: 1.35;
  }

  &__special > div > span.is-green { color: #29e59d; }
  &__special > div > span.is-cyan { color: #35dcff; }
  &__special > div > span.is-amber { color: #ffc857; }
  &__special > div > span.is-red { color: #ff657b; }

  ::v-deep {
    .listing-channel.is-compact b,
    .listing-channel.is-compact strong,
    .listing-channel.is-compact em {
      min-height: 23px;
      padding: 2px 6px;
      font-size: 12px;
    }
  }
}

@media (max-width: 1100px) {
  .source-strip__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .source-strip__item + .source-strip__item { border-top: 1px solid #1c3947; }
}

@media (max-width: 620px) {
  .source-strip__summary { grid-template-columns: minmax(0, 1fr) 18px; }
  .source-strip__summary-state { display: none; }
  .source-strip__grid { grid-template-columns: 1fr; }
}
</style>
