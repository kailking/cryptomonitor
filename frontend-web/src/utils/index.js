/**
 * Created by PanJiaChen on 16/11/18.
 */

/**
 * Parse the time to string
 * @param {(Object|string|number)} time
 * @param {string} cFormat
 * @returns {string | null}
 */
export function parseTime(time, cFormat) {
  if (arguments.length === 0) {
    return null;
  }
  const format = cFormat || "{y}-{m}-{d} {h}:{i}:{s}";
  let date;
  if (typeof time === "object") {
    date = time;
  } else {
    if (typeof time === "string" && /^[0-9]+$/.test(time)) {
      time = parseInt(time);
    }
    if (typeof time === "number" && time.toString().length === 10) {
      time = time * 1000;
    }
    date = new Date(time);
  }
  const formatObj = {
    y: date.getFullYear(),
    m: date.getMonth() + 1,
    d: date.getDate(),
    h: date.getHours(),
    i: date.getMinutes(),
    s: date.getSeconds(),
    a: date.getDay()
  };
  const time_str = format.replace(/{([ymdhisa])+}/g, (result, key) => {
    const value = formatObj[key];
    // Note: getDay() returns 0 on Sunday
    if (key === "a") {
      return ["日", "一", "二", "三", "四", "五", "六"][value];
    }
    return value.toString().padStart(2, "0");
  });
  return time_str;
}

/**
 * @param {number} time
 * @param {string} option
 * @returns {string}
 */
export function formatTime(time, option) {
  if (("" + time).length === 10) {
    time = parseInt(time) * 1000;
  } else {
    time = +time;
  }
  const d = new Date(time);
  const now = Date.now();

  const diff = (now - d) / 1000;

  if (diff < 30) {
    return "刚刚";
  } else if (diff < 3600) {
    // less 1 hour
    return Math.ceil(diff / 60) + "分钟前";
  } else if (diff < 3600 * 24) {
    return Math.ceil(diff / 3600) + "小时前";
  } else if (diff < 3600 * 24 * 2) {
    return "1天前";
  }
  if (option) {
    return parseTime(time, option);
  } else {
    return (
      d.getMonth() +
      1 +
      "月" +
      d.getDate() +
      "日" +
      d.getHours() +
      "时" +
      d.getMinutes() +
      "分"
    );
  }
}

/**
 * @param {string} url
 * @returns {Object}
 */
export function param2Obj(url) {
  const search = url.split("?")[1];
  if (!search) {
    return {};
  }
  return JSON.parse(
    '{"' +
      decodeURIComponent(search)
        .replace(/"/g, '\\"')
        .replace(/&/g, '","')
        .replace(/=/g, '":"')
        .replace(/\+/g, " ") +
      '"}'
  );
}

export function copyText(that, text, successTip, failTip) {
  // 如果传的是函数，先执行拿到字符串
  const rawText = typeof text === "function" ? text() : text;
  const finalText = String(rawText);

  // 创建临时 textarea
  const ta = document.createElement("textarea");
  ta.value = finalText;
  ta.style.position = "fixed";
  ta.style.opacity = "0";
  document.body.appendChild(ta);
  ta.select();

  let ok = false;
  try {
    ok = document.execCommand("copy");
  } catch (e) {
    ok = false;
  }
  document.body.removeChild(ta);

  // 用 Element-UI 的 Message 提示
  if (ok) {
    that.$message.success(successTip || "已复制到剪贴板");
  } else {
    that.$message.error(failTip || "复制失败，请手动复制");
  }
}
export function toFixed2(val) {
  if (val >= 10) return val;
  else return "0" + val;
}
export function covertime(time, dataType = "ymdhis") {
  let date;
  if (time) date = new Date(time);
  else date = new Date();
  const y = toFixed2(date.getFullYear());
  const m = toFixed2(date.getMonth() + 1);
  const d = toFixed2(date.getDate());
  const h = toFixed2(date.getHours());
  const i = toFixed2(date.getMinutes());
  const s = toFixed2(date.getSeconds());
  if (dataType == "ymd") {
    return `${y}-${m}-${d}`;
  } else if (dataType == "ymdh") {
    return `${y}-${m}-${d} ${h}`;
  } else if (dataType == "ym") {
    return `${y}-${m}`;
  } else if (dataType == "d") {
    return `${d}`;
  } else if (dataType == "m") {
    return m;
  } else if (dataType == "y") {
    return y;
  } else if (dataType == "hi") {
    return `${h}:${i}`;
  } else if (dataType == "his") {
    return `${h}:${i}:${s}`;
  } else {
    return `${y}-${m}-${d} ${h}:${i}:${s}`;
  }
}
export function formatDecimal(value, maxDecimals = 2) {
  const num = Number(value);
  if (isNaN(num)) return "0.00";

  // 处理极大或极小数
  if (Math.abs(num) < 1e-6 || Math.abs(num) > 1e15) {
    return num.toLocaleString("en-US", {
      minimumFractionDigits: maxDecimals,
      maximumFractionDigits: maxDecimals,
      useGrouping: false
    });
  }

  // 普通数字用 toFixed
  return num.toFixed(maxDecimals);
}
export function parseNumber(str) {
  if (typeof str === "number") return str;

  const cleaned = String(str).replace(/[^0-9.-]/g, "");
  const num = parseFloat(cleaned);

  if (isNaN(num)) return 0;

  // 如果是极小数（会显示为科学计数法），返回格式化后的字符串
  if (Math.abs(num) < 1e-6 && Math.abs(num) > 0) {
    // 移除末尾多余的零，但保留精度
    return cleaned.replace(/\.?0+$/, "") || "0";
  }

  return num;
}
export function isMobile() {
  return window.matchMedia("(max-width: 768px)").matches;
}
export function formatSmartDecimal(numStr) {
  const num = parseFloat(numStr);
  if (isNaN(num) || num === 0) return "0";

  const isNegative = num < 0;
  const absNum = Math.abs(num);

  // 处理大数（≥1000）：有效数字4位，后面补0保持数量级
  if (absNum >= 1000) {
    const str = Math.floor(absNum).toString();
    if (str.length <= 4) {
      return (isNegative ? "-" : "") + str;
    }
    // 超过4位，截断后补0（如 12345 → 12340）
    const truncated = str.substring(0, 4);
    const zeros = "0".repeat(str.length - 4);
    return (isNegative ? "-" : "") + truncated + zeros;
  }

  // 处理中等数字（1 ≤ x < 1000）
  if (absNum >= 1) {
    const str = absNum.toString();
    const parts = str.split(".");
    const intPart = parts[0];
    const decPart = parts[1] || "";
    const intLen = intPart.length;
    const needDec = 4 - intLen;

    if (needDec <= 0) {
      // 整数部分已够4位，直接返回（如 1234.56 → 1234）
      return (isNegative ? "-" : "") + intPart;
    } else {
      // 需要从小数部分补足
      let targetDec = decPart.substring(0, needDec);
      // 如果decimal不够长，补0
      if (targetDec.length < needDec) {
        targetDec += "0".repeat(needDec - targetDec.length);
      }
      // 去掉末尾的0
      targetDec = targetDec.replace(/0+$/, "");
      if (targetDec === "") {
        return (isNegative ? "-" : "") + intPart;
      }
      return (isNegative ? "-" : "") + intPart + "." + targetDec;
    }
  }

  // 处理小数（< 1）：如 0.005350553395 → 0.00535（保留4位有效数字，去尾0）
  let str = absNum.toFixed(20);
  // 去掉科学计数法展开后的末尾0
  str = str.replace(/(\.\d*?)0+$/, "$1");

  const parts = str.split(".");
  const decPart = parts[1] || "";

  // 找到第一个非零数字的位置
  let firstNonZeroIndex = -1;
  for (let i = 0; i < decPart.length; i++) {
    if (decPart[i] !== "0") {
      firstNonZeroIndex = i;
      break;
    }
  }

  if (firstNonZeroIndex === -1) return "0";

  // 截取：前导零 + 4位有效数字
  const neededLen = firstNonZeroIndex + 4;
  let target = decPart.substring(0, neededLen);

  // 如果位数不够，补0（如 0.0005 只有1位非零，补到 0005）
  if (target.length < neededLen) {
    target += "0".repeat(neededLen - target.length);
  }

  // 去掉末尾的0（关键修改）
  target = target.replace(/0+$/, "");

  // 如果全是0（理论上不会发生），返回0
  if (target === "" || /^0+$/.test(target)) return "0";

  return (isNegative ? "-" : "") + "0." + target;
}
