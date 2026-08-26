<template>
  <div v-if="canView" class="listing-room">
    <header class="listing-room__header">
      <div class="listing-room__brand">
        <span>SPOT LISTING DISCOVERY</span>
        <h1>新币雷达 <small>上币作战室</small></h1>
      </div>
      <div class="listing-room__scope">币安 <i /> OKX <i /> Gate <i /> MEXC <i /> KuCoin</div>
      <div class="listing-room__sync" :class="{ 'is-warning': dataStale || operationsUnavailable }">
        <strong>{{ syncHeadline }}</strong>
        <span>{{ syncDetail }}</span>
      </div>
    </header>

    <div v-if="operationsUnavailable || dataStale" class="listing-room__warning" role="status">
      <i class="el-icon-warning-outline" aria-hidden="true" />
      <span v-if="dataStale">雷达数据已超过 2 分钟未更新，画面保留最后一次有效结果，后台将继续自动重试。</span>
      <span v-else>本轮雷达数据更新失败，画面保留最后一次有效结果，5 秒后自动重试。</span>
    </div>

    <source-health-strip :sources="sourceHealth" />

    <main class="listing-room__main">
      <radar-stage
        :operation="selectedOperation"
        :now-ms="nowMs"
        :has-previous="selectedIndex > 0"
        :has-next="selectedIndex >= 0 && selectedIndex < operations.length - 1"
        :selection-locked="selectionLocked"
        :loading="operationsLoading && !operationsLoaded"
        :refreshing="manualRefreshing"
        @previous="selectRelative(-1)"
        @next="selectRelative(1)"
        @latest="returnToLatest"
        @refresh="refreshAndCalibrate"
        @announcement="openAnnouncement"
      />
      <discovery-queue
        :operations="operations"
        :summary="summary"
        :selected-key="selectedOperationKey"
        :now-ms="nowMs"
        @select="selectOperation"
      />
    </main>

    <intelligence-strip
      :announcements="announcements"
      :operations="operations"
      :announcement-unavailable="announcementsUnavailable"
      @select="selectOperation"
      @announcement="openAnnouncement"
    />

    <section class="listing-room__ledger" aria-labelledby="listing-ledger-title">
      <header>
        <div>
          <span>DISCOVERY LEDGER</span>
          <h2 id="listing-ledger-title">现货上币发现台账</h2>
        </div>
        <small>共 {{ total }} 项 · {{ truncated ? "当前窗口已截断" : "当前窗口完整" }}</small>
      </header>
      <div class="listing-room__table-wrap">
        <table>
          <thead>
            <tr>
              <th>交易所</th>
              <th>交易对 / 项目</th>
              <th>发现阶段</th>
              <th>计划开盘</th>
              <th>首次发现</th>
              <th>发现来源</th>
            </tr>
          </thead>
          <tbody v-if="operations.length">
            <tr
              v-for="operation in operations"
              :key="operation.operation_key"
              :class="{ 'is-selected': operation.operation_key === selectedOperationKey }"
              @click="selectOperation(operation.operation_key)"
            >
              <td><b>{{ platform(operation) }}</b></td>
              <td>
                <strong>{{ operation.symbol }}</strong>
                <small>{{ operation.title || "交易所现货发现" }}</small>
              </td>
              <td><span :class="`is-${group(operation).tone}`">{{ group(operation).label }}</span></td>
              <td><time>{{ formatTime(operation.planned_start_at_ms) }}</time></td>
              <td><time>{{ formatTime(operation.first_seen_at_ms || operation.detected_at_ms) }}</time></td>
              <td>{{ operation.announcement_event_id ? "公告 + 市场" : "市场直接发现" }}</td>
            </tr>
          </tbody>
          <tbody v-else>
            <tr><td colspan="6" class="listing-room__empty">发现窗口内暂无现货上币记录</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <el-drawer
      title="官方上币公告"
      :visible.sync="announcementVisible"
      direction="rtl"
      size="min(720px, 92vw)"
      custom-class="listing-announcement-drawer"
      @closed="announcementDetail = null"
    >
      <div v-loading="announcementDetailLoading" class="listing-room__drawer">
        <el-alert
          v-if="announcementDetailUnavailable"
          title="公告详情暂不可用，系统会继续自动更新公告列表"
          type="warning"
          :closable="false"
          show-icon
        />
        <template v-if="announcementDetail">
          <span>{{ platform(announcementDetail) }}</span>
          <h2>{{ announcementDetail.title }}</h2>
          <p>{{ announcementDetail.description || "暂无公告摘要" }}</p>
          <dl>
            <div><dt>官方发布</dt><dd>{{ formatTime(announcementDetail.published_at_ms) }}</dd></div>
            <div><dt>雷达发现</dt><dd>{{ formatTime(announcementDetail.detected_at_ms) }}</dd></div>
          </dl>
          <h3>公告涉及的现货交易对</h3>
          <ul v-if="announcementPairs.length">
            <li v-for="pair in announcementPairs" :key="pair.symbol">
              <strong>{{ pair.symbol }}</strong>
              <span>{{ formatTime(pair.announced_trading_start_at_ms) }}</span>
            </li>
          </ul>
          <p v-else>尚未从公告中安全解析出明确交易对。</p>
          <a
            v-if="announcementSourceUrl"
            :href="announcementSourceUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            <i class="el-icon-link" aria-hidden="true" /> 打开交易所官方原文
          </a>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import {
  DiscoveryQueue,
  IntelligenceStrip,
  RadarStage,
  SourceHealthStrip
} from "@/components/SpotListingDiscovery";
import {
  getSpotListingAnnouncementDetail,
  getSpotListingAnnouncements,
  getSpotListingOperations
} from "@/api/spotListings";
import { createSerialPoller } from "@/utils/serialPoller";
import { hasPermission } from "@/utils/permissions";
import {
  formatListingTime,
  isDiscoveryTerminal,
  operationGroupMeta,
  platformName,
  sanitizeOfficialSourceUrl,
  unwrapOperationsResponse
} from "@/utils/spotListingDiscovery";

const EMPTY_SUMMARY = {
  opening: 0,
  upcoming: 0,
  time_unknown: 0,
  trading: 0,
  disabled: 0
};
const STALE_AFTER_MS = 120000;

function unwrapPage(response) {
  const payload = response && response.code === 200 ? response.data : response;
  if (
    !payload ||
    typeof payload !== "object" ||
    !Array.isArray(payload.data) ||
    !Number.isSafeInteger(payload.total) ||
    payload.total < 0
  ) {
    throw new TypeError("invalid spot listing announcement page");
  }
  return payload;
}

export default {
  name: "SpotListingDiscoveryRoom",
  components: {
    DiscoveryQueue,
    IntelligenceStrip,
    RadarStage,
    SourceHealthStrip
  },
  data() {
    return {
      operations: [],
      summary: { ...EMPTY_SUMMARY },
      sourceHealth: [],
      total: 0,
      truncated: false,
      selectedOperationKey: "",
      selectionLocked: false,
      operationsLoaded: false,
      operationsLoading: false,
      operationsUnavailable: false,
      generatedAtMs: 0,
      lastSyncedAtMs: 0,
      clockOffsetMs: 0,
      clockCalibrated: false,
      lastCalibratedAtMs: 0,
      nowMs: Date.now(),
      ticker: null,
      operationsPoller: null,
      announcementPoller: null,
      announcements: [],
      announcementsUnavailable: false,
      announcementVisible: false,
      announcementDetailLoading: false,
      announcementDetailUnavailable: false,
      announcementDetail: null,
      manualRefreshing: false,
      calibrationRequested: false,
      manualPending: {
        operations: false,
        announcements: false
      },
      disposed: false
    };
  },
  computed: {
    canView() {
      return hasPermission("quotation.listing.view", this.$store.getters.permissions);
    },
    selectedIndex() {
      return this.operations.findIndex(
        item => item.operation_key === this.selectedOperationKey
      );
    },
    selectedOperation() {
      return this.selectedIndex >= 0 ? this.operations[this.selectedIndex] : null;
    },
    dataStale() {
      return this.generatedAtMs > 0 && this.nowMs - this.generatedAtMs >= STALE_AFTER_MS;
    },
    syncHeadline() {
      if (this.operationsUnavailable || this.dataStale) return "自动更新重试中";
      if (!this.operationsLoaded) return "正在建立雷达视图";
      return "发现引擎运行中";
    },
    syncDetail() {
      const synced = this.lastSyncedAtMs ? this.formatTime(this.lastSyncedAtMs) : "等待首次数据";
      const calibrated = this.lastCalibratedAtMs
        ? this.formatTime(this.lastCalibratedAtMs)
        : "等待首次校准";
      return `任务 5 秒、公告 30 秒自动更新 · 最近任务 ${synced} · 时间校准 ${calibrated}`;
    },
    announcementPairs() {
      return this.announcementDetail && Array.isArray(this.announcementDetail.pairs)
        ? this.announcementDetail.pairs
        : [];
    },
    announcementSourceUrl() {
      if (!this.announcementDetail) return "";
      return sanitizeOfficialSourceUrl(
        this.announcementDetail.source_url,
        this.announcementDetail.platform_id
      );
    }
  },
  watch: {
    canView(value) {
      if (value) this.syncPolling();
      else this.stopPolling();
    }
  },
  created() {
    this.operationsPoller = createSerialPoller(this.pollOperations, 5000);
    this.announcementPoller = createSerialPoller(this.pollAnnouncements, 30000);
  },
  mounted() {
    document.addEventListener("visibilitychange", this.handleVisibilityChange);
    if (this.isPageVisible()) this.startTicker();
    this.syncPolling();
  },
  beforeDestroy() {
    this.disposed = true;
    this.stopPolling();
    this.stopTicker();
    document.removeEventListener("visibilitychange", this.handleVisibilityChange);
  },
  methods: {
    formatTime: formatListingTime,
    group(operation) {
      return operationGroupMeta(operation.operation_group);
    },
    platform(operation) {
      return platformName(operation.platform_id, operation.platform_text);
    },
    isPageVisible() {
      return document.visibilityState !== "hidden";
    },
    startTicker() {
      if (this.ticker) return;
      this.tickClock();
      this.ticker = window.setInterval(this.tickClock, 1000);
    },
    stopTicker() {
      if (!this.ticker) return;
      window.clearInterval(this.ticker);
      this.ticker = null;
    },
    tickClock() {
      this.nowMs = Date.now() + (this.clockCalibrated ? this.clockOffsetMs : 0);
    },
    syncPolling() {
      if (!this.canView || this.disposed || !this.isPageVisible()) {
        this.stopPolling();
        return Promise.resolve(false);
      }
      return Promise.all([
        this.operationsPoller.start(),
        this.announcementPoller.start()
      ]);
    },
    stopPolling() {
      if (this.operationsPoller) this.operationsPoller.stop();
      if (this.announcementPoller) this.announcementPoller.stop();
    },
    handleVisibilityChange() {
      if (this.isPageVisible()) {
        this.startTicker();
        this.syncPolling();
      } else {
        this.stopTicker();
        this.stopPolling();
      }
    },
    async pollOperations() {
      const manual = this.manualPending.operations;
      const recalibrate = this.calibrationRequested;
      const result = await this.loadOperations(recalibrate);
      if (manual) {
        this.calibrationRequested = false;
        this.completeManualPart("operations");
      }
      return result;
    },
    async pollAnnouncements() {
      const manual = this.manualPending.announcements;
      const result = await this.loadAnnouncements();
      if (manual) this.completeManualPart("announcements");
      return result;
    },
    async loadOperations(recalibrate) {
      if (!this.canView || this.disposed || !this.isPageVisible()) return false;
      const requestStartedAt = Date.now();
      this.operationsLoading = true;
      try {
        const response = await getSpotListingOperations({
          limit: 50,
          past_hours: 72,
          future_hours: 168
        });
        if (this.disposed || !this.canView) return false;
        const requestFinishedAt = Date.now();
        const payload = unwrapOperationsResponse(response);
        this.operations = payload.operations;
        this.summary = payload.summary;
        this.sourceHealth = payload.source_health;
        this.total = payload.total;
        this.truncated = payload.truncated;
        this.generatedAtMs = payload.generated_at_ms;
        if (!this.clockCalibrated || recalibrate) {
          const midpoint = requestStartedAt + (requestFinishedAt - requestStartedAt) / 2;
          this.clockOffsetMs = payload.server_time_ms - midpoint;
          this.clockCalibrated = true;
          this.lastCalibratedAtMs = requestFinishedAt + this.clockOffsetMs;
          this.tickClock();
        }
        this.lastSyncedAtMs = requestFinishedAt + this.clockOffsetMs;
        this.reconcileSelection(payload);
        this.operationsLoaded = true;
        this.operationsUnavailable = false;
        return true;
      } catch (error) {
        this.operationsUnavailable = true;
        return false;
      } finally {
        this.operationsLoading = false;
      }
    },
    async loadAnnouncements() {
      if (!this.canView || this.disposed || !this.isPageVisible()) return false;
      try {
        const response = await getSpotListingAnnouncements({ page: 1, page_size: 10 });
        if (this.disposed || !this.canView) return false;
        this.announcements = unwrapPage(response).data;
        this.announcementsUnavailable = false;
        return true;
      } catch (error) {
        this.announcementsUnavailable = true;
        return false;
      }
    },
    defaultOperationKey(payload) {
      const serverChoice = payload.operations.find(
        item => item.operation_key === payload.selected_operation_key
      );
      if (serverChoice && !isDiscoveryTerminal(serverChoice)) {
        return serverChoice.operation_key;
      }
      const active = payload.operations.find(item => !isDiscoveryTerminal(item));
      return active
        ? active.operation_key
        : payload.operations.length
          ? payload.operations[0].operation_key
          : "";
    },
    reconcileSelection(payload) {
      const selectedStillExists = payload.operations.some(
        item => item.operation_key === this.selectedOperationKey
      );
      if (this.selectionLocked && selectedStillExists) return;
      this.selectedOperationKey = this.defaultOperationKey(payload);
      this.selectionLocked = false;
    },
    selectOperation(key) {
      if (!this.operations.some(item => item.operation_key === key)) return;
      this.selectedOperationKey = key;
      this.selectionLocked = true;
    },
    selectRelative(offset) {
      const index = this.selectedIndex + offset;
      if (index < 0 || index >= this.operations.length) return;
      this.selectOperation(this.operations[index].operation_key);
    },
    returnToLatest() {
      this.selectionLocked = false;
      this.selectedOperationKey = this.defaultOperationKey({
        operations: this.operations,
        selected_operation_key: ""
      });
    },
    refreshAndCalibrate() {
      if (this.manualRefreshing || !this.canView || !this.isPageVisible()) {
        return Promise.resolve(false);
      }
      this.manualRefreshing = true;
      this.calibrationRequested = true;
      this.manualPending.operations = true;
      this.manualPending.announcements = true;
      this.operationsPoller.refresh();
      this.announcementPoller.refresh();
      return Promise.resolve(true);
    },
    completeManualPart(part) {
      this.manualPending[part] = false;
      if (!this.manualPending.operations && !this.manualPending.announcements) {
        this.manualRefreshing = false;
      }
    },
    async openAnnouncement(id) {
      if (!Number.isSafeInteger(id) || id <= 0) return;
      this.announcementVisible = true;
      this.announcementDetailLoading = true;
      this.announcementDetailUnavailable = false;
      try {
        const response = await getSpotListingAnnouncementDetail(id);
        const detail = response && response.code === 200 ? response.data : response;
        if (!detail || typeof detail !== "object") {
          throw new TypeError("invalid spot listing announcement detail");
        }
        this.announcementDetail = detail;
      } catch (error) {
        this.announcementDetailUnavailable = true;
      } finally {
        this.announcementDetailLoading = false;
      }
    }
  }
};
</script>

<style lang="scss" scoped>
.listing-room {
  min-height: calc(100vh - 50px);
  padding: 12px;
  color: #b8c9d2;
  background-color: #050d15;
  background-image:
    linear-gradient(rgba(53, 220, 255, 0.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(53, 220, 255, 0.025) 1px, transparent 1px);
  background-size: 30px 30px;

  &__header {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 20px;
    min-height: 58px;
    margin-bottom: 10px;
    padding: 0 6px 10px;
    border-bottom: 1px solid #204457;
  }

  &__brand > span { color: #35dcff; font-size: 7px; letter-spacing: 0.2em; }
  &__brand h1 { margin: 4px 0 0; color: #f2f8fb; font-size: 21px; }
  &__brand h1 small { margin-left: 6px; color: #68808e; font-size: 9px; font-weight: 400; }

  &__scope { color: #8197a4; font-size: 10px; }
  &__scope i { display: inline-block; width: 3px; height: 3px; margin: 0 8px 2px; border-radius: 50%; background: #35dcff; }

  &__sync { min-width: 0; text-align: right; }
  &__sync strong { display: block; color: #29e59d; font-size: 10px; }
  &__sync span { display: block; overflow: hidden; margin-top: 4px; color: #5d7685; font-size: 8px; text-overflow: ellipsis; white-space: nowrap; }
  &__sync.is-warning strong { color: #ffc857; }

  &__warning {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 36px;
    margin-bottom: 10px;
    padding: 0 12px;
    border: 1px solid rgba(255, 200, 87, 0.4);
    border-radius: 4px;
    color: #ffc857;
    background: rgba(255, 200, 87, 0.07);
    font-size: 10px;
  }

  &__main {
    display: grid;
    grid-template-columns: minmax(620px, 2.35fr) minmax(300px, 0.85fr);
    gap: 10px;
    margin: 10px 0;
  }

  &__ledger {
    overflow: hidden;
    margin-top: 10px;
    border: 1px solid #204457;
    border-radius: 7px;
    background: #09131f;
  }
  &__ledger > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 58px;
    padding: 10px 16px;
    border-bottom: 1px solid #204457;
  }
  &__ledger header span { color: #35dcff; font-size: 7px; letter-spacing: 0.14em; }
  &__ledger h2 { margin: 3px 0 0; color: #eaf5fa; font-size: 15px; }
  &__ledger header small { color: #5e7887; font-size: 9px; }
  &__table-wrap { overflow-x: auto; }
  table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 10px; }
  th { height: 34px; padding: 0 15px; color: #597382; background: #0c1a25; font-weight: 500; text-align: left; }
  td { height: 55px; padding: 7px 15px; border-top: 1px solid #193441; color: #8299a6; }
  tbody tr { cursor: pointer; }
  tbody tr:hover,
  tbody tr.is-selected { background: #10222e; }
  tbody tr.is-selected { box-shadow: inset 3px 0 0 #35dcff; }
  td b { color: #35dcff; }
  td strong { display: block; color: #e7f1f5; font-size: 13px; }
  td small { display: block; overflow: hidden; max-width: 380px; margin-top: 4px; color: #536d7b; text-overflow: ellipsis; white-space: nowrap; }
  td span { color: #718996; }
  td span.is-cyan { color: #35dcff; }
  td span.is-amber { color: #ffc857; }
  td span.is-green { color: #29e59d; }
  td span.is-red { color: #ff657b; }
  time { font-family: "SFMono-Regular", Consolas, monospace; font-variant-numeric: tabular-nums; }
  &__empty { height: 90px; color: #536d7b; text-align: center; }

  &__drawer { padding: 0 24px 30px; }
  &__drawer > span { color: #35dcff; font-size: 11px; font-weight: 700; }
  &__drawer h2 { color: #172733; line-height: 1.45; }
  &__drawer p { color: #5d6d78; line-height: 1.7; }
  &__drawer dl { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  &__drawer dl div { padding: 10px; background: #f3f7f9; }
  &__drawer dt { color: #83939d; font-size: 10px; }
  &__drawer dd { margin: 5px 0 0; font-family: Consolas, monospace; font-size: 12px; }
  &__drawer h3 { margin-top: 24px; font-size: 14px; }
  &__drawer ul { margin: 0; padding: 0; list-style: none; }
  &__drawer li { display: flex; justify-content: space-between; padding: 10px; border-top: 1px solid #e4eaee; }
  &__drawer a { display: inline-block; margin-top: 20px; color: #1688aa; text-decoration: none; }
}

@media (max-width: 1080px) {
  .listing-room__main { grid-template-columns: 1fr; }
  .listing-room__header { grid-template-columns: 1fr; gap: 7px; }
  .listing-room__scope { display: none; }
  .listing-room__sync { text-align: left; }
}

@media (max-width: 620px) {
  .listing-room { padding: 7px; }
  .listing-room__drawer dl { grid-template-columns: 1fr; }
}
</style>
