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
</style>
<template>
  <div class="app-container">
    <div class="filter-container" style="margin-bottom: 10px">
      <!-- 自动刷新
      <el-select
        v-model="second"
        placeholder="秒数"
        style="width: 150px"
        class="filter-item"
        @change="changeSecond"
      >
        <el-option :key="5000" :value="5000" label="5秒" />
        <el-option :key="10000" :value="10000" label="10秒" />
        <el-option :key="15000" :value="15000" label="15秒" />
      </el-select>

      <el-switch
        v-model="refresh_button"
        active-color="#13ce66"
        inactive-color="#999"
        :active-value="1"
        :inactive-value="2"
        @change="openRefresh"
      /> -->
      <el-button type="danger" @click="onRestartServer"> 重启服务器 </el-button>
      <!-- <el-button
        class="filter-item"
        type="success"
        icon="el-icon-search"
        @click="handleFilter"
      >
        暂停服务
      </el-button> -->
    </div>

    <el-table
      v-loading="loading"
      :data="platformList"
      element-loading-text="Loading"
      border
      fit
      highlight-current-row
      style="height: 100%"
    >
      <el-table-column type="index" label="序号" width="80" align="center" />
      <el-table-column label="平台" width="150" align="center">
        <template slot-scope="scope">
          <span style="color: #009688">
            {{ scope.row.item }}
          </span>
        </template>
      </el-table-column>
      <el-table-column align="center" label="操作" width="170">
        <template slot-scope="scope">
          <el-button
            size="mini"
            type="success"
            plain
            @click="onRestartPlatform(scope.row)"
            >重启</el-button
          >
        </template>
      </el-table-column>
    </el-table>
  </div>
</template>
<style src="vue-multiselect/dist/vue-multiselect.min.css"></style>
<script>
import { getSystemLogType, postRestartServer, getSystemLog } from "@/api/table";
import { getPlatformList } from "@/api/table";
import { settingServer } from "@/api/setting";
import Pagination from "@/components/pagination";
const defaultData = {
  id: "",
  account: "",
  type_text: "",
  remark: "",
  created_at: "",
};

export default {
  components: { Pagination },
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
      intervalId: null,
      second: 5000,
      refresh_button: 2,
      platformList: [],
    };
  },
  created() {
    // 改成在标记条件后初始化
    this.getTopics();
    this.initPlatform();
    this.getPlatformList();
  },
  mounted() {
    /**
     * 收起搜索
     */
  },
  beforeDestroy() {
    clearInterval(this.intervalId);
  },
  methods: {
    onRestartServer() {
      this.$msgbox
        .confirm(
          "确定重启服务器吗？,行情加载需要几分钟请耐心等待",
          "重启服务器",
          {
            confirmButtonText: "确定",
            cancelButtonText: "取消",
            type: "warning",
          }
        )
        .then(() => {
          postRestartServer()
            .then((res) => {
              this.$message({
                type: "success",
                message: "指令已发送，系统正在重启需要几分钟请耐心等待!",
              });
            })
            .catch(() => {
              this.$message({
                type: "error",
                message: "重启失败，请稍后再试!",
              });
            });
        });
    },
    async onRestartPlatform(row) {
      this.$msgbox
        .confirm(`确定重启 ${row.item} 平台吗？`, "重启平台", {
          confirmButtonText: "确定",
          cancelButtonText: "取消",
          type: "warning",
        })
        .then(async () => {
          try {
            await settingServer({ platform: row.key });
            this.$message({
              type: "success",
              message: "重启指令已发送!",
            });
          } catch (error) {
            this.$message({
              type: "error",
              message: "重启失败，请稍后再试!",
            });
          }
        });
    },
    dataRefresh() {
      // 计时器正在进行中，退出函数
      if (this.intervalId != null) {
        return;
      }
      // 计时器为空，操作
      this.intervalId = setInterval(() => {
        if (this.refresh_button === 1) {
          this.getTopics(true);
        }
      }, this.second);
    },
    changeSecond() {
      this.dataRefresh();
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
    async getPlatformList() {
      this.loading = true;
      const res = await getPlatformList();
      this.platformList = res.data;
      this.loading = false;
    },
  },
};
</script>
