import Vue from "vue";

import "normalize.css/normalize.css"; // A modern alternative to CSS resets

import ElementUI from "element-ui";
import "element-ui/lib/theme-chalk/index.css";
import locale from "element-ui/lib/locale/lang/zh-CN"; // lang i18n

import "@/styles/index.scss"; // global css

import App from "./App";
import store from "./store";
import router from "./router";

import "@/icons"; // icon
import "@/permission"; // permission control

// set ElementUI lang to ZH-CN
Vue.use(ElementUI, { locale });
// 如果想要中文版 element-ui，按如下方式声明
// Vue.use(ElementUI)

Vue.config.productionTip = false;
(function() {
  let down = false;

  const isInput = el =>
    el &&
    (el.tagName === "INPUT" ||
      el.tagName === "TEXTAREA" ||
      el.contentEditable === "true");

  /* ---------- 1. 拖动实时清选区 ---------- */
  document.addEventListener("mousedown", e => {
    down = true;
  });
  document.addEventListener("mousemove", e => {
    if (!down) return;
    if (!isInput(e.target)) window.getSelection().removeAllRanges();
  });
  document.addEventListener("mouseup", () => {
    down = false;
  });

  /* ---------- 2. 禁止全局 Ctrl+A ---------- */
  document.addEventListener("keydown", e => {
    // Ctrl+A 且不在输入框里
    if (
      (e.ctrlKey || e.metaKey) &&
      e.key.toLowerCase() === "a" &&
      !isInput(e.target)
    ) {
      e.preventDefault();
    }
  });
})();
new Vue({
  el: "#app",
  router,
  store,
  render: h => h(App)
});

