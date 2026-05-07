import js from "@eslint/js";
import vue from "eslint-plugin-vue";
import globals from "globals";

export default [
  js.configs.recommended,
  ...vue.configs["flat/recommended"],
  {
    files: ["**/*.{js,vue}"] ,
    languageOptions: {
      ecmaVersion: "latest",
      sourceType: "module",
      globals: {
        ...globals.browser
      }
    }
  }
];
