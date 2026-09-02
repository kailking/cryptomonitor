<template>
  <section class="discovery-queue" aria-labelledby="discovery-queue-title">
    <header>
      <div>
        <span>已确认开盘任务</span>
        <h2 id="discovery-queue-title">未来开盘</h2>
        <small>仅显示已有明确开盘时间的交易对</small>
      </div>
      <b>
        {{ missionCount }} 项
        <small v-if="truncated">· 当前窗口已截断</small>
      </b>
    </header>

    <ol v-if="renderedOperations.length" class="discovery-queue__list">
      <li
        v-for="operation in renderedOperations"
        :key="operation.operation_key"
        :class="{ 'is-selected': operation.operation_key === selectedKey }"
      >
        <button type="button" @click="$emit('select', operation.operation_key)">
          <b class="discovery-queue__rank">{{ rank(operation) }}</b>
          <span class="discovery-queue__content">
            <small>{{ platform(operation) }}</small>
            <listing-channel-badge :value="operation" compact />
            <strong>{{ identity(operation).base }} <i>/ {{ identity(operation).quote }}</i></strong>
            <em>{{ operation.title || "上币与早期市场机会发现" }}</em>
            <span class="discovery-queue__status" :class="`is-${group(operation).tone}`">
              {{ group(operation).label }}
            </span>
          </span>
          <time>
            <strong>{{ formatTime(operation.planned_start_at_ms) }}</strong>
            <small>{{ compactTime(operation) }}</small>
          </time>
        </button>
      </li>
    </ol>

    <div v-else class="discovery-queue__empty">
      <i class="el-icon-search" aria-hidden="true" />
      {{ emptyText }}
    </div>
    <small v-if="missionCount > renderedOperations.length" class="discovery-queue__limit">
      共 {{ missionCount }} 项，当前展示最早的 {{ renderedOperations.length }} 项
    </small>
  </section>
</template>

<script>
import {
  countdownPresentation,
  formatListingTime,
  operationDisplayGroupMeta,
  operationIdentity,
  platformName
} from "@/utils/spotListingDiscovery";
import ListingChannelBadge from "./ListingChannelBadge.vue";

export default {
  name: "SpotListingDiscoveryQueue",
  components: { ListingChannelBadge },
  props: {
    operations: {
      type: Array,
      default: () => []
    },
    summary: {
      type: Object,
      default: () => ({})
    },
    selectedKey: {
      type: String,
      default: ""
    },
    nowMs: {
      type: Number,
      required: true
    },
    loaded: Boolean,
    unavailable: Boolean,
    coverageIncomplete: Boolean,
    truncated: Boolean
  },
  computed: {
    missionCount() {
      return this.visibleOperations.length;
    },
    visibleOperations() {
      return this.operations
        .filter(item =>
          Number.isSafeInteger(item.planned_start_at_ms) &&
          item.planned_start_at_ms > this.nowMs &&
          ["upcoming", "opening"].includes(item.operation_group) &&
          !["trading", "disabled"].includes(item.exchange_status)
        )
        .slice()
        .sort((left, right) => {
          const delta = left.planned_start_at_ms - right.planned_start_at_ms;
          return delta !== 0
            ? delta
            : String(left.operation_key).localeCompare(String(right.operation_key));
        });
    },
    renderedOperations() {
      return this.visibleOperations.slice(0, 8);
    },
    emptyText() {
      if (this.unavailable && !this.loaded) {
        return "任务数据暂不可用，后台自动重试中";
      }
      if (!this.loaded) {
        return "正在载入任务队列";
      }
      if (this.coverageIncomplete) {
        return "来源异常，当前无法确认是否存在倒计时任务";
      }
      return "未来 7 天暂无已确认开盘时间的任务";
    }
  },
  methods: {
    formatTime: formatListingTime,
    identity: operationIdentity,
    group(operation) {
      return operationDisplayGroupMeta(operation, this.nowMs);
    },
    platform(operation) {
      return platformName(operation.platform_id, operation.platform_text);
    },
    rank(operation) {
      const index = this.visibleOperations.findIndex(
        item => item.operation_key === operation.operation_key
      );
      return String(index + 1).padStart(2, "0");
    },
    compactTime(operation) {
      const timer = countdownPresentation(operation, this.nowMs);
      if (timer.state === "unknown") return "平台未公布";
      if (timer.state === "overdue") return "计划已过";
      if (!timer.segments.length) {
        return timer.state === "trading"
          ? "已开放"
          : timer.state === "disabled"
          ? "已停止"
          : "平台未公布";
      }
      return `${timer.prefix}${timer.segments.map(item => item.value).join(":")}`;
    }
  }
};
</script>

<style lang="scss" scoped>
.discovery-queue {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-height: 560px;
  border: 1px solid #204457;
  border-radius: 7px;
  background: #09131f;

  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 82px;
    padding: 12px 16px;
    border-bottom: 1px solid #204457;
  }

  header span {
    display: block;
    color: #35dcff;
    font-size: 12px;
    letter-spacing: 0.14em;
  }

  h2 { margin: 4px 0 0; color: #f1f7fa; font-size: 20px; }
  header div > small { display: block; margin-top: 5px; color: #6f8998; font-size: 12px; }
  header > b { color: #ffc857; font-size: 14px; font-weight: 600; }
  header > b small { color: #ffc857; font-weight: 500; }

  &__list {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    margin: 0;
    padding: 0;
    list-style: none;
    scrollbar-color: #2a5264 #08131f;
    scrollbar-width: thin;
  }
  &__list::-webkit-scrollbar { width: 6px; }
  &__list::-webkit-scrollbar-track { background: #08131f; }
  &__list::-webkit-scrollbar-thumb { border-radius: 3px; background: #2a5264; }
  &__limit {
    display: block;
    padding: 10px 12px;
    border-top: 1px solid #193441;
    color: #ffc857;
    font-size: 12px;
    text-align: center;
  }
  &__list li { border-bottom: 1px solid #193441; }
  &__list li.is-selected { box-shadow: inset 3px 0 0 #35dcff; background: #102230; }
  &__list button {
    display: grid;
    grid-template-columns: 31px minmax(0, 1fr) auto;
    align-items: start;
    width: 100%;
    min-height: 108px;
    padding: 14px 14px 11px;
    border: 0;
    color: inherit;
    background: transparent;
    text-align: left;
    cursor: pointer;
  }
  &__list button:hover,
  &__list button:focus-visible { background: #0e202d; outline: none; }

  &__rank {
    width: 22px;
    padding: 4px 0;
    border: 1px solid #294b5e;
    border-radius: 3px;
    color: #758d9b;
    font-family: Consolas, monospace;
    font-size: 12px;
    text-align: center;
  }
  &__content { min-width: 0; }
  &__content > small { display: block; color: #35dcff; font-size: 12px; }
  &__content > .listing-channel { margin-top: 4px; }
  &__content > strong {
    display: block;
    overflow: hidden;
    margin-top: 5px;
    color: #f2f7f9;
    font-family: "Arial Narrow", Arial, sans-serif;
    font-size: 19px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__content > strong i { color: #8197a4; font-size: 12px; font-style: normal; }
  &__content > em {
    display: block;
    overflow: hidden;
    margin-top: 5px;
    color: #607888;
    font-size: 12px;
    font-style: normal;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__status { display: inline-block; margin-top: 6px; color: #6e8998; font-size: 12px; }
  &__status.is-cyan { color: #35dcff; }
  &__status.is-amber { color: #ffc857; }
  &__status.is-green { color: #29e59d; }
  &__status.is-red { color: #ff657b; }

  time {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    padding-top: 4px;
    color: #ffc857;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  time strong { color: #f4d17a; font-size: 12px; font-weight: 600; }
  time small { margin-top: 7px; color: #ffc857; font-size: 12px; }

  &__empty {
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 28px;
    color: #7894a3;
    font-size: 13px;
    line-height: 1.6;
    text-align: center;
  }
}

@media (min-width: 1281px) {
  .discovery-queue {
    height: 560px;
  }
}
</style>
