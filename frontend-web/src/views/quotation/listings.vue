<template>
  <div v-if="canView" class="listing-room">
    <header class="listing-room__header">
      <div class="listing-room__brand">
        <span>全球现货上新监测</span>
        <h1>新币雷达 <small>上币作战室</small></h1>
      </div>
      <div class="listing-room__scope">币安 <i /> OKX <i /> Gate <i /> MEXC <i /> KuCoin</div>
      <div
        class="listing-room__sync"
        :class="{ 'is-warning': authenticationExpired || dataStale || apiUnavailable || sourceCoverageIncomplete }"
      >
        <strong>{{ syncHeadline }}</strong>
        <span>{{ syncDetail }}</span>
      </div>
    </header>

    <div
      v-if="authenticationExpired || apiUnavailable || dataStale || sourceCoverageIncomplete"
      class="listing-room__warning"
      role="status"
    >
      <i class="el-icon-warning-outline" aria-hidden="true" />
      <span v-if="authenticationExpired">登录状态已过期，实时轮询已暂停；重新登录后会从最新数据恢复。</span>
      <span v-else-if="dataStale">雷达数据已超过 2 分钟未更新，画面保留最后一次有效结果，后台将继续自动重试。</span>
      <span v-else-if="apiUnavailable && operationsLoaded">本轮雷达或公告数据更新失败，画面保留最后一次有效结果并自动重试。</span>
      <span v-else-if="sourceCoverageDegraded">投影接口可用，但至少一个关键扫描来源异常；当前不能按正常业务空窗解释。</span>
      <span v-else-if="sourceCoverageInitializing">关键扫描来源尚未全部就绪；当前不能按正常业务空窗解释。</span>
      <span v-else>雷达任务或公告数据暂不可用，后台将继续自动重试。</span>
      <button
        v-if="authenticationExpired"
        type="button"
        class="listing-room__reauthenticate"
        @click="reauthenticate"
      >
        重新登录
      </button>
    </div>

    <source-health-strip
      :sources="sourceHealth"
      :channel-sources="channelHealth"
      :unavailable="operationsUnavailable"
      :stale="dataStale"
    />

    <main class="listing-room__main">
      <radar-stage
        :operation="displayOperation"
        :now-ms="nowMs"
        :has-previous="selectedIndex > 0"
        :has-next="selectedIndex >= 0 && selectedIndex < operations.length - 1"
        :selection-locked="selectionLocked"
        :loading="operationsLoading && !operationsLoaded"
        :refreshing="manualRefreshing"
        :loaded="operationsLoaded"
        :unavailable="operationsUnavailable"
        :coverage-incomplete="sourceCoverageIncomplete"
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
        :loaded="operationsLoaded"
        :unavailable="operationsUnavailable"
        :coverage-incomplete="sourceCoverageIncomplete"
        :truncated="truncated"
        @select="selectOperation"
      />
    </main>

    <intelligence-strip
      :announcements="announcements"
      :operations="operations"
      :now-ms="nowMs"
      :announcement-unavailable="announcementsUnavailable"
      :announcement-loaded="announcementsLoaded"
      :operations-loaded="operationsLoaded"
      :operations-unavailable="operationsUnavailable"
      :operations-coverage-incomplete="sourceCoverageIncomplete"
      @select="selectOperation"
      @announcement="openAnnouncement"
    />

    <section class="listing-room__ledger" aria-labelledby="listing-ledger-title">
      <header>
        <div>
          <span>发现记录台账</span>
          <h2 id="listing-ledger-title">全部发现记录</h2>
        </div>
        <small>共 {{ total }} 项 · {{ truncated ? "当前窗口已截断" : "当前窗口完整" }}</small>
      </header>
      <div class="listing-room__table-wrap">
        <table>
          <thead>
            <tr>
              <th>交易对 / 项目</th>
              <th>交易所</th>
              <th>产品 / 专区</th>
              <th>发现阶段</th>
              <th>计划开盘（北京时间）</th>
              <th>首次发现（北京时间）</th>
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
              <td class="listing-room__pair-cell">
                <strong>{{ pairLabel(operation) }}</strong>
                <small>{{ operation.title || "上币与早期市场机会发现" }}</small>
              </td>
              <td class="listing-room__exchange-cell"><b>{{ platform(operation) }}</b></td>
              <td class="listing-room__classification-cell"><listing-channel-badge :value="operation" compact /></td>
              <td><span :class="`is-${group(operation).tone}`">{{ group(operation).label }}</span></td>
              <td><time>{{ plannedTime(operation) }}</time></td>
              <td><time>{{ formatTime(operation.first_seen_at_ms || operation.detected_at_ms) }}</time></td>
              <td>{{ sourceText(operation) }}</td>
            </tr>
          </tbody>
          <tbody v-else>
            <tr><td colspan="7" class="listing-room__empty">{{ ledgerEmptyText }}</td></tr>
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
      @closed="handleAnnouncementClosed"
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
          <el-alert
            v-if="announcementProjectionNotice"
            :title="announcementProjectionNotice"
            type="warning"
            :closable="false"
            show-icon
          />
          <span>{{ platform(announcementDetail) }}</span>
          <listing-channel-badge :value="announcementDetail" light />
          <h2>{{ announcementDetail.title }}</h2>
          <p>{{ announcementDetail.description || "暂无公告摘要" }}</p>
          <dl>
            <div><dt>官方发布（北京时间）</dt><dd>{{ formatTime(announcementDetail.published_at_ms) }}</dd></div>
            <div><dt>雷达发现（北京时间）</dt><dd>{{ formatTime(announcementDetail.detected_at_ms) }}</dd></div>
            <div><dt>公告开盘时间（北京时间）</dt><dd>{{ announcementPlannedTimeText }}</dd></div>
          </dl>
          <h3>公告涉及的上币项目</h3>
          <ul v-if="announcementPairs.length">
            <li v-for="pair in announcementPairs" :key="pair.symbol">
              <div>
                <strong>{{ pair.symbol }}</strong>
                <listing-channel-badge :value="pair" compact light />
              </div>
              <span>{{ plannedTime(pair) }}</span>
            </li>
          </ul>
          <p v-else>{{ announcementPairsEmptyText }}</p>
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
  ListingChannelBadge,
  RadarStage,
  SourceHealthStrip
} from "@/components/SpotListingDiscovery";
import {
  getSpotListingAnnouncementDetail,
  getSpotListingAnnouncements,
  getSpotListingOperations
} from "@/api/spotListings";
import { createSerialPoller } from "@/utils/serialPoller";
import { AUTH_EXPIRED_EVENT } from "@/utils/request";
import { hasPermission } from "@/utils/permissions";
import {
  announcementProjectionMessage,
  discoverySourceLabel,
  formatListingTime,
  discoveryCoverageState,
  listingPairLabel,
  operationDisplayGroupMeta,
  isDiscoveryTerminal,
  platformName,
  plannedTimeLabel,
  preferredCountdownMission,
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
const RECENT_OPENING_HISTORY_MS = 24 * 60 * 60 * 1000;

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
    ListingChannelBadge,
    RadarStage,
    SourceHealthStrip
  },
  data() {
    return {
      operations: [],
      summary: { ...EMPTY_SUMMARY },
      sourceHealth: [],
      channelHealth: [],
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
      announcementsLoaded: false,
      announcementsUnavailable: false,
      announcementVisible: false,
      announcementDetailLoading: false,
      announcementDetailUnavailable: false,
      announcementDetail: null,
      announcementDetailRequestId: 0,
      manualRefreshing: false,
      calibrationRequested: false,
      manualPending: {
        operations: false,
        announcements: false
      },
      authenticationExpired: false,
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
    activeMission() {
      return preferredCountdownMission(this.operations, this.nowMs);
    },
    displayOperation() {
      if (this.selectedIndex >= 0) return this.operations[this.selectedIndex];
      return this.activeMission || this.fallbackDisplayOperation(this.operations);
    },
    dataStale() {
      return this.generatedAtMs > 0 && this.nowMs - this.generatedAtMs >= STALE_AFTER_MS;
    },
    apiUnavailable() {
      return this.operationsUnavailable || this.announcementsUnavailable;
    },
    sourceCoverageState() {
      return discoveryCoverageState(this.sourceHealth, this.channelHealth);
    },
    sourceCoverageDegraded() {
      return this.operationsLoaded && this.sourceCoverageState === "degraded";
    },
    sourceCoverageInitializing() {
      return this.operationsLoaded && this.sourceCoverageState === "initializing";
    },
    sourceCoverageIncomplete() {
      return this.sourceCoverageDegraded || this.sourceCoverageInitializing;
    },
    syncHeadline() {
      if (this.authenticationExpired) return "登录已过期 · 雷达已暂停";
      if (this.apiUnavailable || this.dataStale) return "自动更新重试中";
      if (!this.operationsLoaded) return "正在建立雷达视图";
      if (this.sourceCoverageDegraded) return "投影可用 · 来源异常";
      if (this.sourceCoverageInitializing) return "正在建立来源覆盖";
      return "发现引擎运行中";
    },
    syncDetail() {
      if (this.authenticationExpired) {
        return "已停止任务与公告请求，重新登录后自动恢复实时数据";
      }
      const synced = this.lastSyncedAtMs ? this.formatTime(this.lastSyncedAtMs) : "等待首次数据";
      const calibrated = this.lastCalibratedAtMs
        ? this.formatTime(this.lastCalibratedAtMs)
        : "等待首次校准";
      return `任务 5 秒、公告 30 秒自动更新 · 页面时间 北京时间 · 最近任务 ${synced} · 时间校准 ${calibrated}`;
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
    },
    announcementProjectionNotice() {
      return announcementProjectionMessage(this.announcementDetail);
    },
    announcementPairsEmptyText() {
      return this.announcementProjectionNotice
        ? "公告已修订，旧交易对、关联和计划时间已撤销；等待可信新版本。"
        : "公告尚未给出可安全关联的交易对；开盘时间会在官方补充后自动更新。";
    },
    announcementPlannedTimeText() {
      if (!this.announcementDetail) return "--";
      const direct = this.announcementDetail.announced_trading_start_at_ms;
      if (Number.isSafeInteger(direct) && direct > 0) {
        return this.formatTime(direct);
      }
      const times = Array.from(new Set(
        this.announcementPairs
          .map(pair => pair.announced_trading_start_at_ms)
          .filter(timestamp => Number.isSafeInteger(timestamp) && timestamp > 0)
      ));
      if (times.length === 1) return this.formatTime(times[0]);
      if (times.length > 1) return "各交易对时间不同，详见下方";
      return "平台尚未公布";
    },
    ledgerEmptyText() {
      if (this.operationsUnavailable && !this.operationsLoaded) {
        return "发现数据暂不可用，后台自动重试中";
      }
      if (!this.operationsLoaded) return "正在载入发现台账";
      if (this.operationsUnavailable || this.dataStale) {
        return "当前显示上次有效状态，无法确认是否为业务空窗";
      }
      if (this.sourceCoverageIncomplete) {
        return "数据来源尚未完整可用，当前无法确认是否为业务空窗";
      }
      return "发现窗口内暂无上币机会记录";
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
    window.addEventListener(AUTH_EXPIRED_EVENT, this.handleAuthenticationExpired);
    if (this.isPageVisible()) this.startTicker();
    this.syncPolling();
  },
  beforeDestroy() {
    this.disposed = true;
    this.stopPolling();
    this.stopTicker();
    document.removeEventListener("visibilitychange", this.handleVisibilityChange);
    window.removeEventListener(AUTH_EXPIRED_EVENT, this.handleAuthenticationExpired);
  },
  methods: {
    formatTime: formatListingTime,
    pairLabel: listingPairLabel,
    plannedTime: plannedTimeLabel,
    sourceText: discoverySourceLabel,
    group(operation) {
      return operationDisplayGroupMeta(operation, this.nowMs);
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
      this.reconcileAutomaticSelectionAtClock();
    },
    reconcileAutomaticSelectionAtClock() {
      if (this.selectionLocked) return;
      const selected = this.operations.find(
        item => item.operation_key === this.selectedOperationKey
      );
      const next = preferredCountdownMission(this.operations, this.nowMs);
      if (next && selected && next.operation_key === selected.operation_key) return;
      const fallback = next || this.fallbackDisplayOperation(this.operations);
      this.selectedOperationKey = fallback ? fallback.operation_key : "";
    },
    syncPolling() {
      if (
        !this.canView ||
        this.disposed ||
        this.authenticationExpired ||
        !this.isPageVisible()
      ) {
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
    handleAuthenticationExpired() {
      if (this.authenticationExpired) return;
      this.authenticationExpired = true;
      this.manualRefreshing = false;
      this.manualPending.operations = false;
      this.manualPending.announcements = false;
      this.stopPolling();
    },
    async reauthenticate() {
      this.stopPolling();
      try {
        await this.$store.dispatch("user/resetToken");
        window.location.reload();
        return true;
      } catch (error) {
        return false;
      }
    },
    async pollOperations() {
      const manual = this.manualPending.operations;
      const recalibrate = this.calibrationRequested;
      const result = await this.loadOperations(recalibrate);
      if (result && recalibrate) {
        this.calibrationRequested = false;
      }
      if (manual) {
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
        this.channelHealth = payload.channel_health || [];
        this.total = payload.total;
        this.truncated = payload.truncated;
        this.generatedAtMs = payload.generated_at_ms;
        if (!this.clockCalibrated || recalibrate) {
          const midpoint = Math.round(
            requestStartedAt + (requestFinishedAt - requestStartedAt) / 2
          );
          this.clockOffsetMs = payload.server_time_ms - midpoint;
          this.clockCalibrated = true;
          this.lastCalibratedAtMs = Math.round(
            requestFinishedAt + this.clockOffsetMs
          );
          this.tickClock();
        }
        this.lastSyncedAtMs = Math.round(
          requestFinishedAt + this.clockOffsetMs
        );
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
        this.announcementsLoaded = true;
        this.announcementsUnavailable = false;
        return true;
      } catch (error) {
        this.announcementsUnavailable = true;
        return false;
      }
    },
    activeMissionKey(payload) {
      const serverChoice = payload.operations.find(
        item => item.operation_key === payload.selected_operation_key
      );
      const active = preferredCountdownMission(payload.operations, this.nowMs);
      if (
        active &&
        serverChoice &&
        active.operation_key === serverChoice.operation_key
      ) {
        return serverChoice.operation_key;
      }
      return active ? active.operation_key : "";
    },
    operationRecency(operation) {
      const lifecycleTimes = Array.isArray(operation.lifecycle)
        ? operation.lifecycle.map(node => node.at_ms)
        : [];
      return [
        operation.first_seen_at_ms,
        operation.detected_at_ms,
        operation.published_at_ms,
        operation.planned_start_at_ms,
        ...lifecycleTimes
      ].reduce(
        (latest, timestamp) =>
          Number.isSafeInteger(timestamp) && timestamp > latest
            ? timestamp
            : latest,
        0
      );
    },
    newestOperation(operations) {
      return operations.slice().sort((left, right) => {
        const timeDelta = this.operationRecency(right) - this.operationRecency(left);
        if (timeDelta !== 0) return timeDelta;
        return String(left.operation_key).localeCompare(String(right.operation_key));
      })[0] || null;
    },
    fallbackDisplayOperation(operations) {
      const recentlyOpenedWithTime = operations.filter(
        item =>
          item.exchange_status === "trading" &&
          Number.isSafeInteger(item.planned_start_at_ms) &&
          item.planned_start_at_ms <= this.nowMs &&
          item.planned_start_at_ms >= this.nowMs - RECENT_OPENING_HISTORY_MS
      );
      return this.newestOperation(recentlyOpenedWithTime);
    },
    defaultDisplayOperationKey(payload) {
      const activeKey = this.activeMissionKey(payload);
      if (activeKey) return activeKey;
      const fallback = this.fallbackDisplayOperation(payload.operations);
      return fallback ? fallback.operation_key : "";
    },
    reconcileSelection(payload) {
      const selected = payload.operations.find(
        item => item.operation_key === this.selectedOperationKey
      );
      if (this.selectionLocked && selected) {
        if (!isDiscoveryTerminal(selected)) return;
        const next = preferredCountdownMission(payload.operations, this.nowMs);
        this.selectedOperationKey = next ? next.operation_key : "";
        this.selectionLocked = false;
        return;
      }
      this.selectedOperationKey = this.defaultDisplayOperationKey(payload);
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
      this.selectedOperationKey = this.defaultDisplayOperationKey({
        operations: this.operations,
        selected_operation_key: ""
      });
    },
    refreshAndCalibrate() {
      if (
        this.manualRefreshing ||
        this.authenticationExpired ||
        !this.canView ||
        !this.isPageVisible()
      ) {
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
      const requestId = ++this.announcementDetailRequestId;
      this.announcementVisible = true;
      this.announcementDetailLoading = true;
      this.announcementDetailUnavailable = false;
      this.announcementDetail = null;
      try {
        const response = await getSpotListingAnnouncementDetail(id);
        const detail = response && response.code === 200 ? response.data : response;
        if (!detail || typeof detail !== "object") {
          throw new TypeError("invalid spot listing announcement detail");
        }
        if (
          this.disposed ||
          requestId !== this.announcementDetailRequestId ||
          !this.announcementVisible
        ) {
          return;
        }
        this.announcementDetail = detail;
      } catch (error) {
        if (
          this.disposed ||
          requestId !== this.announcementDetailRequestId ||
          !this.announcementVisible
        ) {
          return;
        }
        this.announcementDetailUnavailable = true;
      } finally {
        if (
          requestId === this.announcementDetailRequestId &&
          this.announcementVisible
        ) {
          this.announcementDetailLoading = false;
        }
      }
    },
    handleAnnouncementClosed() {
      this.announcementDetailRequestId += 1;
      this.announcementVisible = false;
      this.announcementDetail = null;
      this.announcementDetailLoading = false;
      this.announcementDetailUnavailable = false;
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
    min-height: 70px;
    margin-bottom: 10px;
    padding: 2px 8px 12px;
    border-bottom: 1px solid #204457;
  }

  &__brand > span { color: #35dcff; font-size: 12px; line-height: 1.3; letter-spacing: 0.18em; }
  &__brand h1 { margin: 5px 0 0; color: #f2f8fb; font-size: 28px; line-height: 1.2; }
  &__brand h1 small { margin-left: 8px; color: #8299a6; font-size: 13px; font-weight: 400; }

  &__scope { color: #9bb0bb; font-size: 13px; }
  &__scope i { display: inline-block; width: 3px; height: 3px; margin: 0 8px 2px; border-radius: 50%; background: #35dcff; }

  &__sync { min-width: 0; text-align: right; }
  &__sync strong { display: block; color: #29e59d; font-size: 14px; line-height: 1.35; }
  &__sync span { display: block; overflow: hidden; margin-top: 5px; color: #7893a2; font-size: 12px; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
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
    font-size: 12px;
    line-height: 1.45;
  }
  &__reauthenticate {
    margin-left: auto;
    padding: 5px 12px;
    border: 1px solid rgba(255, 200, 87, 0.55);
    border-radius: 3px;
    color: #ffc857;
    background: rgba(255, 200, 87, 0.08);
    cursor: pointer;
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
  &__ledger header span { color: #35dcff; font-size: 12px; letter-spacing: 0.08em; }
  &__ledger h2 { margin: 3px 0 0; color: #eaf5fa; font-size: 15px; }
  &__ledger header small { color: #7893a2; font-size: 12px; }
  &__table-wrap { overflow-x: auto; }
  table { width: 100%; min-width: 1240px; border-collapse: collapse; font-size: 12px; }
  th { height: 40px; padding: 0 15px; color: #7893a2; background: #0c1a25; font-weight: 600; text-align: left; }
  td { height: 62px; padding: 8px 15px; border-top: 1px solid #193441; color: #8299a6; }
  tbody tr { cursor: pointer; }
  tbody tr:hover,
  tbody tr.is-selected { background: #10222e; }
  tbody tr.is-selected { box-shadow: inset 3px 0 0 #35dcff; }
  td b { color: #35dcff; }
  &__pair-cell { min-width: 270px; }
  &__pair-cell strong {
    display: block;
    color: #f2f8fb;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: 0.015em;
  }
  &__exchange-cell { width: 100px; }
  &__exchange-cell b { font-size: 13px; font-weight: 800; }
  &__classification-cell { min-width: 230px; }
  td small { display: block; overflow: hidden; max-width: 380px; margin-top: 4px; color: #7893a2; text-overflow: ellipsis; white-space: nowrap; }
  td span { color: #718996; }
  td span.is-cyan { color: #35dcff; }
  td span.is-amber { color: #ffc857; }
  td span.is-green { color: #29e59d; }
  td span.is-red { color: #ff657b; }
  time { font-family: "SFMono-Regular", Consolas, monospace; font-variant-numeric: tabular-nums; }
  &__empty { height: 90px; color: #536d7b; text-align: center; }

  &__drawer { padding: 0 24px 30px; }
  &__drawer > span { color: #35dcff; font-size: 12px; font-weight: 700; }
  &__drawer h2 { color: #172733; line-height: 1.45; }
  &__drawer p { color: #5d6d78; line-height: 1.7; }
  &__drawer dl { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  &__drawer dl div { padding: 10px; background: #f3f7f9; }
  &__drawer dt { color: #83939d; font-size: 12px; }
  &__drawer dd { margin: 5px 0 0; font-family: Consolas, monospace; font-size: 12px; }
  &__drawer h3 { margin-top: 24px; font-size: 14px; }
  &__drawer ul { margin: 0; padding: 0; list-style: none; }
  &__drawer li { display: flex; justify-content: space-between; padding: 10px; border-top: 1px solid #e4eaee; }
  &__drawer li > div { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
  &__drawer a { display: inline-block; margin-top: 20px; color: #1688aa; text-decoration: none; }
}

@media (max-width: 1280px) {
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
