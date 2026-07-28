const SECURITY_LOG_TYPE = 2;

const riskLevels = [
  {
    prefix: "[高风险]",
    label: "高风险",
    tagType: "danger",
    rowClass: "security-log-high",
  },
  {
    prefix: "[风险]",
    label: "风险",
    tagType: "warning",
    rowClass: "security-log-risk",
  },
  {
    prefix: "[注意]",
    label: "注意",
    tagType: "",
    rowClass: "security-log-notice",
  },
];

function isSecurityLog(row) {
  return Number(row && row.type) === SECURITY_LOG_TYPE;
}

function legacySecuritySummary(remark) {
  if (/ip或指纹|登录异常|登陆异常/i.test(remark)) {
    return "IP或浏览器指纹发生变化（历史记录）";
  }

  return remark || "异常登录记录";
}

function standardLogTag(row) {
  const type = Number(row && row.type);
  if (type === 1) return { label: row.type_text || "续费", tagType: "success" };
  if (type === 3 || type === 4) {
    return { label: "系统操作", tagType: "info" };
  }
  return { label: (row && row.type_text) || "系统日志", tagType: "info" };
}

export function getSystemLogPresentation(row = {}) {
  const remark = typeof row.remark === "string" ? row.remark.trim() : "";

  if (!isSecurityLog(row)) {
    const tag = standardLogTag(row);
    return {
      ...tag,
      summary: remark || "—",
      details: remark,
      showDetails: remark.length > 80,
      rowClass: "",
    };
  }

  const risk = riskLevels.find((item) => remark.startsWith(item.prefix));
  if (risk) {
    return {
      label: risk.label,
      tagType: risk.tagType,
      summary: remark.slice(risk.prefix.length).trim() || "异常登录记录",
      details: remark,
      showDetails: false,
      rowClass: risk.rowClass,
    };
  }

  const summary = legacySecuritySummary(remark);
  return {
    label: "历史异常",
    tagType: "info",
    summary,
    details: remark,
    showDetails: Boolean(remark) && remark !== summary,
    rowClass: "security-log-legacy",
  };
}

export function systemLogRowClass({ row } = {}) {
  return getSystemLogPresentation(row).rowClass;
}
