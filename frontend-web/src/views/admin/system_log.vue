<style scoped lang="scss">
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
.filter-container {
  margin-bottom: 10px;
}
/deep/ .el-table td {
  padding: 5px 0;
}
</style>
<template>
  <div class="app-container">
    <div class="filter-container">
      <el-input
        v-model="query.search"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="搜索用户"
        style="width: 200px"
        class="filter-item"
        @keyup.enter.native="handleFilter"
      />
      <el-select
        v-model="query.type"
        :size="is_mobile ? 'small' : 'default'"
        placeholder="日志类型"
        clearable
        style="width: 150px"
        class="filter-item"
      >
        <el-option
          v-for="item in logTypeList"
          :key="item.key"
          :label="item.item"
          :value="item.key"
        />
      </el-select>
      <el-date-picker
        v-model="query.timestamp_start"
        :size="is_mobile ? 'small' : 'default'"
        type="datetime"
        value-format="yyyy-MM-dd HH:mm:ss"
        placeholder="开始时间"
      />
      <el-date-picker
        v-model="query.timestamp_end"
        :size="is_mobile ? 'small' : 'default'"
        type="datetime"
        value-format="yyyy-MM-dd HH:mm:ss"
        placeholder="结束时间"
      />

      <el-button
        :size="is_mobile ? 'small' : 'default'"
        class="filter-item"
        type="primary"
        icon="el-icon-search"
        @click="handleFilter"
      >
        搜索
      </el-button>
    </div>

    <!--    <div class="filter-container">-->
    <!--      -->
    <!--    </div>-->
    <el-table
      :data="list.data"
      element-loading-text="Loading"
      border
      fit
      highlight-current-row
      @header-dragend="handleHeaderDragend"
    >
      <el-table-column
        label="触发用户"
        align="center"
        prop="account"
        :width="getWidth('account', 150)"
      >
        <template slot-scope="scope">
          <span class="symbol-link">
            {{ scope.row.account }}
          </span>
        </template>
      </el-table-column>
      <el-table-column
        align="center"
        label="类型"
        prop="type_text"
        :width="getWidth('type_text', 95)"
      >
        <template slot-scope="scope">
          {{ scope.row.type_text }}
        </template>
      </el-table-column>
      <el-table-column
        align="center"
        label="内容"
        :width="getWidth('remark', 500)"
        prop="remark"
      >
        <template slot-scope="scope">
          {{ scope.row.remark }}
        </template>
      </el-table-column>
      <el-table-column
        align="center"
        label="时间"
        :width="getWidth('created_at', 170)"
        prop="created_at"
      >
        <template slot-scope="scope">
          {{ scope.row.created_at }}
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
import { getSystemLogType, getSystemLog } from "@/api/table";
import Pagination from "@/components/pagination";
import { isMobile } from "@/utils";
const defaultData = {
  id: "",
  account: "",
  type_text: "",
  remark: "",
  created_at: "",
};

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
      logTypeList: [],
      showAll: false,
      query: {
        order: "",
        page: 1,
        page_size: 50,
        search: "",
        type: "",
        timestamp_start: "",
        timestamp_end: "",
      },
      lists: [],
      options: [],
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
      const saved = localStorage.getItem(`diff_table_col_${prop}_width`);
      return saved ? parseInt(saved) : defaultWidth;
    },
    handleHeaderDragend(newWidth, oldWidth, column) {
      localStorage.setItem(`diff_table_col_${column.property}_width`, newWidth);
    },

    async getTopics() {
      this.loading = true;
      const res = await getSystemLog(this.query);
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
      const res2 = await getSystemLogType();
      // console.log(res2.data)
      this.logTypeList = res2.data;
      // console.log(this.platformList)
    },
  },
};
</script>
