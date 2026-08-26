<template>
  <section class="source-strip" aria-label="五家交易所雷达来源状态">
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
        <span :class="`is-${meta(source.market_state).tone}`">市场 {{ meta(source.market_state).label }}</span>
        <span :class="`is-${meta(source.announcement_state).tone}`">公告 {{ meta(source.announcement_state).label }}</span>
        <span :class="`is-${meta(source.localization_state).tone}`">中文 {{ meta(source.localization_state).label }}</span>
      </div>
      <small>最近市场成功 {{ formatTime(source.market_last_success_at_ms) }}</small>
    </article>
  </section>
</template>

<script>
import {
  PLATFORM_NAMES,
  formatListingTime,
  platformName,
  sourceHealthMeta
} from "@/utils/spotListingDiscovery";

const IDS = [2, 3, 4, 5, 8];

export default {
  name: "SpotListingSourceHealthStrip",
  props: {
    sources: {
      type: Array,
      default: () => []
    }
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
    }
  },
  methods: {
    meta: sourceHealthMeta,
    formatTime: formatListingTime,
    platform(source) {
      return platformName(source.platform_id, source.platform_text);
    },
    overall(source) {
      return sourceHealthMeta(source.state);
    }
  }
};
</script>

<style lang="scss" scoped>
.source-strip {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  border: 1px solid #1c3947;
  border-radius: 6px;
  background: #09131d;

  &__item {
    min-width: 0;
    padding: 12px 14px;
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
    font-size: 13px;
  }

  &__topline > span {
    color: #8da5b4;
    font-size: 10px;
  }

  &__channels {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 8px;
    margin-top: 8px;
    font-size: 9px;
  }

  &__channels span { color: #6f8797; }
  &__channels .is-green { color: #29e59d; }
  &__channels .is-amber { color: #ffc857; }
  &__channels .is-red { color: #ff657b; }

  small {
    display: block;
    overflow: hidden;
    margin-top: 7px;
    color: #4f6878;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 9px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

@media (max-width: 1100px) {
  .source-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));

    &__item + &__item {
      border-top: 1px solid #1c3947;
    }
  }
}

@media (max-width: 620px) {
  .source-strip { grid-template-columns: 1fr; }
}
</style>
