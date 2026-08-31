<template>
  <section class="intelligence-strip" aria-label="最新发现与待补全开盘时间">
    <header>
      <i class="el-icon-rank" aria-hidden="true" />
      <div>
        <strong>最新发现</strong>
        <span>新记录与时间缺口分开展示，不把待补时间冒充未来开盘</span>
      </div>
    </header>

    <div class="intelligence-strip__column">
      <h2>最新发现记录</h2>
      <button
        v-for="item in recentDiscoveries"
        :key="signalKey(item)"
        type="button"
        @click="openSignal(item)"
      >
        <span class="intelligence-strip__pair">
          <strong>{{ pairLabel(item) }}</strong>
          <small>{{ item.title || "发现新的上币线索" }}</small>
        </span>
        <b class="intelligence-strip__exchange">{{ platform(item) }}</b>
        <span class="intelligence-strip__classification">
          <mark v-if="projectionNotice(item)">公告已修订/投影失效</mark>
          <listing-channel-badge :value="item" compact />
        </span>
        <time>{{ formatTime(signalTime(item)) }}</time>
      </button>
      <p v-if="latestStatusText">{{ latestStatusText }}</p>
    </div>

    <div class="intelligence-strip__column is-missing">
      <h2>
        待补全开盘时间
        <b>{{ missingTimeSignals.length }}</b>
      </h2>
      <button
        v-for="item in visibleMissingTimes"
        :key="`missing-${signalKey(item)}`"
        type="button"
        @click="openSignal(item)"
      >
        <span class="intelligence-strip__pair">
          <strong>{{ pairLabel(item) }}</strong>
          <small>{{ item.title || "已发现上币线索，等待补全开盘时间" }}</small>
        </span>
        <b class="intelligence-strip__exchange">{{ platform(item) }}</b>
        <span class="intelligence-strip__classification">
          <mark>{{ missingTimeLabel(item) }}</mark>
          <listing-channel-badge :value="item" compact />
        </span>
        <time>{{ formatTime(signalTime(item)) }}</time>
      </button>
      <p v-if="missingStatusText">{{ missingStatusText }}</p>
      <p v-if="historicalMissingCount" class="intelligence-strip__history-note">
        另有 {{ historicalMissingCount }} 项历史开盘记录缺时，已归入全部发现记录
      </p>
    </div>

    <div v-if="verificationClues.length" class="intelligence-strip__verification">
      <details>
        <summary>
          待核验公告线索 <b>{{ verificationClues.length }}</b>
          <span>尚未确认安全交易对，不进入最新发现、倒计时或待补时间</span>
        </summary>
        <button
          v-for="item in visibleVerificationClues"
          :key="`verification-${signalKey(item)}`"
          type="button"
          @click="openSignal(item)"
        >
          <span class="intelligence-strip__pair">
            <strong>{{ pairLabel(item) }}</strong>
            <small>{{ item.title || "公告交易对仍待核验" }}</small>
          </span>
          <b class="intelligence-strip__exchange">{{ platform(item) }}</b>
          <span class="intelligence-strip__classification">
            <mark>{{ verificationLabel(item) }}</mark>
            <listing-channel-badge :value="item" compact />
          </span>
          <time>{{ formatTime(signalTime(item)) }}</time>
        </button>
        <p v-if="verificationClues.length > visibleVerificationClues.length">
          当前仅展示最近 {{ visibleVerificationClues.length }} 条，其余线索保留在公告审计记录中
        </p>
      </details>
    </div>
  </section>
</template>

<script>
import {
  announcementProjectionMessage,
  formatListingTime,
  listingPairLabel,
  platformName
} from "@/utils/spotListingDiscovery";
import ListingChannelBadge from "./ListingChannelBadge.vue";

export default {
  name: "SpotListingIntelligenceStrip",
  components: { ListingChannelBadge },
  props: {
    announcements: {
      type: Array,
      default: () => []
    },
    operations: {
      type: Array,
      default: () => []
    },
    announcementUnavailable: Boolean,
    announcementLoaded: Boolean,
    operationsLoaded: Boolean,
    operationsUnavailable: Boolean,
    operationsCoverageIncomplete: Boolean
  },
  computed: {
    operationAnnouncementIds() {
      return new Set(
        this.operations
          .map(item => item.announcement_event_id)
          .filter(id => Number.isSafeInteger(id) && id > 0)
      );
    },
    unmatchedAnnouncements() {
      return this.announcements.filter(item => {
        const id = item.announcement_event_id || item.id;
        return !this.operationAnnouncementIds.has(id);
      });
    },
    verifiedAnnouncements() {
      return this.unmatchedAnnouncements.filter(item =>
        this.announcementHasVerifiedPair(item)
      );
    },
    verificationClues() {
      return this.unmatchedAnnouncements.filter(item =>
        !this.announcementHasVerifiedPair(item)
      );
    },
    visibleVerificationClues() {
      return this.verificationClues
        .slice()
        .sort((left, right) => this.signalTime(right) - this.signalTime(left))
        .slice(0, 4);
    },
    recentDiscoveries() {
      return [...this.operations, ...this.verifiedAnnouncements]
        .slice()
        .sort((left, right) => this.signalTime(right) - this.signalTime(left))
        .slice(0, 4);
    },
    missingTimeOperations() {
      return this.operations
        .filter(item =>
          !Number.isSafeInteger(item.planned_start_at_ms) &&
          (
            item.schedule_conflict === true ||
            !["trading", "disabled"].includes(item.exchange_status)
          )
        )
        .slice()
        .sort((left, right) => {
          const leftCritical = left.schedule_conflict === true ? 0 : 1;
          const rightCritical = right.schedule_conflict === true ? 0 : 1;
          if (leftCritical !== rightCritical) return leftCritical - rightCritical;
          return this.signalTime(right) - this.signalTime(left);
        });
    },
    historicalMissingTimeOperations() {
      return this.operations.filter(item =>
        !Number.isSafeInteger(item.planned_start_at_ms) &&
        item.schedule_conflict !== true &&
        ["trading", "disabled"].includes(item.exchange_status)
      );
    },
    missingTimeAnnouncements() {
      return this.verifiedAnnouncements.filter(item =>
        !this.announcementHasTime(item) && !this.announcementLooksAlreadyLive(item)
      );
    },
    historicalMissingTimeAnnouncements() {
      return this.verifiedAnnouncements.filter(item =>
        !this.announcementHasTime(item) && this.announcementLooksAlreadyLive(item)
      );
    },
    historicalMissingCount() {
      return this.historicalMissingTimeOperations.length +
        this.historicalMissingTimeAnnouncements.length;
    },
    missingTimeSignals() {
      return [...this.missingTimeOperations, ...this.missingTimeAnnouncements]
        .slice()
        .sort((left, right) => {
          const leftCritical = left.schedule_conflict === true ? 0 : 1;
          const rightCritical = right.schedule_conflict === true ? 0 : 1;
          if (leftCritical !== rightCritical) return leftCritical - rightCritical;
          return this.signalTime(right) - this.signalTime(left);
        });
    },
    visibleMissingTimes() {
      return this.missingTimeSignals.slice(0, 4);
    },
    latestStatusText() {
      if (this.operationsUnavailable && !this.operationsLoaded) {
        return "发现数据暂不可用，后台自动重试中";
      }
      if (!this.operationsLoaded) return "正在载入最新发现";
      if (this.operationsCoverageIncomplete) {
        return "数据来源异常，当前展示上次有效记录";
      }
      if (this.announcementUnavailable && this.recentDiscoveries.length) {
        return "公告列表更新失败，市场发现记录继续自动更新";
      }
      return this.recentDiscoveries.length ? "" : "当前窗口暂无发现记录";
    },
    missingStatusText() {
      if (this.operationsUnavailable && !this.operationsLoaded) {
        return "暂时无法核对开盘时间缺口";
      }
      if (!this.operationsLoaded) return "正在核对开盘时间完整性";
      if (this.operationsCoverageIncomplete) {
        return "来源异常，不能把当前结果视为完整时间清单";
      }
      if (this.visibleMissingTimes.length < this.missingTimeSignals.length) {
        return `当前窗口共 ${this.missingTimeSignals.length} 项待补开盘时间，仅展示最近 4 项`;
      }
      return this.missingTimeSignals.length ? "" : "当前待开盘记录时间完整";
    }
  },
  methods: {
    formatTime: formatListingTime,
    pairLabel: listingPairLabel,
    projectionNotice: announcementProjectionMessage,
    platform(item) {
      return platformName(item.platform_id, item.platform_text);
    },
    signalKey(item) {
      return item.operation_key
        ? `operation-${item.operation_key}`
        : `announcement-${item.announcement_event_id || item.id}`;
    },
    signalTime(item) {
      return [
        item.first_seen_at_ms,
        item.detected_at_ms,
        item.published_at_ms
      ].reduce(
        (latest, timestamp) =>
          Number.isSafeInteger(timestamp) && timestamp > latest
            ? timestamp
            : latest,
        0
      );
    },
    openSignal(item) {
      const announcementId = item.announcement_event_id || (
        item.operation_key ? null : item.id
      );
      if (Number.isSafeInteger(announcementId) && announcementId > 0) {
        this.$emit("announcement", announcementId);
        return;
      }
      if (item.operation_key) this.$emit("select", item.operation_key);
    },
    announcementHasTime(item) {
      if (
        Number.isSafeInteger(item.announced_trading_start_at_ms) &&
        item.announced_trading_start_at_ms > 0
      ) {
        return true;
      }
      const pairs = Array.isArray(item.pairs) ? item.pairs : [];
      return pairs.some(pair =>
        Number.isSafeInteger(pair.announced_trading_start_at_ms) &&
        pair.announced_trading_start_at_ms > 0
      );
    },
    announcementHasVerifiedPair(item) {
      if (!item || item.projection_invalidated === true) return false;
      if (String(item.announcement_kind || "").toLowerCase() === "ambiguous") {
        return false;
      }
      const pairs = Array.isArray(item.pairs) ? item.pairs : [];
      return pairs.some(pair => {
        if (!pair || typeof pair !== "object") return false;
        const symbol = String(pair.symbol || pair.candidate_symbol || "")
          .trim()
          .toUpperCase();
        const quote = String(
          pair.quote_currency || pair.candidate_quote || ""
        ).trim().toUpperCase();
        const confidence = Number.isFinite(pair.parse_confidence)
          ? pair.parse_confidence
          : item.parse_confidence;
        const safeQuote = quote === "USDT" || symbol.endsWith("USDT");
        const safeConfidence = !Number.isFinite(confidence) || confidence >= 70;
        return symbol.length > 4 && safeQuote && safeConfidence;
      });
    },
    announcementLooksAlreadyLive(item) {
      const title = String(item.title || "");
      return /\bnow live\b|\bis live\b|現已上線|现已上线|已經上線|已经上线|首发上线/i.test(title);
    },
    missingTimeLabel(item) {
      if (item.schedule_conflict === true) return "官方时间冲突";
      if (!item.operation_key) {
        return "公告未给出精确时间";
      }
      return ["trading", "disabled"].includes(item.exchange_status)
        ? "历史开盘时间缺失"
        : "开盘时间缺失";
    },
    verificationLabel(item) {
      if (item.projection_invalidated === true) return "公告投影已失效";
      if (String(item.announcement_kind || "").toLowerCase() === "ambiguous") {
        return "公告含义待确认";
      }
      return "交易对待确认";
    }
  }
};
</script>

<style lang="scss" scoped>
.intelligence-strip {
  display: grid;
  grid-template-columns: 190px minmax(0, 1.15fr) minmax(0, 0.85fr);
  overflow: hidden;
  border: 1px solid #204457;
  border-radius: 7px;
  background: #09131f;

  > header {
    display: flex;
    align-items: center;
    gap: 13px;
    min-height: 220px;
    padding: 22px;
    border-right: 1px solid #204457;
  }
  > header i { color: #35dcff; font-size: 29px; }
  > header strong { display: block; color: #e9f5fa; font-size: 18px; }
  > header span { display: block; margin-top: 8px; color: #7893a2; font-size: 12px; line-height: 1.65; }

  &__column { min-width: 0; padding: 16px 18px; }
  &__column + &__column { border-left: 1px solid #183641; }
  &__column.is-missing { background: rgba(255, 200, 87, 0.025); }
  h2 { margin: 0 0 9px; color: #9bb0bb; font-size: 13px; font-weight: 600; letter-spacing: 0.05em; }
  h2 b { margin-left: 5px; color: #ffc857; }

  button {
    display: grid;
    grid-template-columns: minmax(180px, 1.35fr) 76px minmax(150px, 1fr) 146px;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 54px;
    padding: 8px 7px;
    border: 0;
    border-top: 1px solid #162f3b;
    color: inherit;
    background: transparent;
    text-align: left;
    cursor: pointer;
  }
  button:hover,
  button:focus-visible { background: #0e202d; outline: none; }
  &__pair { min-width: 0; }
  &__pair strong {
    display: block;
    overflow: hidden;
    color: #f2f8fb;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: 0.015em;
    line-height: 1.25;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__pair small {
    display: block;
    overflow: hidden;
    margin-top: 4px;
    color: #7893a2;
    font-size: 12px;
    line-height: 1.25;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__exchange {
    overflow: hidden;
    color: #35dcff;
    font-size: 13px;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  &__classification {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px;
    min-width: 0;
    color: #8aa0ac;
    font-size: 12px;
  }
  button mark {
    display: inline-block;
    padding: 2px 4px;
    border: 1px solid rgba(255, 200, 87, 0.5);
    border-radius: 3px;
    color: #ffc857;
    background: rgba(255, 200, 87, 0.1);
    font-size: 12px;
  }
  button time { color: #6f8998; font-family: Consolas, monospace; font-size: 12px; white-space: nowrap; }
  p { margin: 18px 4px; color: #6f8998; font-size: 12px; line-height: 1.6; }
  &__history-note { color: #657d8b; }

  &__verification {
    grid-column: 2 / -1;
    padding: 10px 18px 14px;
    border-top: 1px solid #183641;
    background: rgba(120, 147, 162, 0.035);
  }
  &__verification summary {
    color: #8aa0ac;
    font-size: 12px;
    cursor: pointer;
  }
  &__verification summary b { margin-left: 4px; color: #9bb0bb; }
  &__verification summary span { margin-left: 12px; color: #657d8b; }
}

@media (max-width: 1550px) and (min-width: 1051px) {
  .intelligence-strip button {
    grid-template-columns: minmax(150px, 1fr) 70px 132px;
    grid-template-areas:
      "pair exchange time"
      "classification classification classification";
  }
  .intelligence-strip__pair { grid-area: pair; }
  .intelligence-strip__exchange { grid-area: exchange; }
  .intelligence-strip__classification { grid-area: classification; }
  .intelligence-strip button time { grid-area: time; text-align: right; }
}

@media (max-width: 1050px) {
  .intelligence-strip { grid-template-columns: 1fr; }
  .intelligence-strip > header { min-height: auto; border-right: 0; border-bottom: 1px solid #204457; }
  .intelligence-strip__column + .intelligence-strip__column { border-top: 1px solid #183641; border-left: 0; }
  .intelligence-strip__verification { grid-column: 1 / -1; }
}

@media (max-width: 720px) {
  .intelligence-strip button {
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      "pair exchange"
      "classification time";
  }
  .intelligence-strip__pair { grid-area: pair; }
  .intelligence-strip__exchange { grid-area: exchange; text-align: right; }
  .intelligence-strip__classification { grid-area: classification; }
  .intelligence-strip button time { grid-area: time; align-self: end; }
}
</style>
