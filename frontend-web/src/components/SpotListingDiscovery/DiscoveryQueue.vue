<template>
  <section class="discovery-queue" aria-labelledby="discovery-queue-title">
    <header>
      <div>
        <span>LIVE MISSION QUEUE</span>
        <h2 id="discovery-queue-title">即将执行</h2>
      </div>
      <b>{{ operations.length }} 项任务</b>
    </header>

    <nav class="discovery-queue__tabs" aria-label="任务分组">
      <button
        v-for="tab in tabs"
        :key="tab.value"
        type="button"
        :class="{ 'is-active': activeGroup === tab.value }"
        @click="activeGroup = tab.value"
      >
        {{ tab.label }} <small>{{ tab.count }}</small>
      </button>
    </nav>

    <ol v-if="visibleOperations.length" class="discovery-queue__list">
      <li
        v-for="(operation, index) in visibleOperations"
        :key="operation.operation_key"
        :class="{ 'is-selected': operation.operation_key === selectedKey }"
      >
        <button type="button" @click="$emit('select', operation.operation_key)">
          <b class="discovery-queue__rank">{{ String(index + 1).padStart(2, "0") }}</b>
          <span class="discovery-queue__content">
            <small>{{ platform(operation) }}</small>
            <strong>{{ identity(operation).base }} <i>/ {{ identity(operation).quote }}</i></strong>
            <em>{{ operation.title || "交易所现货发现" }}</em>
            <span class="discovery-queue__status" :class="`is-${group(operation).tone}`">
              {{ group(operation).label }}
            </span>
          </span>
          <time>{{ compactTime(operation) }}</time>
        </button>
      </li>
    </ol>

    <div v-else class="discovery-queue__empty">
      <i class="el-icon-search" aria-hidden="true" />
      当前分组暂无任务
    </div>
  </section>
</template>

<script>
import {
  countdownPresentation,
  operationGroupMeta,
  operationIdentity,
  platformName
} from "@/utils/spotListingDiscovery";

export default {
  name: "SpotListingDiscoveryQueue",
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
    }
  },
  data() {
    return { activeGroup: "all" };
  },
  computed: {
    tabs() {
      return [
        { value: "all", label: "全部", count: this.operations.length },
        { value: "upcoming", label: "待开", count: this.summary.upcoming || 0 },
        { value: "opening", label: "到时", count: this.summary.opening || 0 },
        { value: "time_unknown", label: "待定", count: this.summary.time_unknown || 0 },
        { value: "trading", label: "已开", count: this.summary.trading || 0 }
      ];
    },
    visibleOperations() {
      const items =
        this.activeGroup === "all"
          ? this.operations
          : this.operations.filter(item => item.operation_group === this.activeGroup);
      return items.slice(0, 30);
    }
  },
  methods: {
    identity: operationIdentity,
    group(operation) {
      return operationGroupMeta(operation.operation_group);
    },
    platform(operation) {
      return platformName(operation.platform_id, operation.platform_text);
    },
    compactTime(operation) {
      const timer = countdownPresentation(operation, this.nowMs);
      if (!timer.segments.length) {
        return timer.state === "trading" ? "已开放" : timer.state === "disabled" ? "已停止" : "--";
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
    min-height: 69px;
    padding: 10px 15px;
    border-bottom: 1px solid #204457;
  }

  header span {
    display: block;
    color: #35dcff;
    font-size: 8px;
    letter-spacing: 0.14em;
  }

  h2 { margin: 4px 0 0; color: #f1f7fa; font-size: 16px; }
  header > b { color: #66808f; font-size: 10px; font-weight: 500; }

  &__tabs {
    display: flex;
    overflow-x: auto;
    min-height: 42px;
    padding: 0 7px;
    border-bottom: 1px solid #193441;
  }
  &__tabs button {
    flex: 0 0 auto;
    min-height: 40px;
    padding: 0 8px;
    border: 0;
    border-bottom: 2px solid transparent;
    color: #607b8a;
    background: transparent;
    font-size: 10px;
    cursor: pointer;
  }
  &__tabs button.is-active { border-bottom-color: #35dcff; color: #35dcff; }
  &__tabs small { margin-left: 2px; }

  &__list {
    flex: 1;
    overflow-y: auto;
    margin: 0;
    padding: 0;
    list-style: none;
  }
  &__list li { border-bottom: 1px solid #193441; }
  &__list li.is-selected { box-shadow: inset 3px 0 0 #35dcff; background: #102230; }
  &__list button {
    display: grid;
    grid-template-columns: 31px minmax(0, 1fr) auto;
    align-items: start;
    width: 100%;
    min-height: 104px;
    padding: 13px 12px 10px;
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
    font-size: 9px;
    text-align: center;
  }
  &__content { min-width: 0; }
  &__content > small { display: block; color: #35dcff; font-size: 9px; }
  &__content > strong {
    display: block;
    overflow: hidden;
    margin-top: 5px;
    color: #f2f7f9;
    font-family: "Arial Narrow", Arial, sans-serif;
    font-size: 17px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__content > strong i { color: #8197a4; font-size: 9px; font-style: normal; }
  &__content > em {
    display: block;
    overflow: hidden;
    margin-top: 5px;
    color: #607888;
    font-size: 9px;
    font-style: normal;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__status { display: inline-block; margin-top: 6px; color: #6e8998; font-size: 9px; }
  &__status.is-cyan { color: #35dcff; }
  &__status.is-amber { color: #ffc857; }
  &__status.is-green { color: #29e59d; }
  &__status.is-red { color: #ff657b; }

  time {
    padding-top: 4px;
    color: #ffc857;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 10px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  &__empty {
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #587181;
    font-size: 11px;
  }
}
</style>
