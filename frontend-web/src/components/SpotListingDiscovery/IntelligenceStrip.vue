<template>
  <section class="intelligence-strip" aria-label="上币情报">
    <header>
      <i class="el-icon-bell" aria-hidden="true" />
      <div>
        <strong>上币情报带</strong>
        <span>官方公告与市场直接发现并行，任一来源都可生成雷达任务</span>
      </div>
    </header>

    <div class="intelligence-strip__column">
      <h2>官方公告</h2>
      <button
        v-for="item in visibleAnnouncements"
        :key="`announcement-${item.announcement_event_id || item.id}`"
        type="button"
        @click="$emit('announcement', item.announcement_event_id || item.id)"
      >
        <b>{{ platform(item) }}</b>
        <span>{{ item.title || "官方现货上币公告" }}</span>
        <time>{{ formatTime(item.published_at_ms) }}</time>
      </button>
      <p v-if="!visibleAnnouncements.length">{{ announcementUnavailable ? "公告来源暂时异常，自动重试中" : "当前窗口暂无新公告" }}</p>
    </div>

    <div class="intelligence-strip__column">
      <h2>市场直接发现</h2>
      <button
        v-for="item in silentDiscoveries"
        :key="`market-${item.operation_key}`"
        type="button"
        @click="$emit('select', item.operation_key)"
      >
        <b>{{ platform(item) }}</b>
        <span>{{ item.symbol }} 已出现在交易所现货市场</span>
        <time>{{ formatTime(item.first_seen_at_ms) }}</time>
      </button>
      <p v-if="!silentDiscoveries.length">当前窗口暂无无公告交易对</p>
    </div>
  </section>
</template>

<script>
import {
  formatListingTime,
  platformName
} from "@/utils/spotListingDiscovery";

export default {
  name: "SpotListingIntelligenceStrip",
  props: {
    announcements: {
      type: Array,
      default: () => []
    },
    operations: {
      type: Array,
      default: () => []
    },
    announcementUnavailable: Boolean
  },
  computed: {
    visibleAnnouncements() {
      return this.announcements.slice(0, 3);
    },
    silentDiscoveries() {
      return this.operations
        .filter(item => item.instrument_id && !item.announcement_event_id)
        .slice(0, 3);
    }
  },
  methods: {
    formatTime: formatListingTime,
    platform(item) {
      return platformName(item.platform_id, item.platform_text);
    }
  }
};
</script>

<style lang="scss" scoped>
.intelligence-strip {
  display: grid;
  grid-template-columns: 210px minmax(0, 1fr) minmax(0, 1fr);
  overflow: hidden;
  border: 1px solid #204457;
  border-radius: 7px;
  background: #09131f;

  > header {
    display: flex;
    align-items: center;
    gap: 13px;
    min-height: 160px;
    padding: 18px;
    border-right: 1px solid #204457;
  }
  > header i { color: #35dcff; font-size: 27px; }
  > header strong { display: block; color: #e9f5fa; font-size: 15px; }
  > header span { display: block; margin-top: 7px; color: #577383; font-size: 9px; line-height: 1.6; }

  &__column { min-width: 0; padding: 13px 14px; }
  &__column + &__column { border-left: 1px solid #183641; }
  h2 { margin: 0 0 7px; color: #6f8998; font-size: 10px; font-weight: 500; letter-spacing: 0.08em; }

  button {
    display: grid;
    grid-template-columns: 54px minmax(0, 1fr) auto;
    gap: 8px;
    width: 100%;
    min-height: 36px;
    padding: 7px 4px;
    border: 0;
    border-top: 1px solid #162f3b;
    color: inherit;
    background: transparent;
    text-align: left;
    cursor: pointer;
  }
  button:hover,
  button:focus-visible { background: #0e202d; outline: none; }
  button b { overflow: hidden; color: #35dcff; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
  button span { overflow: hidden; color: #a4b5bf; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
  button time { color: #4d6878; font-family: Consolas, monospace; font-size: 8px; white-space: nowrap; }
  p { margin: 20px 4px; color: #506a79; font-size: 10px; }
}

@media (max-width: 1050px) {
  .intelligence-strip { grid-template-columns: 1fr; }
  .intelligence-strip > header { min-height: auto; border-right: 0; border-bottom: 1px solid #204457; }
  .intelligence-strip__column + .intelligence-strip__column { border-top: 1px solid #183641; border-left: 0; }
}
</style>
