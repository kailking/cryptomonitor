<style lang="scss" scoped>
.price-red {
  /* element-ui 元素*/
  color: red;
  font-size: larger;
}
.price-yellow {
  color: dodgerblue;
}
.price-green {
  color: green;
  font-size: larger;
}
#searchBox {
  overflow: hidden;
}
.pricedown {
  padding: 5px;
  color: #009688;
  background: #f0f9ff;
}
</style>
<template>
  <div>
    <div class="pricedown">📉 下跌行情</div>
    <div class="filter-container">
      <div
        class="notMobile-mt10"
        :style="{
          marginBottom: showAll ? '0' : '10px',
        }"
      >
        <el-input
          v-model="query.symbol"
          placeholder="查询交易对"
          style="width: 100px"
          :size="is_mobile ? 'small' : 'default'"
          class="filter-item"
        />
        <el-button
          class="filter-item"
          type="primary"
          :size="is_mobile ? 'small' : 'default'"
          icon="el-icon-search"
          @click="handleFilterSave"
        >
          搜索
        </el-button>
        <span style="margin: 5px 0">自动刷新</span>
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
          v-model="query.change"
          :size="is_mobile ? 'small' : 'default'"
          placeholder="差价大于"
          clearable
          style="width: 150px"
          class="filter-item"
          @change="handleFilterSave"
        >
          <el-option
            v-for="item in diffList"
            :key="item.key"
            :label="item.display_name"
            :value="item.key"
          />
        </el-select>
        <!-- <span style="margin-right: 5px; margin-left: 5px">
        杠杆
        <el-switch
          v-model="query.margin_status"
          active-color="#13ce66"
          inactive-color="#999"
          :active-value="1"
          :inactive-value="0"
          @change="handleFilter"
        />
      </span> -->
        <!--      <el-input v-model="query.total_price" placeholder="总价大于(¥)" style="width: 200px;" class="filter-item" @keyup.enter.native="handleFilter" />-->
        <!--      <el-button class="filter-item" type="primary" icon="el-icon-pencil" style="float: right;" @click="saveFilter" >-->
        <!--        标记过滤条件-->
        <!--      </el-button>-->
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
      <div v-show="showAll" style="margin-bottom: 10px">
        <div style="padding-top: 10px">
          <span> 过滤 </span>
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
        <div>
          <span> 展示列 </span>
          <el-checkbox
            v-for="(item, index33) in lists"
            :key="item.key"
            v-model="lists[index33].ispass"
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
      :key="tablekey"
      :height="showAll ? '67vh' : '73vh'"
      :data="list.data"
      element-loading-text="Loading"
      border
      fit
      highlight-current-row
      @header-dragend="handleHeaderDragend"
    >
      <el-table-column
        v-if="lists[0].ispass"
        align="center"
        label="ID"
        prop="id"
        :width="getWidth('id', 65)"
      >
        <template slot-scope="scope">
          {{ scope.row.id }}
        </template>
      </el-table-column>

      <el-table-column
        label="币种"
        align="center"
        prop="currency_name"
        :width="getWidth('currency_name', 110)"
      >
        <template slot-scope="scope">
          <span class="symbol-link" @click="copyText(scope.row.currency_name)">
            {{ scope.row.currency_name }}
          </span>
        </template>
      </el-table-column>
      <!-- <el-table-column v-if="lists[1].ispass" label="交易对" align="center">
        <template slot-scope="scope">
          <span
            style="color: lightseagreen"
            @click="copyText(scope.row.symbol)"
          >
            {{ scope.row.symbol }}
          </span>
        </template>
      </el-table-column> -->
      <el-table-column
        label="平台"
        align="center"
        prop="platform_text"
        :width="getWidth('platform_text', 100)"
      >
        <template slot-scope="scope">
          <div
            style="
              display: flex;
              align-items: center;
              justify-content: center;
              color: blue;
            "
            @click="
              jumpLink(
                scope.row.platform,
                scope.row.currency_name,
                scope.row.quote_name
              )
            "
          >
            {{ scope.row.platform_text }}

            <img
              v-if="scope.row.margin_status == 1"
              src="@/assets/margin_status.png"
              style="width: 20px; height: 20px; margin-left: 5px"
            />
          </div>
        </template>
      </el-table-column>
      <el-table-column
        label="价格差(%)"
        align="center"
        :width="getWidth('direction')"
        prop="direction"
      >
        <template slot-scope="scope">
          <span v-if="scope.row.direction === 1" class="price-diff-red-link">
            {{ scope.row.change }}%
          </span>
          <span
            v-else-if="scope.row.direction === 2"
            class="price-diff-green-link"
          >
            - {{ scope.row.change }}%
          </span>
          <span v-else>{{ scope.row.change }}</span>
        </template>
      </el-table-column>
      <el-table-column
        v-if="lists[2].ispass"
        label="时间间隔(分钟)"
        align="center"
        :width="getWidth('period', 120)"
        prop="period"
      >
        <template slot-scope="scope">
          <span>{{ scope.row.period }}</span>
        </template>
      </el-table-column>
      <el-table-column
        label="历史价格"
        align="center"
        prop="price_begin"
        :width="getWidth('price_begin')"
      >
        <template slot-scope="scope">
          {{ scope.row.price_begin | toFloat }}
        </template>
      </el-table-column>
      <el-table-column
        label="实时价格"
        align="center"
        prop="price_end"
        :width="getWidth('price_end')"
      >
        <template slot-scope="scope">
          {{ scope.row.price_end | toFloat }}
        </template>
      </el-table-column>
      <el-table-column
        v-if="lists[1].ispass"
        align="center"
        prop="updated_at"
        label="更新时间"
        :width="getWidth('updated_at', 150)"
      >
        <template slot-scope="scope">
          <span>{{ scope.row.updated_at }}</span>
        </template>
      </el-table-column>
      <el-table-column
        label="过滤"
        prop="filter"
        :width="getWidth('filter', 140)"
      >
        <template slot-scope="scope">
          <el-switch
            v-model="scope.row['block_status']"
            active-color="#13ce66"
            inactive-color="#999"
            :active-value="false"
            :inactive-value="true"
            @change="filterIdTemp(scope.row, scope.$index)"
          />
          <el-button
            style="margin-left: 10px"
            size="mini"
            type="success"
            plain
            @click="filterId(scope.row.id)"
            >隐藏</el-button
          >
        </template>
      </el-table-column>
    </el-table>
    <div class="block" style="margin-top: 10px">
      <el-pagination
        layout="total, sizes, prev, pager, next, jumper"
        :current-page="list.current_page"
        :page-sizes="[50, 20, 10, 15, 50, 100]"
        :page-size="50"
        :pager-count="7"
        :total="list.total"
        @size-change="handleSizeChange"
        @current-change="handleCurrentChange"
      />
    </div>
  </div>
</template>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>
<script>
// import Multiselect from 'vue-multiselect'
import { getMarketChange, getPlatformList, getSymbolOption } from "@/api/table";
import {
  changeBlockId,
  setCommonFilter,
  getCommonFilter,
  getInfo,
} from "@/api/user";
import { copyText, isMobile, parseNumber } from "@/utils";
import { buildPlatformTradeUrl } from "@/utils/platform";
import { restartInterval, stopInterval } from "@/utils/interval";
import { createLatestRequestGuard } from "@/utils/latestRequest";
const defaultData = {
  id: "",
  symbol: "",
  currency_name: "",
  period: "",
  change: "",
  platform_text: "",
  price_begin: "",
  price_end: "",
  updated_at: "",
};
const diffList = [
  { key: "1", display_name: "1%" },
  { key: "3", display_name: "3%" },
  { key: "5", display_name: "5%" },
  { key: "15", display_name: "15%" },
  { key: "10", display_name: "10%" },
  { key: "20", display_name: "20%" },
  { key: "50", display_name: "50%" },
];

export default {
  filters: {
    toFloat(val) {
      if (val) return parseNumber(val);
      return val;
    },
    statusFilter(status) {
      const statusMap = {
        published: "success",
        draft: "gray",
        deleted: "danger",
      };
      return statusMap[status];
    },
  },
  components: {
    // Multiselect
  },
  data() {
    return {
      tablekey: "",
      lists: [
        {
          key: "id",
          label: "ID",
          ispass: false,
        },
        // {
        //   label: "交易对",
        //   ispass: true,
        // },
        {
          key: "updated_at",
          label: "更新时间",
          ispass: true,
        },
        {
          key: "period",
          label: "时间间隔(分钟)",
          ispass: false,
        },
      ],
      topic: Object.assign({}, defaultData),
      index: 0,
      routes: [],
      list: [],
      loading: true,
      platformList: [],
      showAll: !(
        localStorage.getItem("change_right_search_box_show_all") == "false" ||
        localStorage.getItem("change_right_search_box_show_all") == null
      ),
      query: {
        direction: 2,
        order: "",
        page: 1,
        page_size: 50,
        symbol: "",
        diff_price: "",
        total_price: "",
        platform: [],
        // block_symbol: [],
        block_ids: [],
        // block_symbol_temp: [],
        // platform_temp: [],
        block_id_temp: [],
      },
      diffList: diffList,
      options: [],
      refresh_button: 2,
      second: 5000,
      intervalId: null,
      isDisposed: false,
      is_mobile: isMobile(),
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
  },
  created() {
    this.topicsRequestGuard = createLatestRequestGuard();
    this.initPlatform();
    // this.initSymbols()
    this.initFilter();
    // 改成在标记条件后初始化
  },
  mounted() {},
  beforeDestroy() {
    this.isDisposed = true;
    if (this.topicsRequestGuard) this.topicsRequestGuard.invalidate();
    this.intervalId = stopInterval(this.intervalId);
  },
  methods: {
    jumpLink(platform, symbol, quoteName) {
      const url = buildPlatformTradeUrl(platform, symbol, quoteName);
      if (url) window.open(url);
    },
    getWidth(prop, defaultWidth) {
      const saved = localStorage.getItem(`diff_right_table_col_${prop}_width`);
      return saved ? parseInt(saved) : defaultWidth;
    },
    handleHeaderDragend(newWidth, oldWidth, column) {
      localStorage.setItem(
        `diff_right_table_col_${column.property}_width`,
        newWidth
      );
    },
    changeSecond() {
      this.saveFilter();

      this.dataRefresh();
    },
    changeSort() {
      const passedKeys = this.lists.reduce((arr, item) => {
        arr.push({ key: item.key, ispass: item.ispass });
        return arr;
      }, []);
      setCommonFilter({
        key: "change_right_columns",
        object: passedKeys,
      });
      this.tablekey = Date.now();
    },
    copyText(text) {
      copyText(this, text);
    },
    async getTopics() {
      const requestToken = this.topicsRequestGuard.begin();
      this.loading = true;
      let res;
      try {
        res = await getMarketChange(this.query);
      } catch (error) {
        if (this.topicsRequestGuard.isCurrent(requestToken)) {
          this.loading = false;
        }
        return;
      }
      if (!this.topicsRequestGuard.isCurrent(requestToken)) return;
      this.list = res.data;
      if (this.topicsRequestGuard.isCurrent(requestToken)) {
        this.loading = false;
      }
    },
    async initSymbols() {
      const res3 = await getSymbolOption();
      this.options = res3.data;
    },
    handlePlatformFilter() {
      setCommonFilter({
        key: "change_right_platform",
        object: this.query.platform,
      }).then((res) => {
        this.page = 1;
        this.getTopics();
      });
    },
    async initFilter() {
      await getCommonFilter({
        key: "change_right_platform",
      }).then((res) => {
        this.query.platform = Array.isArray(res.data) ? res.data : [];
      });
      await getInfo().then((res) => {
        const blockPlatform =
          res && res.data && typeof res.data.block_platform === "string"
            ? res.data.block_platform
            : "";
        const block_platform = blockPlatform.split(",").filter(Boolean);
        for (let i = 0; i < block_platform.length; i++) {
          if (!this.query.platform.includes(block_platform[i])) {
            this.query.platform.push(block_platform[i]);
          }
        }
      });
      const savedFilter = await getCommonFilter({
        key: "change_right_filter",
      });
      if (savedFilter.data.change) this.query.change = savedFilter.data.change;
      if (savedFilter.data.second) this.second = savedFilter.data.second;
      if (savedFilter.data.refresh_button) {
        this.refresh_button = savedFilter.data.refresh_button;
      }
      // const init_filter = await getFilter()
      // this.query.diff_price = init_filter.data.diff_price
      // this.query.platform = init_filter.data.platform
      // this.query.block_symbol = init_filter.data.block_symbol
      // this.query.block_ids = init_filter.data.block_ids
      // 在这里初始化
      getCommonFilter({
        key: "change_right_columns",
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
      // console.log(res2.data)
      this.platformList = res2.data;
      // console.log(this.platformList)
    },
    closeSearch() {
      this.showAll = !this.showAll;
      localStorage.setItem("change_right_search_box_show_all", this.showAll);
    },
    dataRefresh() {
      this.intervalId = stopInterval(this.intervalId);
      if (this.refresh_button !== 1) return;
      this.intervalId = restartInterval(
        this.intervalId,
        () => {
          if (this.refresh_button === 1) this.getTopics();
        },
        this.second,
        this.isDisposed
      );
    },
    handleFilter() {
      this.query.page = 1;
      this.getTopics();
    },
    handleFilterSave() {
      this.saveFilter();
      this.query.page = 1;
      this.getTopics();
    },
    handleSizeChange(size) {
      this.query.page_size = size;
      this.getTopics();
    },
    handleCurrentChange(page) {
      this.query.page = page;
      this.getTopics();
    },
    openRefresh() {
      this.saveFilter();
      this.dataRefresh();
    },
    handlePlatformChange(value) {
      // console.log(value)
      this.query.platform = value;
    },
    addTag(newTag) {
      const tag = {
        name: newTag,
        code: newTag.substring(0, 2) + Math.floor(Math.random() * 10000000),
      };
      this.options.push(tag);
      this.value.push(tag);
    },
    filterIdTemp(data, index) {
      // const symbol = data.currency_name + data.quote_name;
      // this.query.block_symbol_temp.push(symbol);
      // this.query.platform_temp.push(data.platform);
      this.query.block_id_temp.push(data.id);
      this.list.data.splice(index, 1);
    },
    async filterId(id) {
      const r = await changeBlockId(id);
      if (r.code === 200) {
        this.$message.success("更新成功");
        this.getTopics();
      }
    },
    removeBlockId(id) {
      const rl = this.query.block_ids;
      for (let i = 0; i < rl.length; i++) {
        if (rl[i] === id) {
          rl.splice(i, 1);
        }
      }
      this.query.block_ids = rl;
      console.log(this.query.block_ids);
    },
    async saveFilter() {
      setCommonFilter({
        key: "change_right_filter",
        object: {
          change: this.query.change,
          second: this.second,
          refresh_button: this.refresh_button,
        },
      });
      //   const r = await setFilter(this.query)
      //   console.log(r.code)
      //   if (r.code === 200) {
      //     this.$message('保存成功')
      //   }
    },
  },
};
</script>
