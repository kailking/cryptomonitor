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
/* 禁用表格内部滚动条，使用浏览器滚动条 */

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
        placeholder="查询币种"
        :size="is_mobile ? 'small' : 'default'"
        style="width: 200px"
        class="filter-item"
        @keyup.enter.native="handleFilter"
      />
      <el-select
        v-model="query.status"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="筛选"
        clearable
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
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        type="danger"
        @click="handleSwitchBatch(false)"
      >
        批量禁用
      </el-button>
      <el-button
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        type="success"
        @click="handleSwitchBatch(true)"
      >
        批量启用
      </el-button>
    </div>

    <el-table
      :data="list.data"
      element-loading-text="Loading"
      border
      :fit="false"
      highlight-current-row
      @cell-click="cellClick"
      @header-dragend="handleHeaderDragend"
      @selection-change="handleSelectionChange"
    >
      <el-table-column
        type="selection"
        prop="selection"
        :width="getWidth('selection', 40)"
      />
      <el-table-column
        label="交易对"
        :width="getWidth('symbol', 150)"
        prop="symbol"
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
        :width="getWidth('platform_buy', 150)"
        prop="platform_buy"
        align="center"
      >
        <template slot-scope="scope">
          {{ scope.row.platform_buy }}
          {{ scope.row.quote_name == "USDT" ? "" : scope.row.quote_name }}
        </template>
      </el-table-column>
      <el-table-column
        label="卖出平台"
        :width="getWidth('platform_sell', 150)"
        prop="platform_sell"
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
        label="备注"
        align="center"
        :width="getWidth('remark', 300)"
        prop="remark"
      >
        <!-- <template slot-scope="scope">
          <template v-if="scope.row.edit">
            <el-input
              v-model="scope.row.remark"
              class="edit-input"
              size="small"
            />
            <el-button
              class="cancel-btn"
              size="small"
              icon="el-icon-refresh"
              type="warning"
              @click="cancelEdit(scope.row)"
            >
              cancel
            </el-button>
          </template>
          <span v-else>{{ scope.row.remark }}</span>
        </template> -->

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
        label="状态"
        align="center"
        class-name="status-col"
        :width="getWidth('block_status', 100)"
        prop="block_status"
      >
        <template slot-scope="{ row }">
          <el-tag :type="row.block_status | statusFilter">
            {{ row.block_status ? "禁用" : "正常" }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column
        label="更新时间"
        align="center"
        class-name="status-col"
        :width="getWidth('updated_at', 100)"
        prop="block_status"
      >
        <template slot-scope="{ row }">
          {{ row.updated_at }}
        </template>
      </el-table-column>
      <el-table-column
        label="过滤"
        prop="filter"
        :width="getWidth('filter', 100)"
      >
        <template slot-scope="scope">
          <el-switch
            v-model="scope.row.block_status"
            active-color="#13ce66"
            inactive-color="#999"
            :active-value="true"
            :inactive-value="false"
            @change="filterId(scope.row.id)"
          />
          <!-- <el-button
            v-if="scope.row.block_status == false"
            size="mini"
            type="danger"
            plain
            @click="filterId(scope.row.id)"
            >禁用</el-button
          >
          <el-button
            v-else
            size="mini"
            type="primary"
            plain
            @click="filterId(scope.row.id)"
            >开启</el-button
          > -->
          <!-- <template v-if="scope.row.block_status == true">
            <el-button
              v-if="scope.row.edit"
              type="success"
              size="small"
              icon="el-icon-circle-check-outline"
              @click="confirmEdit(scope.row)"
            >
              保存
            </el-button>
            <el-button
              v-else
              type="primary"
              size="small"
              icon="el-icon-edit"
              @click="handleEdit(scope.row)"
            >
              编辑备注
            </el-button>
          </template> -->
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
import { getDiffConfig, getPlatformList, postRemark } from "@/api/table";
import { blockId, blockIdBatch, updateBlockRemark } from "@/api/user";
import Pagination from "@/components/pagination";
import { isMobile } from "@/utils";
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
  edit: false,
  remark: "",
};
const diffList = [
  { key: "0", display_name: "全部" },
  { key: "1", display_name: "已禁用" },
  { key: "2", display_name: "未禁用" },
];

export default {
  components: {
    Pagination,
  },
  filters: {
    statusFilter(status) {
      const statusMap = {
        false: "success",
        true: "danger",
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
      temp2: {
        id: undefined,
        remark: "",
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
      const saved = localStorage.getItem(`diff_config_table_col_${prop}_width`);
      return saved ? parseInt(saved) : defaultWidth;
    },
    handleHeaderDragend(newWidth, oldWidth, column) {
      localStorage.setItem(
        `diff_config_table_col_${column.property}_width`,
        newWidth
      );
    },
    handleSwitchBatch(is_delete) {
      if (!this.selectList.length) {
        return this.$message.error("至少勾选一个交易对");
      }
      const ids = [];
      this.selectList.forEach((item) => {
        ids.push(item.id);
      });
      blockIdBatch({ id: ids.join(","), is_delete: is_delete }).then((res) => {
        if (res.code === 200) {
          this.$message.success("更新成功");
          this.getTopics();
        }
      });
    },

    handleSelectionChange(val) {
      this.selectList = val;
    },
    async getTopics() {
      this.loading = true;
      const res = await getDiffConfig(this.query);
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
    cellClick(row, column) {
      const prop = column.property;
      if (prop == "remark") {
        this.onRemarkClick(row);
      }
    },
    onRemarkClick(row) {
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
    },
    onRemarkBlur(row) {
      this.list.data.forEach((item) => {
        this.$set(item, "is_remark", false);
      });

      // updateBlockRemark({
      //   id: row.id,
      //   remark: row.remark,
      // });
      postRemark({
        diff_id: row.id,
        id: row.remark_id,
        buy_platform: row.buy_platform,
        sell_platform: row.sell_platform,
        match_id: row.match_id,
        sell_match_id: row.sell_match_id,
        remark: row.remark,
      });
    },
    handleEdit(row) {
      row.originalTitle = row.remark;
      row.edit = true;
    },
    cancelEdit(row) {
      row.remark = row.originalTitle;
      row.edit = false;
      this.$message({
        message: "取消成功",
        type: "warning",
      });
    },
    confirmEdit(row) {
      row.edit = false;
      row.originalTitle = row.remark;
      this.temp2.id = row.id;
      this.temp2.remark = row.remark;
      updateBlockRemark(this.temp2).then(() => {
        this.$message({
          message: "保存成功",
          type: "success",
        });
        this.getTopics();
      });
    },
    async initPlatform() {
      const res2 = await getPlatformList();
      // console.log(res2.data)
      this.platformList = res2.data;
      // console.log(this.platformList)
    },
    async filterId(id) {
      const r = await blockId(id);
      console.log(r.code);
      if (r.code === 200) {
        this.$message.success("保存成功");
        this.getTopics();
      }
    },
  },
};
</script>
