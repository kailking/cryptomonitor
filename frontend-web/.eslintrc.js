module.exports = {
  root: true,
  parserOptions: {
    parser: "babel-eslint",
    sourceType: "module",
  },
  env: {
    browser: true,
    node: true,
    es6: true,
  },
  extends: ["plugin:vue/recommended", "eslint:recommended"],
  rules: {
    "vue/max-attributes-per-line": "off",
    "vue/singleline-html-element-content-newline": "off",
    "vue/multiline-html-element-content-newline": "off",
    "vue/html-closing-bracket-newline": "off",
    "vue/html-indent": "off",
    "vue/html-self-closing": "off",
    "vue/name-property-casing": ["error", "PascalCase"],
    "vue/no-v-html": "off",
    "no-console": "off",
    "no-control-regex": "off",
    "no-unused-vars": ["error", { vars: "all", args: "none" }],
    "no-useless-escape": "off",
    "no-debugger": process.env.NODE_ENV === "production" ? "error" : "off",
  },
};
