<template>
  <section class="radar-stage" aria-labelledby="radar-stage-title">
    <div v-if="operation" class="radar-stage__body">
      <aside class="radar-stage__identity">
        <span class="radar-stage__platform">{{ platform }}</span>
        <h1 id="radar-stage-title">
          {{ identity.base }} <small>/ {{ identity.quote }}</small>
        </h1>
        <p>{{ operation.title || `${identity.base} 现货上币监测` }}</p>
        <span class="radar-stage__spot">SPOT · DISCOVERY</span>
        <dl>
          <div>
            <dt>计划开盘时间</dt>
            <dd>{{ formatTime(operation.planned_start_at_ms) }}</dd>
          </div>
          <div>
            <dt>时间依据</dt>
            <dd>{{ sourceLabel }}</dd>
          </div>
          <div>
            <dt>首次发现</dt>
            <dd>{{ formatTime(operation.first_seen_at_ms || operation.detected_at_ms) }}</dd>
          </div>
        </dl>
        <button
          v-if="operation.announcement_event_id"
          type="button"
          class="radar-stage__link"
          @click="$emit('announcement', operation.announcement_event_id)"
        >
          <i class="el-icon-link" aria-hidden="true" /> 查看官方公告
        </button>
      </aside>

      <div class="radar-stage__scope">
        <div class="radar-stage__sweep" aria-hidden="true" />
        <div class="radar-stage__timer" :class="`is-${countdown.tone}`" role="timer">
          <span>{{ countdown.label }}</span>
          <strong v-if="countdown.segments.length">
            <b>{{ countdown.prefix }}</b>
            <template v-for="(segment, index) in countdown.segments">
              <em :key="`${segment.label}-value`">
                <i>{{ segment.value }}</i>
                <small>{{ segment.label }}</small>
              </em>
              <b
                v-if="index < countdown.segments.length - 1"
                :key="`${segment.label}-separator`"
                class="radar-stage__separator"
              >:</b>
            </template>
          </strong>
          <strong v-else class="radar-stage__terminal">{{ countdown.label }}</strong>
        </div>
      </div>
    </div>

    <div v-else class="radar-stage__empty">
      <i class="el-icon-rank" aria-hidden="true" />
      <strong id="radar-stage-title">{{ loading ? "正在接入雷达数据" : "当前窗口暂无上币项目" }}</strong>
      <span>发现引擎会持续寻找下一项现货上币任务</span>
    </div>

    <nav class="radar-stage__toolbar" aria-label="项目切换与刷新">
      <button type="button" :disabled="!hasPrevious" @click="$emit('previous')">
        <i class="el-icon-arrow-left" aria-hidden="true" /> 上一个
      </button>
      <button type="button" :disabled="!hasNext" @click="$emit('next')">
        下一个 <i class="el-icon-arrow-right" aria-hidden="true" />
      </button>
      <button type="button" :disabled="!selectionLocked" @click="$emit('latest')">
        返回最近项目
      </button>
      <button
        type="button"
        class="is-primary"
        :disabled="refreshing"
        @click="$emit('refresh')"
      >
        <i :class="refreshing ? 'el-icon-loading' : 'el-icon-refresh'" aria-hidden="true" />
        {{ refreshing ? "刷新校时中" : "立即刷新并校时" }}
      </button>
    </nav>

    <ol v-if="operation" class="radar-stage__lifecycle" aria-label="项目发现时间线">
      <li
        v-for="(node, index) in operation.lifecycle"
        :key="node.key"
        :class="`is-${nodeState(node, index)}`"
      >
        <i :class="nodeState(node, index) === 'completed' ? 'el-icon-check' : 'el-icon-time'" aria-hidden="true" />
        <span>{{ lifecycleText(node.key) }}</span>
        <small>{{ formatTime(node.at_ms) }}</small>
      </li>
    </ol>
  </section>
</template>

<script>
import {
  countdownPresentation,
  formatListingTime,
  lifecycleLabel,
  lifecycleNodeState,
  operationIdentity,
  platformName,
  startSourceLabel
} from "@/utils/spotListingDiscovery";

export default {
  name: "SpotListingRadarStage",
  props: {
    operation: {
      type: Object,
      default: null
    },
    nowMs: {
      type: Number,
      required: true
    },
    hasPrevious: Boolean,
    hasNext: Boolean,
    selectionLocked: Boolean,
    loading: Boolean,
    refreshing: Boolean
  },
  computed: {
    countdown() {
      return countdownPresentation(this.operation, this.nowMs);
    },
    identity() {
      return operationIdentity(this.operation);
    },
    platform() {
      return this.operation
        ? platformName(this.operation.platform_id, this.operation.platform_text)
        : "--";
    },
    sourceLabel() {
      return this.operation
        ? startSourceLabel(this.operation.planned_start_source)
        : "--";
    }
  },
  methods: {
    formatTime: formatListingTime,
    lifecycleText: lifecycleLabel,
    nodeState(node, index) {
      return lifecycleNodeState(this.operation, node, index, this.nowMs);
    }
  }
};
</script>

<style lang="scss" scoped>
.radar-stage {
  position: relative;
  overflow: hidden;
  min-height: 560px;
  border: 1px solid #204457;
  border-radius: 7px;
  background: #09131f;
  box-shadow: inset 0 0 90px rgba(0, 0, 0, 0.35), 0 18px 60px rgba(0, 0, 0, 0.26);

  &__body {
    display: grid;
    grid-template-columns: minmax(240px, 0.82fr) minmax(410px, 1.9fr);
    min-height: 430px;
  }

  &__identity {
    position: relative;
    z-index: 2;
    min-width: 0;
    padding: 38px 28px 22px;
    border-right: 1px solid rgba(46, 110, 137, 0.3);
  }

  &__platform {
    color: #35dcff;
    font-size: 16px;
    font-weight: 700;
  }

  h1 {
    overflow: hidden;
    margin: 25px 0 8px;
    color: #f4f9fc;
    font-family: "Arial Narrow", "Roboto Condensed", Arial, sans-serif;
    font-size: clamp(44px, 5vw, 76px);
    line-height: 0.96;
    text-overflow: ellipsis;
    text-transform: uppercase;
    white-space: nowrap;
  }

  h1 small {
    color: #c6d4dc;
    font-size: 0.4em;
  }

  p {
    overflow: hidden;
    margin: 10px 0 16px;
    color: #829cac;
    font-size: 13px;
    line-height: 1.55;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__spot {
    display: inline-block;
    padding: 4px 8px;
    border: 1px solid #294a5d;
    border-radius: 3px;
    color: #7f9baa;
    font-size: 9px;
    letter-spacing: 0.12em;
  }

  dl { margin: 28px 0 0; }
  dl > div + div { margin-top: 12px; }
  dt { color: #4f7183; font-size: 10px; }
  dd {
    margin: 4px 0 0;
    color: #35dcff;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
  }

  &__link {
    min-height: 40px;
    margin-top: 10px;
    padding: 0;
    border: 0;
    color: #35dcff;
    background: transparent;
    cursor: pointer;
  }

  &__scope {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    min-width: 0;
    background-image:
      linear-gradient(rgba(53, 220, 255, 0.055) 1px, transparent 1px),
      linear-gradient(90deg, rgba(53, 220, 255, 0.055) 1px, transparent 1px),
      repeating-radial-gradient(circle at center, transparent 0 61px, rgba(53, 220, 255, 0.12) 62px 63px);
    background-size: 45px 45px, 45px 45px, auto;
  }

  &__scope::before,
  &__scope::after {
    position: absolute;
    top: 50%;
    left: 50%;
    background: rgba(53, 220, 255, 0.18);
    content: "";
  }
  &__scope::before { width: 1px; height: 100%; transform: translate(-50%, -50%); }
  &__scope::after { width: 100%; height: 1px; transform: translate(-50%, -50%); }

  &__sweep {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 47%;
    height: 1px;
    transform-origin: left center;
    background: linear-gradient(90deg, transparent, #35dcff);
    box-shadow: 90px 0 22px rgba(53, 220, 255, 0.36);
    animation: radar-stage-sweep 9s linear infinite;
  }

  &__timer {
    position: relative;
    z-index: 2;
    max-width: 100%;
    padding: 28px;
    color: #ffc857;
    text-align: center;
  }

  &__timer > span {
    display: block;
    margin-bottom: 18px;
    font-size: 15px;
    font-weight: 700;
  }

  &__timer > strong {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: clamp(30px, 5.5vw, 68px);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  &__timer strong > b { margin: 4px 8px 0 0; font-size: 0.32em; }
  &__timer em { display: inline-flex; flex-direction: column; font-style: normal; }
  &__timer em i { font-style: normal; }
  &__timer em small { margin-top: 5px; color: #688291; font-size: 9px; font-weight: 400; }
  &__separator { margin: 0 7px !important; font-size: 0.85em !important; }
  &__timer.is-green { color: #29e59d; }
  &__timer.is-red { color: #ff657b; }
  &__timer.is-muted { color: #79919f; }
  &__terminal { max-width: 680px; white-space: normal !important; }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 430px;
    color: #607989;
  }
  &__empty i { color: #35dcff; font-size: 56px; }
  &__empty strong { margin-top: 18px; color: #e7f4fa; font-size: 22px; }
  &__empty span { margin-top: 8px; font-size: 12px; }

  &__toolbar {
    display: flex;
    gap: 8px;
    min-height: 52px;
    padding: 8px 12px;
    border-top: 1px solid #204457;
    border-bottom: 1px solid #204457;
    background: #0b1823;
  }

  &__toolbar button {
    min-height: 36px;
    padding: 0 13px;
    border: 1px solid #294a5d;
    border-radius: 3px;
    color: #91a8b5;
    background: #0b1620;
    cursor: pointer;
  }
  &__toolbar button:disabled { opacity: 0.35; cursor: not-allowed; }
  &__toolbar button.is-primary { margin-left: auto; border-color: #35dcff; color: #03131d; background: #35dcff; }

  &__lifecycle {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    min-height: 70px;
    margin: 0;
    padding: 12px 16px;
    list-style: none;
  }

  &__lifecycle li {
    position: relative;
    display: grid;
    grid-template-columns: 20px 1fr;
    align-content: center;
    min-width: 0;
    color: #536d7d;
  }
  &__lifecycle li::after {
    position: absolute;
    top: 20px;
    right: 8px;
    left: 28px;
    height: 1px;
    background: #294553;
    content: "";
  }
  &__lifecycle li:last-child::after { display: none; }
  &__lifecycle i { color: #526f7f; }
  &__lifecycle span { overflow: hidden; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
  &__lifecycle small { grid-column: 2; margin-top: 3px; font-size: 8px; }
  &__lifecycle li.is-completed i,
  &__lifecycle li.is-completed span { color: #35dcff; }
  &__lifecycle li.is-current i,
  &__lifecycle li.is-current span { color: #ffc857; }
}

@keyframes radar-stage-sweep {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .radar-stage__sweep { animation: none; }
}

@media (max-width: 920px) {
  .radar-stage__body { grid-template-columns: 1fr; }
  .radar-stage__identity { border-right: 0; border-bottom: 1px solid #204457; }
  .radar-stage__scope { min-height: 330px; }
  .radar-stage__toolbar { flex-wrap: wrap; }
  .radar-stage__toolbar button.is-primary { margin-left: 0; }
  .radar-stage__lifecycle { grid-template-columns: 1fr; gap: 7px; }
  .radar-stage__lifecycle li::after { display: none; }
}
</style>
