# Changelog

## 1.1.0

- 默认支持所有 Gutenberg 编辑器上下文，包括自定义文章类型、Blocksy Content Blocks、站点模板和区块小工具。
- 扩展已有设置的编辑器范围，保留逐文章权限和无文章上下文的主题编辑权限检查。
- 为已保存的共享模板和区块小工具增加签名验证、独立资源 Bundle 和按实际渲染排队。
- 将文章资源侧栏独立编译，避免在小工具编辑器引入 `wp-editor` 依赖。
- 共享内容使用来源标识区分模块实例，并在缺少 canonical 前台 URL 时指向宿主页面预览流程。

## 1.0.0

- 增加 Gutenberg 动态代码区块、独立 HTML/CSS/JavaScript 缓冲区和本地 CodeMirror/Prettier。
- 增加认证前台预览、响应式视口、简码执行和资源诊断。
- 增加页面 CSS/JS 构建、独立哈希、Synced Patterns、资源状态面板和历史文件清理。
- 使用 WordPress 标准资源队列和 LiteSpeed 公开的页面排除及 URL 清理 Hook。
