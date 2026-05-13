import js from "@eslint/js";
import vue from "eslint-plugin-vue";

const cypressGlobals = {
  cy: "readonly",
  Cypress: "readonly",
  describe: "readonly",
  context: "readonly",
  it: "readonly",
  specify: "readonly",
  before: "readonly",
  beforeEach: "readonly",
  after: "readonly",
  afterEach: "readonly",
  expect: "readonly",
};

export default [
  js.configs.recommended,
  ...vue.configs["flat/recommended"],
  {
    files: ["**/*.{js,vue}"],
    rules: {
      "no-unused-vars": ["error", { caughtErrors: "none" }],
    },
  },
  {
    files: ["src/pages/**/*.vue"],
    rules: {
      "vue/multi-word-component-names": "off",
    },
  },
  {
    files: ["cypress/**/*.cy.js", "cypress/**/*.js"],
    languageOptions: {
      globals: cypressGlobals,
    },
  },
  {
    ignores: ["dist/**", "node_modules/**"]
  }
];
