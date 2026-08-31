import axios from "axios";
import { MessageBox, Message } from "element-ui";
import store from "@/store";
import { getToken } from "@/utils/auth";

export const AUTH_EXPIRED_EVENT = "cryptomonitor:auth-expired";

let reauthenticationFlow = null;

function safeGet(value, property) {
  try {
    return value == null ? undefined : value[property];
  } catch (error) {
    return undefined;
  }
}

function announceAuthenticationExpiry() {
  if (
    typeof window === "undefined" ||
    typeof window.dispatchEvent !== "function" ||
    typeof window.CustomEvent !== "function"
  ) {
    return;
  }
  window.dispatchEvent(new window.CustomEvent(AUTH_EXPIRED_EVENT));
}

function beginReauthentication() {
  announceAuthenticationExpiry();
  if (reauthenticationFlow) return reauthenticationFlow;
  reauthenticationFlow = Promise.resolve(
    MessageBox.confirm("登录状态过期", "确认退出登录", {
      confirmButtonText: "重新登录",
      cancelButtonText: "取消",
      type: "warning"
    })
  )
    .then(() => store.dispatch("user/resetToken"))
    .then(() => location.reload())
    .catch(() => {})
    .then(result => {
      reauthenticationFlow = null;
      return result;
    });
  return reauthenticationFlow;
}

// create an axios instance
const service = axios.create({
  baseURL: process.env.VUE_APP_BASE_API, // url = base url + request url
  // withCredentials: true, // send cookies when cross-domain requests
  timeout: 5000 // request timeout
});

// request interceptor
service.interceptors.request.use(
  config => {
    // do something before request is sent

    if (store.getters.token) {
      // let each request carry token
      // ['X-Token'] is a custom headers key
      // please modify it according to the actual situation
      config.headers["X-Token"] = getToken();
    }
    return config;
  },
  error => {
    // do something with request error
    console.log(error); // for debug
    return Promise.reject(error);
  }
);

// response interceptor
service.interceptors.response.use(
  /**
   * If you want to get http information such as headers or status
   * Please return  response => response
   */

  /**
   * Determine the request status by custom code
   * Here is just an example
   * You can also judge the status by HTTP Status Code
   */
  response => {
    const res = response.data;

    // if the custom code is not 20000, it is judged as an error.
    if (res.code !== 200) {
      const authenticationExpired = [50008, 50012, 50014].includes(res.code);
      if (
        !authenticationExpired &&
        safeGet(response.config, "silentError") !== true
      ) {
        Message({
          message: res.message || "Error",
          type: "error",
          duration: 5 * 1000
        });
      }

      // 50008: Illegal token; 50012: Other clients logged in; 50014: Token expired;
      if (authenticationExpired) {
        beginReauthentication();
      }
      return Promise.reject(new Error(res.message || "Error"));
    } else {
      return res;
    }
  },
  error => {
    const response = safeGet(error, "response");
    const status = safeGet(response, "status");
    const data = safeGet(response, "data");
    const backendMessageValue = safeGet(data, "message");
    const errorMessageValue = safeGet(error, "message");
    const backendMessage =
      typeof backendMessageValue === "string" &&
      backendMessageValue.trim().length > 0
        ? backendMessageValue
        : "";
    const errorMessage =
      typeof errorMessageValue === "string" &&
      errorMessageValue.trim().length > 0
        ? errorMessageValue
        : "";

    if (safeGet(safeGet(error, "config"), "silentError") !== true) {
      Message({
        message:
          status === 403
            ? backendMessage || "当前账号无此操作权限"
            : backendMessage || errorMessage || "网络错误",
        type: "error",
        duration: 5 * 1000
      });
    }
    return Promise.reject(error);
  }
);

export default service;
