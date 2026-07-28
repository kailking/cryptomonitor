<template>
  <div class="pagination-tb">
    <el-pagination
      layout="total, sizes, prev, pager, next, jumper"
      :current-page="list.current_page"
      :page-sizes="pageSizes"
      :page-size="pageSize"
      :pager-count="pagerCount"
      :total="list.total"
      @size-change="handleSizeChange"
      @current-change="handleCurrentChange"
    />
  </div>
</template>
<style lang="scss" scoped>
.pagination-tb {
  position: fixed;
  bottom: 0;
  width: 100%;
  background: #fff;
  overflow: auto;
}
@media (max-width: 768px) {
  .pagination-tb {
    margin-top: 10px;
    position: static;
    overflow: visible;
  }
}
</style>
<script>
export default {
  name: "Pagination",
  props: {
    list: {
      type: Object,
      default: () => {
        return {
          current_page: 1,
          total: 0,
        };
      },
    },
    pageSizes: {
      type: Array,
      default: () => [50, 20, 10, 15, 30, 100],
    },
    pageSize: {
      type: Number,
      default: 50,
    },
    pagerCount: {
      type: Number,
      default: 7,
    },
  },
  methods: {
    handleSizeChange(val) {
      this.$emit("handleSizeChange", val);
    },
    handleCurrentChange(val) {
      this.$emit("handleCurrentChange", val);
    },
  },
};
</script>
