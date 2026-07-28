<style lang="scss" scoped>
.price-red {
  /* element-ui 元素*/
  font-size: larger;
}
.price-yellow {
  color: dodgerblue;
}
#searchBox {
  /*overflow: hidden;*/
}
/* 禁用表格内部滚动条，使用浏览器滚动条 */

.el-table {
  width: 100% !important;
  min-width: max-content;
}
.el-table__body-wrapper {
  overflow-x: auto;
  overflow-y: auto;
}
.el-table__header-wrapper {
  overflow-x: auto;
}

.kline-diff {
  display: flex;
  flex-direction: row;
  justify-content: space-between;
  .kline-item {
    width: 49.5%;
  }
}

::v-deep {
  .el-table {
    td {
      &.total-green {
        background-color: rgba(0, 150, 135, 0.33) !important;
        color: #606266 !important;
      }

      &.total-red {
        background-color: #cd0000 !important;
        color: #fff !important;
      }

      &.total-yellow {
        background-color: #bdb76b !important;
        color: #606266 !important;
      }
    }
  }
}

.ellipsis-2-lines {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  text-overflow: ellipsis;
  word-break: break-all; /* 防止长单词撑破布局 */
}

.custom-menu {
  position: fixed;
  background: #fff;
  border: 1px solid #ccc;
  border-radius: 4px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  padding: 4px 0;
  min-width: 120px;
  z-index: 9999;
}

.menu-item {
  padding: 8px 16px;
  cursor: pointer;
  font-size: 14px;
}

.menu-item:hover {
  background: #f0f0f0;
}

.menu-divider {
  height: 1px;
  background: #e0e0e0;
  margin: 4px 0;
}
.flex-center {
  display: flex;
  white-space: nowrap;
  align-items: center;
  margin-bottom: 10px;
}
.fixed-filter {
  position: fixed;
  top: 89px;
  padding: 5px 0;
  z-index: 9;
  background: #fff;
}

.flex-space {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
@media (max-width: 768px) {
  .kline-diff {
    display: block;
    .kline-item {
      width: 100%;
    }
  }
}
</style>
<template>
  <div class="app-container">
    <div
      class="filter-container"
      :class="{
        'fixed-filter': fixedfilter,
      }"
    >
      <el-input
        v-model="query.symbol"
        clearable
        placeholder="查询币种"
        :size="is_mobile ? 'small' : 'default'"
        style="width: 200px"
        class="filter-item"
        @keyup.enter.native="handleFilter"
      />
      <el-button
        class="filter-item"
        :size="is_mobile ? 'small' : 'default'"
        type="primary"
        icon="el-icon-search"
        @click="handleFilter"
      >
        搜索
      </el-button>
      <span>自动刷新</span>
      <el-select
        v-model="second"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="秒数"
        style="width: 100px"
        class="filter-item"
        @change="changeSecond"
      >
        <el-option :key="1000" :value="1000" label="1秒" />
        <el-option :key="3000" :value="3000" label="3秒" />
        <el-option :key="5000" :value="5000" label="5秒" />
        <el-option :key="10000" :value="10000" label="10秒" />
        <el-option :key="15000" :value="15000" label="15秒" />
        <el-option :key="30000" :value="30000" label="30秒" />
      </el-select>

      <el-switch
        v-model="refresh_button"
        :size="is_mobile ? 'small' : 'default'"
        active-color="#13ce66"
        inactive-color="#999"
        :active-value="1"
        :inactive-value="2"
        @change="openRefresh"
      />
      <el-select
        v-model="query.diff_price"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="差价大于"
        clearable
        style="width: 100px"
        class="filter-item"
        @change="handleFilter"
      >
        <el-option
          v-for="item in diffList"
          :key="item.key"
          :label="item.display_name"
          :value="item.key"
        />
      </el-select>
      <el-input
        v-model="query.total_price"
        clearable
        :size="is_mobile ? 'small' : 'default'"
        placeholder="总价大于"
        style="width: 120px"
        class="filter-item"
        @blur="handleFilter"
      />
      <span style="margin-right: 5px; margin-left: 5px">
        杠杆
        <el-switch
          v-model="query.is_margin"
          active-color="#13ce66"
          inactive-color="#999"
          :active-value="1"
          :inactive-value="0"
          @change="handleFilter"
        />
      </span>
      <span>右边表格保存</span>
      <el-select
        v-model="right_keep_num"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="右边保持多少数据"
        style="width: 100px"
        class="filter-item"
        @change="changeSaveNum"
      >
        <el-option :key="10" :value="10" label="10条" />
        <el-option :key="20" :value="20" label="20条" />
        <el-option :key="30" :value="30" label="30条" />
        <el-option :key="40" :value="40" label="40条" />
        <el-option :key="50" :value="50" label="50条" />
      </el-select>
      <el-button
        type="primary"
        :size="is_mobile ? 'small' : 'default'"
        @click="onSetFee(true)"
        >设置交易手续费</el-button
      >
      <el-button
        type="success"
        :size="is_mobile ? 'small' : 'default'"
        @click="onSaveTempFilter()"
        >保存临时过滤({{
          tempfiltersymbol > 99 ? "99+" : tempfiltersymbol
        }})</el-button
      >
      <el-button
        type="info"
        :size="is_mobile ? 'small' : 'default'"
        @click="queryOrClearTempFilter()"
        >查询临时过滤</el-button
      >

      <el-button
        type="primary"
        :size="is_mobile ? 'small' : 'default'"
        @click="closeOpenFixed()"
        >{{ fixedfilter == 1 ? "关闭" : "开启" }}固定</el-button
      >
      <el-button
        type="danger"
        :size="is_mobile ? 'small' : 'default'"
        @click="clearLocalStorage('diff_table_temp_list', 'list_temp')"
        >清空右边表格数据</el-button
      >
    </div>
    <div>
      <el-button
        id="closeSearchBtn"
        :size="is_mobile ? 'small' : 'default'"
        type="text"
        style="margin-left: 10px"
        @click="closeSearch"
      >
        {{ word }}
        <i :class="showAll ? 'el-icon-arrow-up ' : 'el-icon-arrow-down'" />
      </el-button>
    </div>
    <div v-show="showAll" id="searchBox">
      <div class="filter-container" style="padding-bottom: 5px">
        <div style="padding-bottom: 5px">
          <div style="padding-top: 5px">
            <span> 过滤平台 </span>
            <el-checkbox
              v-for="item in platformList"
              :key="item.key"
              v-model="query.platform"
              :label="item.key"
              class="filter-item"
              style="margin-left: 15px"
              @change="handlePlatformFilter"
            >
              {{ item.item }}
            </el-checkbox>
          </div>
        </div>
      </div>
      <template style="width: 200px">
        <div>
          <multiselect
            v-model="query.block_symbol"
            :size="is_mobile ? 'small' : 'default'"
            tag-placeholder="添加过滤交易对"
            placeholder="搜索并过滤交易对"
            :options="options"
            :multiple="true"
            :taggable="true"
            @input="addTag"
          />
        </div>
      </template>
      <!-- <div class="filter-container" style="padding-bottom: 5px">
        <div style="padding-bottom: 5px">
          <div style="padding-top: 10px">
            <span> 展示列 </span>
            <el-checkbox
              v-for="(item, index) in lists"
              :key="item.key"
              v-model="lists[index].ispass"
              :label="item.label"
              style="margin-left: 15px"
              @change="changeSort"
            >
              {{ item.label }}
            </el-checkbox>
          </div>
        </div>
      </div> -->
    </div>
    <!--
    <el-table
      v-loading="loading"
      class="no-drag"
      :data="list.data"
      element-loading-text="Loading"
      :cell-style="cellStyle"
      :cell-class-name="cellClassName"
      border
      fit
      highlight-current-row
    > -->
    <div style="display: flex; gap: 10px">
      <div>
        <div
          v-show="showAll"
          class="filter-container"
          style="padding-bottom: 5px"
        >
          <div style="padding-bottom: 5px">
            <div style="padding-top: 10px">
              <span> 展示列 </span>
              <el-checkbox
                v-for="(item, index) in lists"
                :key="item.key"
                v-model="lists[index].ispass"
                :label="item.label"
                style="margin-left: 15px"
                @change="changeSort"
              >
                {{ item.label }}
              </el-checkbox>
            </div>
          </div>
        </div>
        <el-table
          :key="tableKey"
          :data="list.data"
          element-loading-text="Loading"
          border
          row-key="id"
          :cell-class-name="cellClassName"
          :fit="false"
          highlight-current-row
          @cell-click="cellClick"
          @header-dragend="handleHeaderDragend"
        >
          <el-table-column
            v-if="lists[6].ispass"
            :key="'collect'"
            label=""
            prop="collect"
            :width="getWidth('collect', 40)"
            align="center"
          >
            <template slot-scope="scope">
              <img
                v-if="scope.row.is_collect == 0"
                src="@/assets/collect.png"
                style="width: 20px; height: 20px; cursor: pointer"
                @click="onCollect(scope.row)"
              />
              <img
                v-else
                src="@/assets/collect_act.png"
                style="width: 20px; height: 20px; cursor: pointer"
                @click="onCollect(scope.row)"
              />
            </template>
          </el-table-column>
          <el-table-column
            label="交易对"
            prop="symbol"
            :width="getWidth('symbol', 130)"
            align="center"
          >
            <template slot-scope="scope">
              <span class="symbol-link" @click="copyText(scope.row.symbol)">
                {{ scope.row.symbol }}
              </span>
            </template>
          </el-table-column>

          <el-table-column
            label="买入平台"
            prop="platform_buy"
            :width="getWidth('platform_buy', 100)"
            align="center"
          >
            <template slot-scope="scope">
              <div
                style="color: blue; cursor: pointer"
                @click="jumpLink(scope.row.buy_platform, scope.row.symbol)"
              >
                {{ scope.row.platform_buy }}
                {{ scope.row.quote_name == "USDT" ? "" : scope.row.quote_name }}
              </div>
            </template>
          </el-table-column>
          <el-table-column
            label="卖出平台"
            prop="platform_sell"
            :width="getWidth('platform_sell', 100)"
            align="center"
          >
            <template slot-scope="scope">
              <div
                style="
                  color: blue;
                  display: flex;
                  align-items: center;
                  justify-content: center;
                "
                @click="jumpLink(scope.row.sell_platform, scope.row.symbol)"
              >
                {{ scope.row.platform_sell }}
                {{
                  scope.row.sell_quote_name == "USDT"
                    ? ""
                    : scope.row.sell_quote_name
                }}
                <img
                  v-if="scope.row.sell_platform_margin"
                  src="@/assets/margin_status.png"
                  style="width: 20px; height: 20px; margin-left: 5px"
                />
              </div>
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[8].ispass"
            label="价格差"
            prop="price_diff"
            :width="getWidth('price_diff', 110)"
            align="center"
          >
            <template slot-scope="scope">
              <span class="price-diff-red-link">{{
                scope.row.price_diff
              }}</span>
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[9].ispass"
            label="买入价格"
            prop="buy_price_fmt"
            :width="getWidth('buy_price_fmt', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.buy_price_fmt | formatSmartDecimalFilters }}
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[10].ispass"
            label="卖出价格"
            prop="sell_price_fmt"
            :width="getWidth('sell_price_fmt', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.sell_price_fmt | formatSmartDecimalFilters }}
            </template>
          </el-table-column>

          <el-table-column
            v-if="lists[0].ispass"
            :key="'buy_num'"
            label="买入数量"
            prop="buy_num"
            :width="getWidth('buy_num', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.buy_num | toFloat }}
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[1].ispass"
            :key="'sell_num'"
            label="卖出数量"
            prop="sell_num"
            :width="getWidth('sell_num', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.sell_num | toFloat }}
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[4].ispass"
            :key="'updated_at'"
            align="center"
            prop="updated_at"
            label="更新时间"
            :width="getWidth('updated_at', 100)"
          >
            <template slot-scope="scope">
              <span>{{ scope.row.updated_at | coverDataTime }}</span>
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[2].ispass"
            :key="'total_buy_price'"
            label="买入总价(USDT)"
            :width="getWidth('total_buy_price', 130)"
            prop="total_buy_price"
            align="center"
          >
            <template slot-scope="scope">
              <span
                style="cursor: pointer"
                @click="checkWithOpenUrl(scope.row.buy_platform, 1)"
              >
                {{ scope.row.total_buy_price | toRound }}</span
              >
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[3].ispass"
            :key="'total_sell_price'"
            label="卖出总价(USDT)"
            :width="getWidth('total_sell_price', 130)"
            align="center"
            prop="total_sell_price"
          >
            <template slot-scope="scope">
              <span
                style="cursor: pointer"
                @click="checkWithOpenUrl(scope.row.sell_platform, 2)"
              >
                <!-- {{ scope.row.sell_price_rmb }} -->
                {{ scope.row.total_sell_price | toRound }}
              </span>
            </template>
          </el-table-column>
          <el-table-column
            v-if="false"
            :key="'lossgiftfee'"
            label="盈亏(没算提币手续费)"
            :width="getWidth('lossgiftfee', 130)"
            align="center"
            prop="lossgiftfee"
          >
            <template slot-scope="scope">
              <span style="color: red; font-weight: bold; font-size: 16px">
                {{ getLossGiftFee(scope.row) }} U</span
              >
            </template>
          </el-table-column>
          <!-- <el-table-column label="K线深度" width="100" align="center">
        <template slot-scope="scope">
          <el-button
            type="primary"
            plain
            size="mini"
            @click="handleKline(scope.row)"
          >
            对比K线图
          </el-button>
        </template>
      </el-table-column> -->
          <el-table-column
            v-if="lists[5].ispass"
            :key="'id'"
            align="center"
            label="ID"
            prop="id"
            :width="getWidth('id', 80)"
          >
            <template slot-scope="scope">
              {{ scope.row.id }}
            </template>
          </el-table-column>
          <!-- <el-table-column label="备注" width="80" align="center">
        <template slot-scope="scope">
          <span @click="onRemark(scope)">{{ scope.row.remark }}</span>
          <input
            v-model="remark"
            placeholder="输入备注"
            @blur="onEditRemark(scope)"
          />
        </template>
      </el-table-column> -->
          <el-table-column
            v-if="lists[11].ispass"
            label="备注"
            align="center"
            prop="remark"
            :width="getWidth('remark', 120)"
          >
            <template slot-scope="scope">
              <span
                v-show="!scope.row['is_remark']"
                style="width: 100%; display: block; height: auto"
                >{{ scope.row.remark }}</span
              >
              <el-input
                v-show="scope.row['is_remark'] === true"
                :ref="`inp-${scope.row.id}`"
                v-model="scope.row.remark"
                focus
                @blur="onRemarkBlur(scope.row)"
              />
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists[7].ispass"
            key="withdraw"
            align="center"
            label="链信息(买/卖)"
            prop="withdraw"
            :width="getWidth('withdraw', 180)"
          >
            <template slot-scope="scope">
              <div
                v-if="
                  !scope.row.buy_withdraw_info_text.length &&
                  !scope.row.sell_withdraw_info_text.length
                "
              >
                无信息
              </div>
              <div
                v-else
                :class="{
                  'ellipsis-2-lines': chain_index !== scope.$index,
                }"
                style="cursor: pointer"
                @click="handleWithdraw(scope.row)"
                @mouseover="handleMouseOver(scope.row, scope.$index)"
                @mouseleave="handleMouseLeave(scope.row, scope.$index)"
              >
                <span v-if="!scope.row.buy_withdraw_info_text.length">
                  无信息
                </span>
                <span
                  v-for="(item, index) in scope.row.buy_withdraw_info_text"
                  :key="index"
                >
                  <span
                    :class="{
                      'chain-green': item.is_withdraw == 1,
                      'chain-red': item.is_withdraw != 1,
                    }"
                    >{{ item.network }}</span
                  >
                  <span
                    v-if="index < scope.row.buy_withdraw_info_text.length - 1"
                    >,</span
                  >
                </span>
                <span>买 - 卖</span>
                <span
                  v-for="(item, index) in scope.row.sell_withdraw_info_text"
                  :key="index"
                >
                  <span
                    :class="{
                      'chain-green': item.is_deposit == 1,
                      'chain-red': item.is_deposit != 1,
                    }"
                    >{{ item.network }}</span
                  >
                  <span
                    v-if="index < scope.row.sell_withdraw_info_text.length - 1"
                    >,</span
                  >
                </span>
                <span v-if="!scope.row.sell_withdraw_info_text.length">
                  无信息
                </span>
              </div>
            </template>
          </el-table-column>

          <!-- <el-table-column label="是否可借" width="80" align="center">
        <template slot-scope="scope">
          <span v-if="scope.row.margin_status == 0" style="color: gray"
            >未知</span
          >
          <span v-if="scope.row.margin_status == 1" style="color: green"
            >可借</span
          >
          <span v-if="scope.row.margin_status == 2" style="color: red"
            >不可</span
          >
        </template>
      </el-table-column> -->
          <el-table-column
            label="过滤"
            :width="getWidth('filter', 140)"
            prop="filter"
          >
            <template slot-scope="scope">
              <el-switch
                v-model="scope.row['block_status']"
                active-color="#13ce66"
                inactive-color="#999"
                :active-value="false"
                :inactive-value="true"
                @change="filterTemp(scope.row, scope.$index)"
              />

              <el-button
                style="margin-left: 10px"
                size="mini"
                type="success"
                plain
                @click="filterId(scope.row.id)"
                >隐藏</el-button
              >
              <!-- <el-button
            size="mini"
            type="success"
            plain
            @click="handleWithdraw(scope.row)"
          >链信息查询</el-butt>
          </el-button> -->
            </template>
          </el-table-column>
        </el-table>
      </div>
      <div>
        <div v-show="showAll" style="padding-bottom: 5px">
          <div style="padding-bottom: 5px">
            <div style="padding-top: 10px">
              <span> 展示列 </span>
              <el-checkbox
                v-for="(item, index) in lists_temp"
                :key="item.key"
                v-model="item.ispass"
                :label="item.label"
                style="margin-left: 15px"
                @change="changeSortTemp"
              >
                {{ item.label }}
              </el-checkbox>
            </div>
          </div>
        </div>
        <el-table
          :data="list_temp"
          element-loading-text="Loading"
          border
          row-key="id"
          :cell-class-name="cellClassName"
          :fit="false"
          highlight-current-row
          @cell-click="cellClickRight"
          @header-dragend="handleHeaderDragendRight"
        >
          <el-table-column
            v-if="lists_temp[6].ispass"
            :key="'collect'"
            label=""
            prop="collect"
            :width="getWidth('collect', 40)"
            align="center"
          >
            <template slot-scope="scope">
              <img
                v-if="scope.row.is_collect == 0"
                src="@/assets/collect.png"
                style="width: 20px; height: 20px; cursor: pointer"
                @click="onCollect(scope.row)"
              />
              <img
                v-else
                src="@/assets/collect_act.png"
                style="width: 20px; height: 20px; cursor: pointer"
                @click="onCollect(scope.row)"
              />
            </template>
          </el-table-column>
          <el-table-column
            label="交易对"
            prop="symbol"
            :width="getWidth('symbol', 130)"
            align="center"
          >
            <template slot-scope="scope">
              <span class="symbol-link" @click="copyText(scope.row.symbol)">
                {{ scope.row.symbol }}
              </span>
            </template>
          </el-table-column>

          <el-table-column
            label="买入平台"
            prop="platform_buy"
            :width="getWidth('platform_buy', 100)"
            align="center"
          >
            <template slot-scope="scope">
              <div
                style="cursor: pointer; color: blue"
                @click="jumpLink(scope.row.buy_platform, scope.row.symbol)"
              >
                {{ scope.row.platform_buy }}
                {{ scope.row.quote_name == "USDT" ? "" : scope.row.quote_name }}
              </div>
            </template>
          </el-table-column>
          <el-table-column
            label="卖出平台"
            prop="platform_sell"
            :width="getWidth('platform_sell', 100)"
            align="center"
          >
            <template slot-scope="scope">
              <div
                style="
                  cursor: pointer;
                  color: blue;
                  display: flex;
                  align-items: center;
                  justify-content: center;
                "
                @click="jumpLink(scope.row.sell_platform, scope.row.symbol)"
              >
                {{ scope.row.platform_sell }}
                {{
                  scope.row.sell_quote_name == "USDT"
                    ? ""
                    : scope.row.sell_quote_name
                }}
                <img
                  v-if="scope.row.sell_platform_margin"
                  src="@/assets/margin_status.png"
                  style="width: 20px; height: 20px; margin-left: 5px"
                />
              </div>
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[8].ispass"
            label="价格差"
            prop="price_diff"
            :width="getWidth('price_diff', 110)"
            align="center"
          >
            <template slot-scope="scope">
              <span class="price-diff-red-link">{{
                scope.row.price_diff
              }}</span>
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[9].ispass"
            label="买入价格"
            prop="buy_price_fmt"
            :width="getWidth('buy_price_fmt', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.buy_price_fmt | formatSmartDecimalFilters }}
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[10].ispass"
            label="卖出价格"
            prop="sell_price_fmt"
            :width="getWidth('sell_price_fmt', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.sell_price_fmt | formatSmartDecimalFilters }}
            </template>
          </el-table-column>

          <el-table-column
            v-if="lists_temp[0].ispass"
            :key="'buy_num'"
            label="买入数量"
            prop="buy_num"
            :width="getWidth('buy_num', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.buy_num | toFloat }}
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[1].ispass"
            :key="'sell_num'"
            label="卖出数量"
            prop="sell_num"
            :width="getWidth('sell_num', 150)"
            align="center"
          >
            <template slot-scope="scope">
              {{ scope.row.sell_num | toFloat }}
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[4].ispass"
            :key="'updated_at'"
            align="center"
            prop="updated_at"
            label="更新时间"
            :width="getWidth('updated_at', 100)"
          >
            <template slot-scope="scope">
              <span>{{ scope.row.updated_at | coverDataTime }}</span>
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[2].ispass"
            :key="'total_buy_price'"
            label="买入总价(USDT)"
            :width="getWidth('total_buy_price', 130)"
            prop="total_buy_price"
            align="center"
          >
            <template slot-scope="scope">
              <span
                style="cursor: pointer"
                @click="checkWithOpenUrl(scope.row.buy_platform, 1)"
              >
                {{ scope.row.total_buy_price | toRound }}</span
              >
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[3].ispass"
            :key="'total_sell_price'"
            label="卖出总价(USDT)"
            :width="getWidth('total_sell_price', 130)"
            align="center"
            prop="total_sell_price"
          >
            <template slot-scope="scope">
              <span
                style="cursor: pointer"
                @click="checkWithOpenUrl(scope.row.sell_platform, 2)"
              >
                <!-- {{ scope.row.sell_price_rmb }} -->
                {{ scope.row.total_sell_price | toRound }}
              </span>
            </template>
          </el-table-column>
          <!-- <el-table-column label="K线深度" width="100" align="center">
        <template slot-scope="scope">
          <el-button
            type="primary"
            plain
            size="mini"
            @click="handleKline(scope.row)"
          >
            对比K线图
          </el-button>
        </template>
      </el-table-column> -->
          <el-table-column
            v-if="lists_temp[5].ispass"
            :key="'id'"
            align="center"
            label="ID"
            prop="id"
            :width="getWidth('id', 80)"
          >
            <template slot-scope="scope">
              {{ scope.row.id }}
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[12].ispass"
            :key="'lossgiftfee'"
            label="盈亏(没算提币手续费)"
            :width="getWidth('lossgiftfee', 130)"
            align="center"
            prop="lossgiftfee"
          >
            <template slot-scope="scope">
              <span style="color: red; font-weight: bold; font-size: 16px">
                {{ getLossGiftFee(scope.row) }} U</span
              >
            </template>
          </el-table-column>
          <el-table-column
            v-if="lists_temp[11].ispass"
            label="备注"
            align="center"
            prop="remark"
            :width="getWidth('remark', 120)"
          >
            <template slot-scope="scope">
              <span
                v-show="!scope.row['is_remark']"
                style="width: 100%; display: block; height: auto"
                >{{ scope.row.remark }}</span
              >
              <el-input
                v-show="scope.row['is_remark'] === true"
                :ref="`inp-right-${scope.row.id}`"
                v-model="scope.row.remark"
                focus
                @blur="onRemarkBlurRight(scope.row)"
              />
            </template>
          </el-table-column>

          <el-table-column
            v-if="lists_temp[7].ispass"
            key="withdraw"
            align="center"
            label="链信息(买/卖)"
            prop="withdraw"
            :width="getWidth('withdraw', 180)"
          >
            <template slot-scope="scope">
              <div
                v-if="
                  !scope.row.buy_withdraw_info_text.length &&
                  !scope.row.sell_withdraw_info_text.length
                "
              >
                无信息
              </div>
              <div
                v-else
                :class="{
                  'ellipsis-2-lines': chain_index !== scope.$index,
                }"
                style="cursor: pointer"
                @click="handleWithdraw(scope.row)"
                @mouseover="handleMouseOver(scope.row, scope.$index)"
                @mouseleave="handleMouseLeave(scope.row, scope.$index)"
              >
                <span v-if="!scope.row.buy_withdraw_info_text.length">
                  无信息
                </span>
                <span
                  v-for="(item, index) in scope.row.buy_withdraw_info_text"
                  :key="index"
                >
                  <span
                    :class="{
                      'chain-green': item.is_withdraw == 1,
                      'chain-red': item.is_withdraw != 1,
                    }"
                    >{{ item.network }}</span
                  >
                  <span
                    v-if="index < scope.row.buy_withdraw_info_text.length - 1"
                    >,</span
                  >
                </span>
                <span>买 - 卖</span>
                <span
                  v-for="(item, index) in scope.row.sell_withdraw_info_text"
                  :key="index"
                >
                  <span
                    :class="{
                      'chain-green': item.is_deposit == 1,
                      'chain-red': item.is_deposit != 1,
                    }"
                    >{{ item.network }}</span
                  >
                  <span
                    v-if="index < scope.row.sell_withdraw_info_text.length - 1"
                    >,</span
                  >
                </span>
                <span v-if="!scope.row.sell_withdraw_info_text.length">
                  无信息
                </span>
              </div>
            </template>
          </el-table-column>

          <!-- <el-table-column label="是否可借" width="80" align="center">
        <template slot-scope="scope">
          <span v-if="scope.row.margin_status == 0" style="color: gray"
            >未知</span
          >
          <span v-if="scope.row.margin_status == 1" style="color: green"
            >可借</span
          >
          <span v-if="scope.row.margin_status == 2" style="color: red"
            >不可</span
          >
        </template>
      </el-table-column> -->
        </el-table>
      </div>
    </div>
    <Pagination
      :list="list"
      @handleSizeChange="handleSizeChange"
      @handleCurrentChange="handleCurrentChange"
    />
    <el-dialog
      title="临时过滤查询"
      :visible.sync="queryTempDialogVisible"
      :fullscreen="is_mobile"
      width="70%"
    >
      <div
        style="
          margin-bottom: 10px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 12px;
        "
      >
        <div>
          <el-input
            v-model="tempFilterSearch"
            style="width: 150px"
            clearable
            placeholder="搜索交易对"
            size="small"
            @clear="handleTempFilterSearchClear"
          />
          <el-button size="small" type="primary" @click="handleTempFilterSearch"
            >搜索</el-button
          >
        </div>
        <el-button size="small" type="danger" @click="handleClearTempFilter"
          >清空过滤</el-button
        >
      </div>
      <el-table
        :data="displayTempFilterRows"
        border
        fit
        row-key="id"
        element-loading-text="Loading"
      >
        <el-table-column
          label="交易对"
          prop="symbol"
          width="140"
          align="center"
        >
          <template slot-scope="scope">
            <span class="symbol-link" @click="copyText(scope.row.symbol)">
              {{ scope.row.symbol }}
            </span>
          </template>
        </el-table-column>
        <el-table-column
          label="买入平台"
          prop="platform_buy"
          width="140"
          align="center"
        />
        <el-table-column
          label="卖出平台"
          prop="platform_sell"
          width="140"
          align="center"
        />

        <el-table-column label="买链信息" width="220" align="center">
          <template slot-scope="scope">
            <span v-if="!scope.row.buy_withdraw_info_text.length">无信息</span>
            <span
              v-for="(item, index) in scope.row.buy_withdraw_info_text"
              :key="`buy-${scope.row.id}-${index}`"
            >
              <span
                :class="{
                  'chain-green': item.is_withdraw == 1,
                  'chain-red': item.is_withdraw != 1,
                }"
                >{{ item.network }}</span
              >
              <span v-if="index < scope.row.buy_withdraw_info_text.length - 1"
                >,</span
              >
            </span>
          </template>
        </el-table-column>
        <el-table-column label="卖链信息" width="220" align="center">
          <template slot-scope="scope">
            <span v-if="!scope.row.sell_withdraw_info_text.length">无信息</span>
            <span
              v-for="(item, index) in scope.row.sell_withdraw_info_text"
              :key="`sell-${scope.row.id}-${index}`"
            >
              <span
                :class="{
                  'chain-green': item.is_deposit == 1,
                  'chain-red': item.is_deposit != 1,
                }"
                >{{ item.network }}</span
              >
              <span v-if="index < scope.row.sell_withdraw_info_text.length - 1"
                >,</span
              >
            </span>
          </template>
        </el-table-column>
        <el-table-column label="链状态" width="80" align="center">
          <template slot-scope="scope">
            <span
              v-if="
                scope.row.buy_withdraw_status.length == 0 ||
                scope.row.sell_deposit_status.length == 0
              "
              :class="{
                'chain-red':
                  scope.row.buy_withdraw_status.length == 0 ||
                  scope.row.sell_deposit_status.length == 0,
              }"
              >暂停</span
            >
            <span v-else>未知</span>
          </template>
        </el-table-column>
        <el-table-column label="备注" prop="remark" width="180" align="center">
          <template slot-scope="scope">
            <span>{{ scope.row.remark }}</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="120" align="center">
          <template slot-scope="scope">
            <el-switch
              v-model="scope.row.temp_filter_active"
              active-color="#13ce66"
              inactive-color="#f56c6c"
              @change="onTempFilterSwitch(scope.row, $event)"
            />
          </template>
        </el-table-column>
      </el-table>
      <div style="margin: 14px 0; text-align: right">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :current-page="tempFilterPage.page"
          :page-size="tempFilterPage.page_size"
          :page-sizes="[10, 20, 50, 100]"
          :total="tempFilterTotal"
          @size-change="handleTempFilterSizeChange"
          @current-change="handleTempFilterCurrentChange"
        />
      </div>
      <span slot="footer" class="dialog-footer">
        <el-button @click="queryTempDialogVisible = false">关闭</el-button>
      </span>
    </el-dialog>
    <el-dialog
      title="链信息(买/卖)"
      :visible.sync="expireFormVisible"
      :fullscreen="is_mobile"
    >
      <!-- <div class="flex-center" style="margin-bottom: 0">
        <span style="color: red; font-weight: bold; font-size: 16px">
          {{ getLossGiftFee(with_info) }} U</span
        >
        <el-input
          v-model="with_fee_info"
          style="width: 100px; margin: 0 10px"
          placeholder="提币手续费"
        />
        <span
          >实际盈利：<span
            style="color: red; font-weight: bold; font-size: 16px"
          >
            {{ getTotalWin(with_info, with_fee_info) }}</span
          ></span
        >
      </div> -->
      <div class="flex-space">
        <h3>
          买入平台：<span class="symbol-link">{{
            with_draw_info.buy_platform
          }}</span>
        </h3>

        <el-switch
          v-model="with_info.block_status"
          active-color="#13ce66"
          inactive-color="#999"
          :active-value="false"
          :inactive-value="true"
          style="margin-left: 12px"
          @change="filterTempSwitch(with_info)"
        />
      </div>
      <el-table
        v-loading="loading"
        :data="with_draw_info.buy_list"
        element-loading-text="Loading"
        border
        fit
        highlight-current-row
      >
        <el-table-column label="币种" width="150" align="center">
          <template slot-scope="scope">
            <span
              class="symbol-link"
              @click="copyText(scope.row.currency_name)"
            >
              {{ scope.row.currency_name }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="链名" width="150" align="center">
          <template slot-scope="scope">
            {{ scope.row.chain }}
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="提币通道"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            <el-tag :type="scope.row.wd_status | statusFilter">{{
              scope.row.wd_status_text
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="提币手续费"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            <span
              v-if="
                !scope.row.withdraw_fee || scope.row.withdraw_fee.includes('%')
              "
            />
            <span v-else style="color: red"
              >≈{{ setWithdrawFee(scope.row.withdraw_fee) }} U</span
            >
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="区块确认数"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            {{ scope.row.confirm_num }}
          </template>
        </el-table-column>
        <el-table-column label="余额" width="100" align="center">
          <template slot-scope="scope">
            {{
              scope.row.platform_address &&
              scope.row.platform_address.balance !== undefined
                ? parseInt(scope.row.platform_address.balance)
                : "--"
            }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" align="center">
          <template slot-scope="scope">
            <el-button
              v-if="scope.row.platform_address"
              type="primary"
              size="mini"
              @click="handlePlatformAddress(scope.row, 2)"
            >
              更新钱包余额
            </el-button>
            <el-button
              v-if="!scope.row.platform_address && isAdmin"
              type="primary"
              size="mini"
              @click="handlePlatformAddress(scope.row, 2)"
            >
              配置地址
            </el-button>
            <el-button
              v-if="scope.row.platform_address && isAdmin"
              type="primary"
              size="mini"
              @click="handleEditAddress(scope.row, 2)"
            >
              修改地址
            </el-button>
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="提币链接"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            <span
              style="cursor: pointer; color: blue"
              @click="checkWithOpenUrl(scope.row.platform, 1)"
              >点击查看</span
            >
          </template>
        </el-table-column>
        <!-- <el-table-column label="更新时间" width="170" align="center">
          <template slot-scope="scope">
            <span>{{ scope.row.updated_at }}</span>
          </template>
        </el-table-column> -->
      </el-table>
      <h3>
        卖出平台：<span class="symbol-link">{{
          with_draw_info.sell_platform
        }}</span>
      </h3>
      <el-table
        v-loading="loading"
        :data="with_draw_info.sell_list"
        element-loading-text="Loading"
        border
        fit
        highlight-current-row
      >
        <el-table-column label="币种" width="150" align="center">
          <template slot-scope="scope">
            <span
              class="symbol-link"
              @click="copyText(scope.row.currency_name)"
            >
              {{ scope.row.currency_name }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="链名" width="150" align="center">
          <template slot-scope="scope">
            {{ scope.row.chain }}
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="充值通道"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            <el-tag :type="scope.row.wd_status | statusFilter">{{
              scope.row.wd_status_text
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="提币手续费"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            <span
              v-if="
                !scope.row.withdraw_fee || scope.row.withdraw_fee.includes('%')
              "
            />
            <span v-else style="color: red"
              >≈{{ setWithdrawFee(scope.row.withdraw_fee) }} U</span
            >
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="区块确认数"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            {{ scope.row.confirm_num }}
          </template>
        </el-table-column>
        <el-table-column label="余额" width="100" align="center">
          <template slot-scope="scope">
            {{
              scope.row.platform_address &&
              scope.row.platform_address.balance !== undefined
                ? parseInt(scope.row.platform_address.balance)
                : "--"
            }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" align="center">
          <template slot-scope="scope">
            <el-button
              v-if="scope.row.platform_address"
              type="primary"
              size="mini"
              @click="handlePlatformAddress(scope.row, 2)"
            >
              更新钱包余额
            </el-button>
            <el-button
              v-if="!scope.row.platform_address && isAdmin"
              type="primary"
              size="mini"
              @click="handlePlatformAddress(scope.row, 2)"
            >
              配置地址
            </el-button>
            <el-button
              v-if="scope.row.platform_address && isAdmin"
              type="primary"
              size="mini"
              @click="handleEditAddress(scope.row, 1)"
            >
              修改地址
            </el-button>
          </template>
        </el-table-column>
        <el-table-column
          class-name="status-col"
          label="充币链接"
          width="110"
          align="center"
        >
          <template slot-scope="scope">
            <span
              style="cursor: pointer; color: blue"
              @click="checkWithOpenUrl(scope.row.platform, 2)"
              >点击查看</span
            >
          </template>
        </el-table-column>
        <!-- <el-table-column label="更新时间" width="170" align="center">
          <template slot-scope="scope">
            <span>{{ scope.row.updated_at }}</span>
          </template>
        </el-table-column> -->
      </el-table>
    </el-dialog>
    <el-dialog
      v-if="klineShow"
      title="K线深度"
      :visible.sync="klineShow"
      :fullscreen="true"
    >
      <div class="kline-diff">
        <div class="kline-item">
          <Kline
            :id="klineId"
            ref="kline"
            :is-show="klineShow"
            :platform-list="platformList"
            :is-buy="true"
            :platform="buyPlatform"
            :curreny-name="klineCurrenyName"
            :quote-name="klineQuoteName"
          />
          <Depth
            :id="klineId"
            ref="kline"
            :is-show="klineShow"
            :is-buy="true"
            :platform="buyPlatform"
            :curreny-name="klineCurrenyName"
            :quote-name="klineQuoteName"
          />
        </div>
        <div class="kline-item">
          <Kline
            :id="klineId"
            ref="kline"
            :is-show="klineShow"
            :platform-list="platformList"
            :is-buy="false"
            :platform="sellPlatform"
            :curreny-name="klineCurrenyName"
            :quote-name="klineQuoteName"
          />
          <Depth
            :id="klineId"
            ref="kline"
            :is-show="klineShow"
            :is-buy="false"
            :platform="sellPlatform"
            :curreny-name="klineCurrenyName"
            :quote-name="klineQuoteName"
          />
        </div>
      </div>
    </el-dialog>
    <el-dialog
      title="地址配置"
      :visible.sync="platformAddressDialogVisible"
      width="520px"
    >
      <el-form label-width="90px" :model="platformAddressForm">
        <el-form-item label="币种">
          <el-input v-model="platformAddressForm.currency_name" disabled />
        </el-form-item>
        <el-form-item label="平台">
          <span>{{ platformAddressForm.platform_name }}</span>
        </el-form-item>
        <el-form-item label="链名">
          <el-select
            v-model="platformAddressForm.network_type"
            placeholder="请选择链名"
            @change="changeChain"
          >
            <el-option
              v-for="item in chainList"
              :key="item.id"
              :label="item.name"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="地址">
          <el-input
            v-model="platformAddressForm.address"
            type="textarea"
            :rows="3"
            placeholder="请输入或粘贴地址"
          />
        </el-form-item>
        <el-form-item label="合约">
          <el-input
            v-model="platformAddressForm.contract"
            placeholder="请输入合约地址或合约名称"
          />
        </el-form-item>
      </el-form>
      <span slot="footer" class="dialog-footer">
        <el-button @click="platformAddressDialogVisible = false"
          >取消</el-button
        >
        <el-button type="primary" @click="savePlatformAddressConfig"
          >保存</el-button
        >
      </span>
    </el-dialog>
    <!-- 自定义菜单 -->
    <div v-show="menuVisible" class="custom-menu" :style="menuStyle">
      <div class="menu-item" @click="toggleRefresh()">
        {{ refresh_button == 1 ? "停止自动刷新" : "开启自动刷新" }}
      </div>
      <div class="menu-item" @click="getTopics()">刷新</div>
      <!-- <div class="menu-item" @click="handleClick('粘贴')">粘贴</div>
      <div class="menu-item" @click="handleClick('删除')">删除</div>
      <div class="menu-divider" />
      <div class="menu-item" @click="handleClick('刷新')">刷新</div> -->
    </div>
    <el-dialog
      title="设置交易手续费"
      :visible.sync="setLossFeeVisible"
      :fullscreen="is_mobile"
    >
      <div v-for="item in platformAllTemp" :key="item.id" class="flex-center">
        <span style="margin-right: 10px">{{ item.name }}</span>
        <el-input v-model="item.val">
          <template slot="append">%</template>
        </el-input>
      </div>
      <div slot="footer" class="dialog-footer">
        <el-button @click="setLossFeeVisible = false">取 消</el-button>
        <el-button type="primary" @click="onSaveLossFee">确 定</el-button>
      </div>
    </el-dialog>
  </div>
</template>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>
<script>
import Multiselect from "vue-multiselect";
import {
  getQuotationPrice,
  getPlatformList,
  getSymbolOption,
  getWithdrawInfo,
  refreshPlatformAddress,
  configPlatformAddress,
  postCollect,
  postRemark,
} from "@/api/table";
import {
  setFilter,
  getFilter,
  setPlatformFilter,
  getPlatformFilter,
  setCommonFilter,
  getCommonFilter,
  blockId,
  getInfo,
} from "@/api/user";
import { switchDiff } from "@/api/setting";
import Pagination from "@/components/pagination";
import {
  copyText,
  covertime,
  isMobile,
  parseNumber,
  formatSmartDecimal,
} from "@/utils/index";
import Kline from "@/components/kline/index.vue";
import Depth from "@/components/depth/index.vue";
import {
  parsePercentage,
  platformText,
  chainList,
  parsePercent,
  calcumNum,
  calcProfit,
} from "@/utils/platform";

const defaultData = {
  id: "",
  symbol: "",
  price_diff: "",
  platform_buy: "",
  buy_price_fmt: "",
  platform_sell: "",
  sell_price_fmt: "",
  buy_num: "",
  sell_num: "",
  buy_price_rmb: "",
  sell_price_rmb: "",
  updated_at: "",
  quote_name: "",
  sell_quote_name: "",
  chain: "",
  wd_status: 0,
  wd_status_text: "",
  is_margin: 0,
};
const diffList = [
  { key: "0.1", display_name: "0.1%" },
  { key: "0.2", display_name: "0.2%" },
  { key: "0.3", display_name: "0.3%" },
  { key: "0.4", display_name: "0.4%" },
  { key: "0.5", display_name: "0.5%" },
  { key: "0.6", display_name: "0.6%" },
  { key: "0.7", display_name: "0.7%" },
  { key: "0.8", display_name: "0.8%" },
  { key: "0.9", display_name: "0.9%" },
  { key: "1", display_name: "1%" },
  { key: "1.5", display_name: "1.5%" },
  { key: "1.7", display_name: "1.7%" },
  { key: "2", display_name: "2%" },
  { key: "2.5", display_name: "2.5%" },
  { key: "3", display_name: "3%" },
  { key: "5", display_name: "5%" },
];

export default {
  filters: {
    toRound(val) {
      if (val) {
        const a = Math.round(val);
        return a == 0 ? 1 : a;
      }
      return val;
    },
    formatSmartDecimalFilters(val) {
      if (val) return formatSmartDecimal(parseNumber(val));
      return val;
    },
    toFloat(val) {
      if (val) return parseNumber(val);
      return val;
    },
    toNumber(val) {
      return Math.round(val);
    },
    coverDataTime(date) {
      return covertime(date, "his");
    },
    statusFilter(status) {
      const statusMap = {
        1: "success",
        0: "danger",
      };
      return statusMap[status];
    },
  },
  components: {
    Depth,
    Kline,
    Pagination,
    Multiselect,
  },
  data() {
    return {
      with_fee_info: 0,
      fixedfilter: 0,
      tempfiltersymbol: 0,
      with_info: {},
      platformAllTemp: [],
      platformAll: platformText(),
      setLossFeeVisible: false,
      right_keep_num: 10,
      tableKey: "",
      klineShow: false,
      buyPlatform: "",
      sellPlatform: "",
      klineQuoteName: "",
      klineCurrenyName: "",
      klineId: "",
      remark: "",
      topic: Object.assign({}, defaultData),
      index: 0,
      routes: [],
      list: [],
      loading: true,
      platformList: [],
      showAll: !(
        localStorage.getItem("diff_search_box_show_all") == "false" ||
        localStorage.getItem("diff_search_box_show_all") == null
      ),
      expireFormVisible: false,
      platformAddressDialogVisible: false,
      platformAddressForm: {
        platform: "",
        platform_name: "",
        currency_name: "",
        chain: "ETH",
        network_type: "1",
        contract: "",
        address: "",
      },
      platformAddressRow: null,
      with_draw_info: {
        currency_name: "",
        buy_platform: "",
        sell_platform: "",
        balance: "",
        platform_address: "",
        buy_list: [],
        sell_list: [],
      },
      chainList: chainList(),
      query: {
        // block_symbol_temp: [],
        // buy_platform_temp: [],
        // sell_platform_temp: [],
        block_id_temp: [],
        order: "",
        page: 1,
        page_size: 50,
        symbol: "",
        is_margin: "",
        diff_price: "",
        total_price: "",
        platform: [],
        block_symbol: [],
        // block_ids: [],
        quote_name: [],
      },
      lists: [
        {
          key: "buy_num",
          label: "买入数量",
          ispass: true,
        },
        {
          key: "sell_num",
          label: "卖出数量",
          ispass: true,
        },
        {
          key: "total_buy_price",
          label: "买入总价",
          ispass: true,
        },
        {
          key: "total_sell_price",
          label: "卖入总价",
          ispass: true,
        },
        {
          key: "updated_at",
          label: "更新时间",
          ispass: false,
        },
        {
          key: "id",
          label: "ID",
          ispass: false,
        },
        {
          key: "collect",
          label: "收藏",
          ispass: true,
        },
        {
          key: "withdraw",
          label: "链信息(买/卖)",
          ispass: true,
        },
        {
          key: "price_diff",
          label: "价格差",
          ispass: true,
        },
        {
          key: "buy_price",
          label: "买入价格",
          ispass: true,
        },
        {
          key: "sell_price",
          label: "卖出价格",
          ispass: true,
        },
        {
          key: "remark",
          label: "备注",
          ispass: true,
        },
        {
          key: "lossgiftfee",
          label: "盈亏不包含提币手续费",
          ispass: true,
        },
      ],
      lists_temp: [
        {
          key: "buy_num",
          label: "买入数量",
          ispass: false,
        },
        {
          key: "sell_num",
          label: "卖出数量",
          ispass: false,
        },
        {
          key: "total_buy_price",
          label: "买入总价",
          ispass: true,
        },
        {
          key: "total_sell_price",
          label: "卖入总价",
          ispass: true,
        },
        {
          key: "updated_at",
          label: "更新时间",
          ispass: false,
        },
        {
          key: "id",
          label: "ID",
          ispass: false,
        },
        {
          key: "collect",
          label: "收藏",
          ispass: false,
        },
        {
          key: "withdraw",
          label: "链信息(买/卖)",
          ispass: false,
        },
        {
          key: "price_diff",
          label: "价格差",
          ispass: false,
        },
        {
          key: "buy_price",
          label: "买入价格",
          ispass: false,
        },
        {
          key: "sell_price",
          label: "卖出价格",
          ispass: false,
        },
        {
          key: "remark",
          label: "备注",
          ispass: false,
        },
        // {
        //   key: "lossgiftfee",
        //   label: "盈亏不包含提币手续费",
        //   ispass: true,
        // },
      ],
      currencyList: [
        { key: "USDT", item: "USDT" },
        { key: "BTC", item: "BTC" },
        { key: "ETH", item: "ETH" },
      ],
      diffList: diffList,
      options: [],
      refresh_button: 2,
      refresh_button_temp: "",
      second: 5000,
      intervalId: null,
      tablekeycount: 0,
      is_mobile: isMobile(),
      chain_index: "",
      list_temp: [],
      right_keep_num: 10,
      menuVisible: false,
      menuStyle: {
        left: 0,
        top: 0,
      },
      roles: [],
      queryTempDialogVisible: false,
      tempFilterSearch: "",
      tempFilterRows: [],
      tempFilterPage: {
        page: 1,
        total: 0,
        last_page: 1,
        page_size: 10,
        total: 0,
        last_page: 1,
        total: 0,
        last_page: 1,
      },
    };
  },
  computed: {
    word: function () {
      if (this.showAll === false) {
        return "展开";
      } else {
        return "收起";
      }
    },
    isAdmin() {
      const roles =
        Array.isArray(this.roles) && this.roles.length > 0
          ? this.roles
          : this.$store && this.$store.getters && this.$store.getters.roles;
      return Array.isArray(roles) && roles.includes("admin");
    },
    filteredTempFilterRows() {
      const keyword = this.tempFilterSearch.trim().toLowerCase();
      if (!keyword) {
        return this.tempFilterRows;
      }
      return this.tempFilterRows.filter((item) => {
        const symbol = (item.symbol || item.currency_name || "").toLowerCase();
        return symbol.includes(keyword);
      });
    },
    tempFilterTotal() {
      return this.filteredTempFilterRows.length;
    },
    displayTempFilterRows() {
      const start =
        (this.tempFilterPage.page - 1) * this.tempFilterPage.page_size;
      const end = start + this.tempFilterPage.page_size;
      return this.filteredTempFilterRows.slice(start, end);
    },
  },
  watch: {
    tempFilterSearch() {
      this.tempFilterPage.page = 1;
    },
  },
  destroyed() {
    document.removeEventListener("contextmenu", this.showMenu);
    document.removeEventListener("click", this.hideMenu);
    clearInterval(this.intervalId);
  },

  created() {
    document.addEventListener("contextmenu", (e) => {
      e.preventDefault(); // 阻止浏览器默认的右键菜单
      this.showMenu(e);
    });
    this.initPlatform();
    this.initSymbols();
    this.initFilter();
    const right_keep_num = localStorage.getItem(
      "diff_right_table_temp_keep_num"
    );
    if (right_keep_num) this.right_keep_num = parseInt(right_keep_num);

    const lists_temp = localStorage.getItem("diff_column_lists_filter_temp");
    const list_temp = localStorage.getItem("diff_table_temp_list");
    if (lists_temp) {
      const lists_temp_data = JSON.parse(lists_temp);
      this.lists_temp.forEach((item) => {
        lists_temp_data.forEach((it) => {
          if (item.key == it.key) {
            item.ispass = it.ispass;
          }
        });
      });
    }
    if (list_temp) {
      this.list_temp = JSON.parse(list_temp);
    }

    // 改成在标记条件后初始化
    // this.getTopics()
  },
  mounted() {
    this.fetchUserInfo();
    if (localStorage.getItem("platform_fee")) {
      const platform_fee = JSON.parse(localStorage.getItem("platform_fee"));
      this.platformAll.forEach((item) => {
        platform_fee.forEach((it) => {
          if (item.id == it.id) {
            item.val = it.val;
          }
        });
      });
    } else {
      this.$msgbox
        .confirm("当前未设置交易手续费", "设置交易手续费", {
          confirmButtonText: "确定",
          type: "warning",
        })
        .then(() => {
          this.onSetFee(true);
        });
    }
    if (localStorage.getItem("temp_filter_diff")) {
      const temp_symbol = JSON.parse(localStorage.getItem("temp_filter_diff"));
      this.tempfiltersymbol = temp_symbol.length;
      this.query.block_id_temp = temp_symbol;
    }
    if (localStorage.getItem("temp_filter_diff_info")) {
      try {
        this.tempFilterRows = JSON.parse(
          localStorage.getItem("temp_filter_diff_info")
        );
      } catch (err) {
        this.tempFilterRows = [];
      }
    }
    if (localStorage.getItem("fixed_filter_diff")) {
      this.fixedfilter = localStorage.getItem("fixed_filter_diff");
    }
  },
  methods: {
    getTotalWin(data, fee) {
      const lossgift = this.getLossGiftFee(data);
      return calcumNum(lossgift - fee);
    },
    filterTempSwitch(data) {
      if (!data || !data.id) return;

      // 更新临时过滤列表
      if (!this.query.block_id_temp.includes(data.id)) {
        this.query.block_id_temp.push(data.id);
      }
      this.pushTempFilterRow(data);
      this.saveTempFilterInfo();

      // 删除表格中已隐藏项
      this.list.data = this.list.data.filter((item) => item.id !== data.id);
      this.list_temp = this.list_temp.filter((item) => item.id !== data.id);

      // 关闭链信息弹窗
      this.expireFormVisible = false;
    },
    saveTempFilterInfo() {
      localStorage.setItem(
        "temp_filter_diff",
        JSON.stringify(this.query.block_id_temp)
      );
      this.tempfiltersymbol = this.query.block_id_temp.length;
    },
    onSaveTempFilter() {
      this.saveTempFilterInfo();
    },
    queryOrClearTempFilter() {
      this.openTempFilterDialog();
    },
    async handleClearTempFilter() {
      this.onClearTempFilter();
      this.ensureTempFilterPage();
    },
    handleTempFilterSearchClear() {
      this.tempFilterSearch = "";
      this.tempFilterPage.page = 1;
      this.ensureTempFilterPage();
    },
    handleTempFilterSearch() {
      this.tempFilterPage.page = 1;
      this.ensureTempFilterPage();
    },
    async queryTempFilterRows() {
      if (
        !Array.isArray(this.query.block_id_temp) ||
        !this.query.block_id_temp.length
      ) {
        this.$message.warning("请先保存临时过滤并选择过滤项");
        return;
      }

      const ids = this.query.block_id_temp.join(",");
      const params = {
        ids,
        page: this.tempFilterPage.page,
        page_size: 9999,
      };

      const res = await getQuotationPrice(params);
      const rows = Array.isArray(res.data.data) ? res.data.data : [];
      this.tempFilterRows = rows.map((item) => this.buildTempFilterRow(item));
      this.tempFilterPage.page = 1;
      this.tempFilterPage.total = rows.length;
      this.tempFilterPage.last_page = Math.max(
        1,
        Math.ceil(this.tempFilterPage.total / this.tempFilterPage.page_size)
      );
    },
    releaseTempFilter(row) {
      if (!row || !row.id) return;
      this.query.block_id_temp = this.query.block_id_temp.filter(
        (item) => item !== row.id
      );
      this.tempFilterRows = this.tempFilterRows.filter(
        (item) => item.id !== row.id
      );
      if (this.tempFilterPage.total != null && this.tempFilterPage.total > 0) {
        this.tempFilterPage.total = Math.max(0, this.tempFilterPage.total - 1);
      }
      this.ensureTempFilterPage();
      this.saveTempFilterInfo();
    },
    handleTempFilterSizeChange(size) {
      this.tempFilterPage.page_size = size;
      this.tempFilterPage.page = 1;
      this.tempFilterPage.last_page = Math.max(
        1,
        Math.ceil(this.tempFilterTotal / this.tempFilterPage.page_size)
      );
      this.ensureTempFilterPage();
    },
    handleTempFilterCurrentChange(page) {
      this.tempFilterPage.page = page;
      this.ensureTempFilterPage();
    },
    async fetchUserInfo() {
      try {
        const res = await getInfo();
        const roles = res && res.data && res.data.roles;
        this.roles = Array.isArray(roles) ? roles : [];
      } catch (err) {
        this.roles = [];
      }
    },
    onTempFilterSwitch(row, value) {
      if (!value) {
        this.releaseTempFilter(row);
      }
    },
    ensureTempFilterPage() {
      const totalCount = this.tempFilterTotal;
      const totalPages = Math.max(
        1,
        Math.ceil(totalCount / this.tempFilterPage.page_size)
      );
      if (this.tempFilterPage.page > totalPages) {
        this.tempFilterPage.page = totalPages;
      }
    },
    formatWithdrawInfoText(info = []) {
      const result = [];
      if (!Array.isArray(info)) return result;
      info.forEach((wd) => {
        if (!wd) return;
        const item = typeof wd === "string" ? { network: wd } : { ...wd };
        if (!item.network) item.network = "空";
        result.push(item);
      });
      return result;
    },
    buildTempFilterRow(row) {
      const buy_withdraw_status = [];
      const sell_deposit_status = [];
      if (row.buy_withdraw_info && row.buy_withdraw_info.length) {
        row.buy_withdraw_info.forEach((wd) => {
          if (wd.is_withdraw == 1) {
            buy_withdraw_status.push(wd.network);
          }
        });
      }
      if (row.sell_withdraw_info && row.sell_withdraw_info.length) {
        row.sell_withdraw_info.forEach((wd) => {
          if (wd.is_deposit == 1) {
            sell_deposit_status.push(wd.network);
          }
        });
      }
      return {
        id: row.id,
        symbol: row.symbol || row.currency_name || "",
        platform_buy: row.platform_buy || "",
        platform_sell: row.platform_sell || "",
        buy_withdraw_info_text: this.formatWithdrawInfoText(
          row.buy_withdraw_info_text || row.buy_withdraw_info
        ),
        sell_withdraw_info_text: this.formatWithdrawInfoText(
          row.sell_withdraw_info_text || row.sell_withdraw_info
        ),
        remark: row.remark || "",
        temp_filter_active: true,

        buy_withdraw_status: buy_withdraw_status,
        sell_deposit_status: sell_deposit_status,
      };
    },
    pushTempFilterRow(row) {
      if (!row || !row.id) return;
      if (this.tempFilterRows.some((item) => item.id === row.id)) return;
      this.tempFilterRows.push(this.buildTempFilterRow(row));
    },
    loadTempFilterInfo() {
      let savedRows = [];
      if (localStorage.getItem("temp_filter_diff_info")) {
        try {
          savedRows = JSON.parse(localStorage.getItem("temp_filter_diff_info"));
        } catch (err) {
          savedRows = [];
        }
      }

      const mergedRows = [...savedRows, ...this.tempFilterRows];
      const uniqueRows = [];
      mergedRows.forEach((item) => {
        if (item && item.id && !uniqueRows.some((row) => row.id === item.id)) {
          uniqueRows.push(item);
        }
      });
      this.tempFilterRows = uniqueRows.map((item) => ({
        ...item,
        temp_filter_active: item.temp_filter_active !== false,
      }));

      if (!this.tempFilterRows.length && this.query.block_id_temp.length) {
        const ids = this.query.block_id_temp;
        const rows = [];
        if (this.list.data && this.list.data.length) {
          rows.push(...this.list.data.filter((item) => ids.includes(item.id)));
        }
        if (this.list_temp && this.list_temp.length) {
          rows.push(...this.list_temp.filter((item) => ids.includes(item.id)));
        }
        this.tempFilterRows = rows.map((item) => this.buildTempFilterRow(item));
      }
    },
    async openTempFilterDialog() {
      this.loadTempFilterInfo();
      this.tempFilterPage.page = 1;
      this.queryTempDialogVisible = true;
      if (
        Array.isArray(this.query.block_id_temp) &&
        this.query.block_id_temp.length
      ) {
        await this.queryTempFilterRows();
      }
    },
    closeOpenFixed() {
      if (this.fixedfilter == 0) this.fixedfilter = 1;
      else this.fixedfilter = 0;
      localStorage.setItem("fixed_filter_diff", this.fixedfilter);
    },
    onClearTempFilter() {
      localStorage.removeItem("temp_filter_diff");
      localStorage.removeItem("temp_filter_diff_info");
      this.tempfiltersymbol = 0;
      this.query.block_id_temp = [];
      this.tempFilterRows = [];
      this.tempFilterPage.total = 0;
      this.tempFilterPage.last_page = 1;
    },
    checkWithOpenUrl(data, type) {
      const plat_temp = platformText();
      for (let i = 0; i < plat_temp.length; i++) {
        if (plat_temp[i]["id"] == data.platform) {
          if (type == 1) {
            window.open(plat_temp[i]["with_url"]);
          } else {
            window.open(plat_temp[i]["recharge_url"]);
          }
          break;
        }
      }
    },
    jumpLink(platform, symbol) {
      const plat_temp = platformText();
      for (let i = 0; i < plat_temp.length; i++) {
        if (plat_temp[i]["id"] == platform) {
          let url = "";
          if (plat_temp[i]["url_type"]) {
            url = plat_temp[i]["url"].replace(/btc/gi, symbol.toLowerCase());
          } else {
            url = plat_temp[i]["url"].replace(/btc/gi, symbol.toUpperCase());
          }
          window.open(url);
          break;
        }
      }
    },
    setWithdrawFee(fee) {
      const val = Number(this.with_info.buy_price_plus) * parsePercent(fee);

      if (val) return calcumNum(val);
      return val;
    },
    onSaveLossFee() {
      this.platformAll = this.platformAllTemp.map((item) => ({
        ...item,
      }));
      localStorage.setItem("platform_fee", JSON.stringify(this.platformAll));
      this.setLossFeeVisible = false;
    },
    onSetFee(bool) {
      this.platformAllTemp = this.platformAll.map((item) => ({
        ...item,
      }));
      this.setLossFeeVisible = bool;
    },
    // getLossGiftFee(data) {
    //   const buy_platform = data.buy_platform;
    //   let buy_fee = 0;
    //   let buy_num = 0;
    //   let sell_fee = 0;
    //   let sell_num = 0;
    //   const sell_platform = data.sell_platform;

    //   let num = data.total_buy_price;
    //   let result = 0;
    //   if (Number(data.total_sell_price) < Number(num)) {
    //     num = data.total_sell_price;
    //   }
    //   num = Math.round(num);
    //   for (let i = 0; i < this.platformAll.length; i++) {
    //     if (this.platformAll[i].id == buy_platform) {
    //       buy_fee = this.platformAll[i]["val"];
    //     }
    //     if (this.platformAll[i].id == sell_platform) {
    //       sell_fee = this.platformAll[i]["val"];
    //     }
    //   }
    //   if (buy_fee) buy_num = (num * buy_fee) / 100;
    //   if (sell_fee) sell_num = (num * sell_fee) / 100;
    //   result = num * parsePercentage(data.price_diff);
    //   result = result - buy_num - sell_num;
    //   result = result.toFixed(4);

    //   return result;
    // },
    getLossGiftFee(data) {
      const buy_platform = data.buy_platform;
      let buy_fee = 0;
      const buy_num = 0;
      let sell_fee = 0;
      const sell_num = 0;
      const sell_platform = data.sell_platform;

      let num = data.total_buy_price;

      const result = 0;
      if (Number(data.total_sell_price) < Number(num)) {
        num = data.total_sell_price;
      }
      num = Math.round(num);
      for (let i = 0; i < this.platformAll.length; i++) {
        if (this.platformAll[i].id == buy_platform) {
          buy_fee = this.platformAll[i]["val"];
        }
        if (this.platformAll[i].id == sell_platform) {
          sell_fee = this.platformAll[i]["val"];
        }
      }
      return calcProfit(
        num,
        data.buy_price,
        data.sell_price,
        buy_fee,
        sell_fee
      );
      // if (buy_fee) buy_num = (num * buy_fee) / 100;
      // if (sell_fee) sell_num = (num * sell_fee) / 100;

      // result = num * (data.sell_price - data.buy_price) - buy_num - sell_num;
      // result = result.toFixed(4);
      // // result = num * parsePercentage(data.price_diff);
      // // result = result - buy_num - sell_num;
      // return result;
    },
    clearLocalStorage(key, data) {
      localStorage.setItem(key, JSON.stringify([]));
      this[data] = [];
    },
    showMenu(e) {
      // 阻止默认菜单
      e.preventDefault();

      // 设置菜单位置（基于鼠标坐标）
      this.menuStyle.left = e.clientX + "px";
      this.menuStyle.top = e.clientY + "px";
      this.menuVisible = true;

      // 点击其他地方关闭菜单
      document.addEventListener("click", this.hideMenu);
    },
    hideMenu() {
      this.menuVisible = false;
      document.removeEventListener("click", this.hideMenu);
    },
    changeSaveNum() {
      localStorage.setItem(
        "diff_right_table_temp_keep_num",
        this.right_keep_num
      );
    },
    handleMouseOver(row, index) {
      this.chain_index = index;
    },
    handleMouseLeave(row, index) {
      this.chain_index = "";
    },
    getWidth(prop, defaultWidth) {
      const saved = localStorage.getItem(`diff_table_col_${prop}_width`);
      return saved ? parseInt(saved) : defaultWidth;
    },
    handleHeaderDragend(newWidth, oldWidth, column) {
      localStorage.setItem(`diff_table_col_${column.property}_width`, newWidth);
    },
    handleHeaderDragendRight(newWidth, oldWidth, column) {
      localStorage.setItem(
        `diff_right_table_col_${column.property}_width`,
        newWidth
      );
    },
    changeSortTemp() {
      const passedKeys = this.lists_temp.reduce((arr, item) => {
        arr.push({ key: item.key, ispass: item.ispass });
        return arr;
      }, []);
      localStorage.setItem(
        "diff_column_lists_filter_temp",
        JSON.stringify(passedKeys)
      );
    },
    changeSort() {
      const passedKeys = this.lists.reduce((arr, item) => {
        arr.push({ key: item.key, ispass: item.ispass });
        return arr;
      }, []);
      setCommonFilter({
        key: "diff_columns",
        object: passedKeys,
      });
      this.tableKey = Date.now();
    },
    handleKline(row) {
      this.klineId = row.id;
      this.buyPlatform = row.buy_platform;
      this.sellPlatform = row.sell_platform;
      this.klineQuoteName = row.quote_name;
      this.klineCurrenyName = row.currency_name;
      // switch (row.buy_platform) {
      //   case 2:
      //     this.buySymbol = row.currency_name + row.quote_name;
      //     this.buyCurrenyName = row.currency_name;
      //     break;
      // }
      this.klineShow = true;
    },
    copyText(text) {
      copyText(this, text);
    },
    cellClickRight(row, column) {
      const prop = column.property;
      if (prop == "remark") {
        this.onRemarkClick(row, "right");
      }
    },
    cellClick(row, column) {
      const prop = column.property;
      if (prop == "remark") {
        this.onRemarkClick(row, "left");
      }
    },
    onRemarkClick(row, position) {
      this.refresh_button_temp = this.refresh_button;
      if (this.refresh_button == 1) {
        this.refresh_button = 2;
      }
      if (position == "left") {
        this.list.data.forEach((item) => {
          if (item.id == row.id) this.$set(item, "is_remark", true);
          else this.$set(item, "is_remark", false);
        });

        setTimeout(() => {
          this.$nextTick(() => {
            const ref = this.$refs[`inp-${row.id}`];
            if (ref) {
              ref.focus();
            }
          });
        }, 500);
      } else {
        this.list_temp.forEach((item) => {
          if (item.id == row.id) this.$set(item, "is_remark", true);
          else this.$set(item, "is_remark", false);
        });

        setTimeout(() => {
          this.$nextTick(() => {
            const ref = this.$refs[`inp-right-${row.id}`];
            if (ref) {
              ref.focus();
            }
          });
        }, 500);
      }
    },
    onRemarkBlur(row) {
      this.refresh_button = this.refresh_button_temp;
      if (this.refresh_button == 1) {
        this.dataRefresh();
      }
      this.list.data.forEach((item) => {
        this.$set(item, "is_remark", false);
      });

      postRemark({
        diff_id: row.id,
        id: row.remark_id,
        buy_platform: row.buy_platform,
        sell_platform: row.sell_platform,
        match_id: row.match_id,
        sell_match_id: row.sell_match_id,
        remark: row.remark,
      }).then((res) => {
        if (!row.remark_id) row.remark_id = res.data;
      });
    },
    onRemarkBlurRight(row) {
      this.refresh_button = this.refresh_button_temp;
      if (this.refresh_button == 1) {
        this.dataRefresh();
      }
      this.list_temp.forEach((item) => {
        this.$set(item, "is_remark", false);
      });

      postRemark({
        diff_id: row.id,
        id: row.remark_id,
        buy_platform: row.buy_platform,
        sell_platform: row.sell_platform,
        match_id: row.match_id,
        sell_match_id: row.sell_match_id,
        remark: row.remark,
      }).then((res) => {
        if (!row.remark_id) row.remark_id = res.data;
      });
    },
    onCollect(row) {
      let is_collect = 0;
      if (row.is_collect == 0) {
        is_collect = 1;
      } else {
        is_collect = 0;
      }
      const data = {
        diff_id: row.id,
        id: row.collect_id,
        buy_platform: row.buy_platform,
        sell_platform: row.sell_platform,
        match_id: row.match_id,
        sell_match_id: row.sell_match_id,
        status: is_collect,
      };
      postCollect(data).then((res) => {
        if (!row.collect_id) row.collect_id = res.data;
        row.is_collect = is_collect;
      });
    },
    async getTopics(bool) {
      if (bool !== true) {
        this.loading = true;
      }
      if (this.query.is_margin != 1) this.query.is_margin = "";
      const res = await getQuotationPrice(this.query);
      const newList = [];
      res.data.data.forEach((item) => {
        const buy_withdraw_info_text = [];
        const sell_withdraw_info_text = [];
        if (item.buy_withdraw_info && item.buy_withdraw_info.length) {
          item.buy_withdraw_info.forEach((wd) => {
            if (!wd.network) {
              wd.network = "空";
            }
            buy_withdraw_info_text.push(wd);
          });
        }
        if (item.sell_withdraw_info && item.sell_withdraw_info.length) {
          item.sell_withdraw_info.forEach((wd) => {
            if (!wd.network) {
              wd.network = "空";
            }
            sell_withdraw_info_text.push(wd);
          });
        }
        item.buy_withdraw_info_text = buy_withdraw_info_text;
        item.sell_withdraw_info_text = sell_withdraw_info_text;
        // let buy_sell_bool = false;

        // if (item.sell_withdraw_info && item.buy_withdraw_info) {
        //   for (let i = 0; i < item.buy_withdraw_info.length; i++) {
        //     if (buy_sell_bool) break;
        //     for (let j = 0; j < item.sell_withdraw_info.length; j++) {
        //       if (
        //         item.buy_withdraw_info[i]["is_deposit"] == 1 &&
        //         item.sell_withdraw_info[j]["is_deposit"] == 1 &&
        //         item.buy_withdraw_info[i]["network"] ==
        //           item.sell_withdraw_info[j]["network"]
        //       ) {
        //         buy_sell_bool = true;
        //         break;
        //       }
        //     }
        //   }
        // }
        if (item.total_buy_price >= 2000 && item.total_sell_price >= 2000) {
          newList.push(item);
        }
      });
      // 合并所有数据
      const combined = [...newList, ...this.list_temp];

      // 用 Map 去重：相同配置保留时间最新的
      const map = new Map();

      combined.forEach((item) => {
        // 生成唯一键：卖平台+买平台+币种+计价币
        const key = `${item.sell_platform}-${
          item.buy_platform
        }-${item.currency_name.toUpperCase()}-${item.quote_name.toUpperCase()}`;

        const existing = map.get(key);
        const itemTime = new Date(item.updated_at).getTime();

        if (!existing || itemTime > new Date(existing.updated_at).getTime()) {
          map.set(key, item);
        }
      });

      // 转回数组并按时间排序
      const uniqueList = Array.from(map.values());
      uniqueList.sort(
        (a, b) =>
          new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime()
      );

      // 取前N条
      this.list_temp = uniqueList.slice(0, this.right_keep_num);
      localStorage.setItem(
        "diff_table_temp_list",
        JSON.stringify(this.list_temp)
      );
      this.list = res.data;
      this.loading = false;
    },
    async initSymbols() {
      const res3 = await getSymbolOption();
      this.options = res3.data;
      console.log(this.options);
    },
    async getDiffWd(id) {
      const wd_info = await getWithdrawInfo(id);
      const buy_list = [];
      const sell_list = [];
      if (wd_info.data.balance !== undefined) {
        this.with_draw_info.balance = wd_info.data.balance;
      } else {
        this.with_draw_info.balance = "";
      }
      if (wd_info.data.platform_address !== undefined) {
        this.with_draw_info.platform_address = wd_info.data.platform_address;
      } else {
        this.with_draw_info.platform_address = "";
      }
      (wd_info.data.buy_list || []).forEach((item) => {
        if (!item.chain) item["chain"] = "空";
        buy_list.push(item);
      });
      (wd_info.data.sell_list || []).forEach((item) => {
        if (!item.chain) item["chain"] = "空";
        if (item.chain) sell_list.push(item);
      });
      this.with_draw_info.buy_list = buy_list;
      this.with_draw_info.sell_list = sell_list;
    },
    async initFilter() {
      const init_filter = await getFilter();
      this.second = init_filter.data.second;
      this.query.total_price = init_filter.data.total_price;
      this.refresh_button = init_filter.data.refresh_button;
      this.query.is_margin = init_filter.data.is_margin;
      this.query.diff_price = init_filter.data.diff_price;
      this.query.platform = init_filter.data.platform;
      this.query.block_symbol = init_filter.data.block_symbol;
      // this.query.block_ids = init_filter.data.block_ids
      this.query.quote_name = init_filter.data.quote_name;

      const init_platform_filter = await getPlatformFilter({
        key: "diff_platform",
      });
      this.query.platform = init_platform_filter.data || [];

      // 在这里初始化
      await getCommonFilter({
        key: "diff_columns",
      }).then((res) => {
        if (!res.data.length) return;
        for (const i in this.lists) {
          for (const j in res.data) {
            if (res.data[j]["key"] == this.lists[i]["key"]) {
              this.lists[i]["ispass"] = res.data[j]["ispass"];
              break;
            }
          }
        }
      });
      this.getTopics();
      this.dataRefresh();
    },
    async initPlatform() {
      const res2 = await getPlatformList();
      this.platformList = res2.data;
    },
    closeSearch() {
      this.showAll = !this.showAll;
      localStorage.setItem("diff_search_box_show_all", this.showAll);
    },
    dataRefresh() {
      // 先清理旧定时器，避免旧间隔继续执行
      if (this.intervalId) {
        clearInterval(this.intervalId);
        this.intervalId = null;
      }

      // 仅在自动刷新开启时启动计时器
      if (this.refresh_button !== 1) {
        return;
      }

      this.intervalId = setInterval(() => {
        if (this.refresh_button === 1) {
          this.getTopics(true);
        }
      }, this.second);
    },
    changeSecond() {
      this.saveFilter();

      this.intervalId = null;
      this.dataRefresh();
    },
    handlePlatformFilter() {
      setPlatformFilter({
        key: "diff_platform",
        platform_keys: this.query.platform,
      }).then((res) => {
        this.page = 1;
        this.getTopics();
      });
    },

    handleFilter() {
      this.query.page = 1;
      this.getTopics();
      this.saveFilter();
    },
    handleSizeChange(size) {
      this.query.page_size = size;
      this.getTopics();
    },
    handleCurrentChange(page) {
      this.query.page = page;
      this.getTopics();
    },
    handleWithdraw(row) {
      this.with_info = row;
      this.with_draw_info.currency_name = row.symbol;
      this.with_draw_info.buy_platform = row.platform_buy;
      this.with_draw_info.sell_platform = row.platform_sell;

      this.getDiffWd(row.id);
      this.expireFormVisible = true;
    },
    getPlatformName(platformId) {
      const platform = platformText().find((item) => item.id == platformId);
      return platform
        ? platform.name || platform.label || platform.id
        : platformId;
    },
    async handleEditAddress(row, type) {
      if (!this.isAdmin) {
        this.$message.warning("当前用户无权限配置地址");
        return;
      }
      this.platformAddressRow = row;
      this.platformAddressForm.platform = row.platform;
      this.platformAddressForm.platform = row.platform;
      this.platformAddressForm.platform_name = this.getPlatformName(
        row.platform
      );
      this.platformAddressForm.currency_name = row.currency_name || "";
      this.platformAddressForm.contract = row.platform_address.contract;
      this.platformAddressForm.address = row.platform_address.address;
      this.platformAddressForm.network_type = row.platform_address.network_type;
      this.platformAddressDialogVisible = true;
    },
    async handlePlatformAddress(row, type) {
      if (row.platform_address) {
        await refreshPlatformAddress({
          platform: row.platform,
          currency_name: row.currency_name,
          network: row.platform_address.network,
          network_type: row.platform_address.network_type,
        }).then((res) => {
          if (row.platform_address) {
            this.$set(row.platform_address, "balance", res.data.balance);
          }
        });
        this.$message.success("更新成功");

        return;
      }

      if (!this.isAdmin) {
        this.$message.warning("当前用户无权限配置地址");
        return;
      }

      this.platformAddressRow = row;
      this.platformAddressForm.platform = row.platform;
      this.platformAddressForm.platform = row.platform;
      this.platformAddressForm.platform_name = this.getPlatformName(
        row.platform
      );
      this.platformAddressForm.currency_name = row.currency_name || "";
      this.platformAddressForm.contract = "";
      this.platformAddressForm.address = "";
      this.platformAddressForm.network_type = 1;
      this.platformAddressDialogVisible = true;
    },
    changeChain(value) {
      this.chainList.filter((item) => {
        if (item.id == value) {
          this.platformAddressForm.network_type = item.id;
          return;
        }
      });
    },
    async savePlatformAddressConfig() {
      if (!this.platformAddressForm.address) {
        this.$message.warning("请输入地址");
        return;
      }
      if (!this.platformAddressForm.contract) {
        this.$message.warning("请输入合约");
        return;
      }
      if (!this.platformAddressForm.network_type) {
        this.$message.warning("请选择链类型");
        return;
      }
      if (!this.isAdmin && !this.platformAddressRow.platform_address) {
        this.$message.warning("当前用户无权限配置地址");
        return;
      }

      await configPlatformAddress({
        platform: this.platformAddressForm.platform,
        network_type: this.platformAddressForm.network_type,
        currency_name: this.platformAddressRow.currency_name,
        network: this.platformAddressRow.chain,
        address: this.platformAddressForm.address,
        contract: this.platformAddressForm.contract,
      });
      this.$message.success("地址配置成功");
      this.platformAddressDialogVisible = false;
      this.getDiffWd(this.with_info.id);
    },
    toggleRefresh() {
      if (this.refresh_button == 1) {
        this.refresh_button = 2;
      } else {
        this.refresh_button = 1;
      }
      this.openRefresh();
    },
    openRefresh() {
      this.saveFilter();
      this.dataRefresh();
    },
    handlePlatformChange(value) {
      // console.log(value)
      this.query.platform = value;
      console.log(this.query.platform);
    },
    addTag(newTag) {
      this.handleFilter();
      return;
      const tag = {
        name: newTag,
        code: newTag.substring(0, 2) + Math.floor(Math.random() * 10000000),
      };
      this.options.push(tag);
      this.value.push(tag);
    },
    // filterId(id) {
    //   this.query.block_ids.push(id)
    //   this.getTopics()
    // },
    // removeBlockId(id) {
    //   const rl = this.query.block_ids
    //   for (let i = 0; i < rl.length; i++) {
    //     if (rl[i] === id) {
    //       rl.splice(i, 1)
    //     }
    //   }
    //   this.query.block_ids = rl
    //   console.log(this.query.block_ids)
    // },
    async saveFilter() {
      const data = Object.assign(this.query, {
        second: this.second,
        refresh_button: this.refresh_button,
      });
      const r = await setFilter(data);
      if (r.code === 200) {
        // this.$message.success("保存成功");
      }
    },
    async onSwitchDiff(id) {
      const r = await switchDiff(id);
      if (r.code === 200) {
        this.$message.success("更新成功");
        this.getTopics();
      }
    },
    filterTemp(data, i) {
      if (!data || !data.id) return;
      if (!this.query.block_id_temp.includes(data.id)) {
        this.query.block_id_temp.push(data.id);
      }
      this.pushTempFilterRow(data);
      // const symbol = data.currency_name + data.quote_name;
      // this.query.block_symbol_temp.push(symbol);
      // this.query.buy_platform_temp.push(data.buy_platform);
      // this.query.sell_platform_temp.push(data.sell_platform);
      this.list.data.splice(i, 1);
      this.saveTempFilterInfo();
    },
    async filterId(id) {
      const r = await blockId(id);
      console.log(r.code);
      if (r.code === 200) {
        this.$message.success("保存成功");
        this.getTopics();
      }
    },
    cellClassName({ column, row }) {
      const prop = column.property;
      // 买入总价列
      if (prop === "total_buy_price") {
        if (row.total_buy_price >= 2000) return "total-red";
        if (row.total_buy_price >= 1000) return "total-yellow";
        if (row.total_buy_price >= 100) return "total-green";

        return "";
      }

      // 卖出总价列
      if (prop === "total_sell_price") {
        if (row.total_sell_price >= 2000) return "total-red";
        if (row.total_sell_price >= 1000) return "total-yellow";
        if (row.total_sell_price >= 100) return "total-green";
        return "";
      }

      // 其他列无样式
      return "";
    },

    cellStyle({ column, row }) {
      const clearStyle = { backgroundColor: "", color: "" };

      if (column.property === "total_buy_price") {
        if (row.total_buy_price >= 2000) {
          return { backgroundColor: "#cd0000", color: "#fff" };
        }
        if (row.total_buy_price >= 1000) {
          return { backgroundColor: "#bdb76b", color: "#606266" };
        }
        if (row.total_buy_price >= 100) {
          return {
            backgroundColor: "rgba(0, 150, 135, 0.33)",
            color: "#606266",
          };
        }
        return clearStyle;
      }

      if (column.property === "total_sell_price") {
        if (row.total_sell_price >= 2000) {
          return { backgroundColor: "#cd0000", color: "#fff" };
        }
        if (row.total_sell_price >= 1000) {
          return { backgroundColor: "#bdb76b", color: "#606266" };
        }
        if (row.total_sell_price >= 100) {
          return {
            backgroundColor: "rgba(0, 150, 135, 0.33)",
            color: "#606266",
          };
        }
        return clearStyle;
      }

      return clearStyle;
    },
  },
};
</script>
