<style lang="scss" scoped>
.filter-container {
  margin-bottom: 10px;
}
.price-red {
  /* element-ui 元素*/
  color: red;
  font-size: larger;
}
.price-yellow {
  color: dodgerblue;
}
#searchBox {
  overflow: hidden;
}
.el-table {
  width: 100% !important;
  min-width: max-content;
}
.el-table__body-wrapper {
  overflow-x: visible !important;
  overflow-y: visible !important;
}
.el-table__header-wrapper {
  overflow-x: visible !important;
}
</style>
<template>
  <div class="app-container">
    <div class="filter-container">
      <el-input
        v-model="query.symbol"
        placeholder="查询币种或ID"
        style="width: 200px"
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        @keyup.enter.native="handleFilter"
      />
      <el-select
        v-model="query.status"
        placeholder="筛选"
        clearable
        :size="is_mobile ? 'small' : 'default'"
        style="width: 150px"
        class="filter-item"
      >
        <el-option
          v-for="item in diffList"
          :key="item.key"
          :label="item.display_name"
          :value="item.key"
        />
      </el-select>
      <el-select
        v-model="query.platform"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="搜索平台"
        clearable
        style="width: 150px"
        class="filter-item"
      >
        <el-option
          v-for="item in platformList"
          :key="item.key"
          :label="item.item"
          :value="item.key"
        />
      </el-select>
      <el-button
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        type="primary"
        icon="el-icon-search"
        @click="handleFilter"
      >
        搜索
      </el-button>
      <el-button
        v-if="canUpdateMarket"
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        type="danger"
        @click="handleSwitchBatch(0)"
      >
        批量禁用
      </el-button>
      <el-button
        v-if="canUpdateMarket"
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        type="success"
        @click="handleSwitchBatch(1)"
      >
        批量启用
      </el-button>
    </div>

    <el-table
      v-loading="loading"
      :data="list.data"
      element-loading-text="Loading"
      border
      fit
      highlight-current-row
      @selection-change="handleSelectionChange"
      @header-dragend="handleHeaderDragend"
    >
      <el-table-column
        v-if="canUpdateMarket"
        type="selection"
        prop="selection"
        :width="getWidth('selection', 40)"
      />
      <el-table-column
        label="交易对"
        prop="symbol"
        :width="getWidth('symbol', 150)"
        align="center"
      >
        <template slot-scope="scope">
          <span class="symbol-link">
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
          {{ scope.row.platform_buy }}
          {{ scope.row.quote_name == "USDT" ? "" : scope.row.quote_name }}
        </template>
      </el-table-column>
      <el-table-column
        label="卖出平台"
        prop="platform_sell"
        :width="getWidth('platform_sell', 100)"
        align="center"
      >
        <template slot-scope="scope">
          {{ scope.row.platform_sell }}
          {{
            scope.row.sell_quote_name == "USDT" ? "" : scope.row.sell_quote_name
          }}
        </template>
      </el-table-column>
      <el-table-column
        label="状态"
        class-name="status-col"
        prop="is_show"
        :width="getWidth('is_show', 70)"
      >
        <template slot-scope="{ row }">
          <el-tag :type="row.is_show | statusFilter">
            {{ row.show_text }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column
        align="center"
        label="ID"
        prop="id"
        :width="getWidth('id', 95)"
      >
        <template slot-scope="scope">
          {{ scope.row.id }}
        </template>
      </el-table-column>
      <el-table-column
        label="操作"
        prop="filter"
        :width="getWidth('filter', 100)"
      >
        <template slot-scope="scope">
          <el-button
            v-if="canUpdateMarket && scope.row.is_show == 1"
            size="mini"
            type="danger"
            plain
            @click="filterId(scope.row.id)"
            >禁用</el-button
          >
          <el-button
            v-if="canUpdateMarket && scope.row.is_show != 1"
            size="mini"
            type="primary"
            plain
            @click="filterId(scope.row.id)"
            >开启</el-button
          >
        </template>
      </el-table-column>
    </el-table>
    <Pagination
      :list="list"
      @handleSizeChange="handleSizeChange"
      @handleCurrentChange="handleCurrentChange"
    />
  </div>
</template>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>
<script>
import { getPlatformList } from "@/api/table";
import { getDiffSetting, switchDiff, switchDiffBatch } from "@/api/setting";
import Pagination from "@/components/pagination";
import { isMobile } from "@/utils/index";
import { hasPermission } from "@/utils/permissions";
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
  is_show: 1,
};
const diffList = [
  { key: "", display_name: "全部" },
  { key: 0, display_name: "已禁用" },
  { key: 1, display_name: "未禁用" },
];

export default {
  components: {
    Pagination,
  },
  filters: {
    statusFilter(status) {
      const statusMap = {
        1: "success",
        0: "danger",
      };
      return statusMap[status];
    },
  },
  data() {
    return {
      topic: Object.assign({}, defaultData),
      index: 0,
      routes: [],
      list: [],
      loading: true,
      platformList: [],
      showAll: false,
      query: {
        order: "",
        page: 1,
        page_size: 50,
        symbol: "",
        status: "",
        platform: "",
      },
      lists: [],
      currencyList: [
        { key: "USDT", item: "USDT" },
        { key: "BTC", item: "BTC" },
        { key: "ETH", item: "ETH" },
      ],
      diffList: diffList,
      options: [],
      refresh_button: 2,
      second: 5000,
      intervalId: null,
      selectList: [],
      is_mobile: isMobile(),
    };
  },
  computed: {
    canUpdateMarket() {
      return hasPermission(
        "settings.market.update",
        this.$store.getters.permissions
      );
    },
  },

  created() {
    // 改成在标记条件后初始化
    this.getTopics();
    this.initPlatform();
  },
  mounted() {
    /**
     * 收起搜索
     */
  },
  methods: {
    getWidth(prop, defaultWidth) {
      const saved = localStorage.getItem(`diff_table_col_${prop}_width`);
      return saved ? parseInt(saved) : defaultWidth;
    },
    handleHeaderDragend(newWidth, oldWidth, column) {
      localStorage.setItem(`diff_table_col_${column.property}_width`, newWidth);
    },
    handleSwitchBatch(is_show) {
      if (
        !hasPermission(
          "settings.market.update",
          this.$store.getters.permissions
        )
      ) {
        return;
      }
      if (!this.selectList.length) {
        return this.$message.error("至少勾选一个交易对");
      }
      const ids = [];
      this.selectList.forEach((item) => {
        ids.push(item.id);
      });
      switchDiffBatch({ id: ids.join(","), is_show: is_show }).then((res) => {
        if (res.code === 200) {
          this.$message.success("更新成功");
          this.getTopics();
        }
      });
    },

    handleSelectionChange(val) {
      if (
        !hasPermission(
          "settings.market.update",
          this.$store.getters.permissions
        )
      ) {
        return;
      }
      this.selectList = val;
    },
    async getTopics() {
      this.loading = true;
      const res = await getDiffSetting(this.query);
      this.list = res.data;
      this.loading = false;
    },
    handleFilter() {
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
    async initPlatform() {
      const res2 = await getPlatformList();
      // console.log(res2.data)
      this.platformList = res2.data;
      // console.log(this.platformList)
    },
    async filterId(id) {
      if (
        !hasPermission(
          "settings.market.update",
          this.$store.getters.permissions
        )
      ) {
        return;
      }
      const r = await switchDiff(id);
      if (r.code === 200) {
        this.$message("更新成功");
        this.getTopics();
      }
    },
  },
};
</script>
