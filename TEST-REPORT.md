# 测试报告

## 1.1.0 编辑器兼容回归（2026-11-19）

- 已在一次性 WordPress 6.9.4 中验证非公开 `ct_content_block` 文章类型的原生 Gutenberg 插入器、代码编辑器、正常保存和重新打开。通过宿主页面实际渲染该来源时，HTML、CSS、JS 和简码正常，资源只加载一次，未使用该来源的页面没有加载对应资源。
- 已验证旧设置只有 page/post 时自动支持 Content Blocks，同时支持另一个未手动加入列表的非公开 Gutenberg 自定义文章类型。
- 已在原生 Widgets 编辑器中验证插入器、受控小工具区域中的模块插入和本地代码编辑器。已在 Twenty Twenty-Five 的原生站点编辑器中验证模板插入器、模块插入及 iframe 画布内的代码编辑器。
- PHP 集成测试覆盖站点编辑器/小工具编辑器的无文章上下文权限，模板部件保存与直接区块渲染，小工具来源签名、外部 Bundle 及删除清理，以及低权限小工具代码拒绝。
- 已重新通过原有 CSS/JS 编译器、独立哈希、共享区块、草稿资源和清理范围测试。编辑器侧栏的 `wp-editor` 依赖独立编译并只在文章编辑器加载。
- Blocksy Pro Content Blocks 是付费组件，本机未安装其实际代码。这次使用与官方文档一致的文章类型名称和非公开 Gutenberg 类型进行回归，并通过标准 `the_content` 在宿主页面渲染。真实 Pro 版的所有显示条件、Hook 位置和缓存配置仍需在目标站验收。[Blocksy 官方 Content Blocks 文档](https://creativethemes.com/blocksy/docs/modules/content-blocks/)

## 测试环境

工作区没有可访问的部署站点或可用的 WPCodeBox 2 安装包。以下结果来自一次性本地 WordPress 6.9.4、PHP 8.3.36、SQLite、Microsoft Edge 和 PHP 开发服务器。测试站点采用 HTTP，uploads 可写，未启用生产外部对象缓存。两轮成功的浏览器测试分别使用 Twenty Twenty-Five 和 Blocksy 2.1.60 的测试子主题。第二轮启用了 Contact Form 7 6.1.5 和 LiteSpeed Cache 7.9.3。目标站 WordPress/PHP、主题、插件、服务器、缓存和 HTTPS 配置均待现场核对。

ZIP 通过 WordPress 管理后台实际上传与激活。PHP 语法检查、`npm run build`、`npm test` 中的三组编译器测试和 ZIP 结构验证通过。Webpack 对首次打开编辑器及首次格式化时异步加载的两份依赖报告体积提示。前台页面使用生成的页面资源。

## 验收结果

| 项目 | 实际验证结果 |
| --- | --- |
| A · 基础模块 | 五个嵌套模块的 HTML、CSS、JS 在 WordPress 页面中运行。 |
| B · 专业编辑器 | Edge 中验证语法高亮、标签切换、CSS 格式化、搜索面板、全屏、复制生成新实例 ID、Gutenberg 正常保存和重新打开后保留代码。长文档性能、全部补全行为及快捷键组合尚待覆盖。 |
| C · Shortcode | WordPress 注册的测试简码在前台和 iframe 执行并保留页面 ID。关闭简码的区块在核心再次处理内容时保持禁用。Contact Form 7 6.1.5 的实际表单及已注册 JS 在前台与预览加载。表单提交和 WooCommerce 未测。 |
| D · CSS Bundle | 每个测试页面只有一份 MCS CSS，规则顺序、CSS AST 路径重写、媒体查询、标准嵌套和保留历史资产均有测试。 |
| E · JS Bundle | 每个测试页面只有一份 MCS JS。前台确认五个模块按序运行，jQuery 只加载一次；Node 执行实际生成的 Bundle，验证实例防重、作用域及错误隔离。 |
| F · 独立 Hash | 单独修改 CSS 或 JS 时，另一个资源的哈希一致。 |
| G · 模块删除 | 删除第五个模块，其规则和脚本从新 Bundle 中移除。删除最后一个模块后移除 manifest。 |
| H · 页面隔离 | 两个发布页各自使用对应页面 ID 的 Bundle。 |
| I · WPCodeBox 2 CSS | 本地前台样式夹具验证全局规则、按页面 URL 的规则、未保存 CSS 覆盖和未匹配页面不加载。真实 WPCodeBox 2 Snippet、Condition Builder 及 Functionality Plugin 尚未验收。 |
| J · WPCodeBox 2 JS | WordPress 注册、排队和依赖排序已测。真实 WPCodeBox 2 Global JS、外部脚本、SCSS 和 Functionality Plugin 尚未验收。 |
| K · 主题和视口 | Twenty Twenty-Five 和 Blocksy 2.1.60 的页面模板及条件样式通过。Blocksy Header/Footer 与实际激活的测试子主题 CSS 在前台和预览均加载。验证 1440、768、390 px 实际视口及 390 px 下的媒体查询。 |
| L · LiteSpeed | 在已启用的 LiteSpeed Cache 7.9.3 上检查版本、状态和页面级排除选项的保存与隔离。对照该版本源码确认公开 Hook。PHP 开发服务器无法验证 LiteSpeed 服务端缓存和优化链。 |
| M · 更新与缓存 | 新哈希、文件 URL 和旧文件保留已测。真实 LiteSpeed 缓存是否命中、单 URL 请求是否生效和 CDN 回源仍待验证。 |
| N · 安全 | 实测未登录 POST 和作者账号 REST 访问被拒绝，低权限作者写入的未授权代码不会执行。验证跨页面不接受令牌、使用后失效和等待 120 秒自然过期。响应含 no-store 和 sandbox，iframe 无法读取父文档，资源路径校验与定向清理通过。HTTPS、Multisite、真实服务器 PHP 禁用尚待验证。 |
| 补充 · Synced Patterns | 重复放入同一个已发布 Pattern 会得到两个实例。修改 Pattern CSS 后引用页面重新构建，JS 哈希保持。草稿无公开 Bundle，认证草稿预览可插入未保存模块。过期清理保留活跃文件和无关文件。 |

## 需要站点环境的验收

1. **WPCodeBox 2**。安装持有许可证的实际版本。分别发布全局样式、按页面 URL 精确匹配的样式、全局 JS 和页面脚本。检查前台及编辑器预览的生效条件、覆盖顺序、脚本执行和重复加载。切换到 Functionality Plugin 模式后执行同样的检查。 [WPCodeBox Condition Builder](https://docs.wpcodebox.com/condition_builder) · [Functionality Plugin](https://docs.wpcodebox.com/functionality_plugin)
2. **LiteSpeed / CDN**。在对应服务器分别测试 CSS Minify、Combine、Async、CCSS、UCSS、UCSS Inline、CSS Excludes、JS Minify、Combine、Defer、Delay、排除项、Guest Mode、Guest Optimization、HTML Minify、Lazy Load、Responsive Placeholder、对象缓存、浏览器缓存和 CDN。将公开页请求预热为 HIT，再更新单页资源，验证只清理该页面，且预览响应无法污染公开页。 [LiteSpeed API](https://docs.litespeedtech.com/lscache/lscwp/api/) · [Page Optimization](https://docs.litespeedtech.com/lscache/lscwp/pageopt/)
3. **生产环境与业务配置**。检查真实主题的自定义 Header/Footer、WooCommerce 短代码和购买流程、Contact Form 7 提交、并发保存、多节点共享目录、PHP 执行和目录浏览禁用。用真实账号与权限配置检查 Multisite 和 HTTPS。

## 已知限制

- 为保持编辑器隔离，预览 iframe 使用不透明来源。测试环境观察到依赖 CORS 的字体和 WordPress Navigation 的 ES 模块加载受限。表单提交和需要同源认证的 AJAX 也受 sandbox 策略限制。
- 预览继承整页的前台资源。可添加手工 CSS/JS URL。没有基于 WPCodeBox 私有 Hook 的按来源禁用开关或 Functionality Plugin 状态猜测。
- CSS 导入和命名空间声明提前输出。复杂跨模块层顺序应集中管理。JavaScript 支持经典函数体，SCSS 需在其他工具中编译。
- 普通页面使用页面 Bundle，共享模板和区块小工具使用按来源保存的独立 Bundle。Preview 可以运行具备前台地址的草稿临时代码。没有 canonical 页面地址的共享内容需要在实际宿主页面预览。第三方动态拼接且未保存的代码来源需要自行管理资源。
- Nginx、独立静态 CDN 和忽略目录配置的服务器需要应用等效的目录访问规则。PHP 开发服务器本身不会处理 `.htaccess`。

## 复现

编译器测试可使用 `npm test`。浏览器和 WordPress 集成测试的源文件在工作区的 `tests/`。在一次性本机 WordPress 中放置 `tests/fixture.php` 作为 mu-plugin，使用脚本中指定的本机测试账号。设置 `MCS_TEST_ORIGIN`、`MCS_PLAYWRIGHT` 和可选的 `MCS_BROWSER`，运行 `node tests/browser.cjs`。安装 ZIP 后可运行 `node tests/security.cjs`，并设置 `MCS_WP_LOAD` 运行 `php tests/wordpress.php`。使用带 Zip 扩展的 PHP 运行 `php tests/archive.php` 可检查归档结构、解压和 CRC。测试脚本不随生产 ZIP 安装。

1.1.0 的专项回归还需将 `tests/content-blocks-fixture.php` 放入本机测试站点的 mu-plugins，执行 `php tests/content-blocks.php`、`node tests/content-blocks-browser.cjs` 和 `node tests/editor-contexts-browser.cjs`。浏览器脚本使用工作区中的一次性测试站点，并临时切换本地主题后恢复。
