# Modular Code Studio

Modular Code Studio 为 Gutenberg 提供 **Modular Code Module** 动态区块。每个模块独立保存 HTML、CSS 和 JavaScript，同一页面发布后分别加载一份有内容哈希的 CSS 和 JS 文件。插件独立运行，可以和管理全站代码的 WPCodeBox 2 一起使用。

## 1.1.0 的编辑器范围

默认在所有 WordPress Gutenberg 区块编辑器启用插入器，包括文章、页面、使用 Gutenberg 的自定义文章类型、Blocksy Content Blocks、可复用区块、站点编辑器中的模板/模板部件，以及区块小工具。升级已有安装后同样默认启用该范围。需要缩小范围时，在设置中将 **Block editor availability** 改为 **Selected post types**。

文章编辑器继续检查该文章的编辑权限及 `unfiltered_html`。站点编辑器和小工具编辑器使用 `edit_theme_options` 加 `unfiltered_html`。WordPress 自身或其他插件设置的区块白名单、容器允许的子区块和锁定状态仍由相应编辑器管理。经典编辑器及 Elementor 等页面构建器使用各自的编辑界面。

共享模板、Content Blocks 和区块小工具保存时生成其各自的外部 Bundle。资源在模块真正被前台渲染时排队，晚于 head 渲染的样式通过 WordPress 样式加载器输出。普通页面维持页面级 Bundle，共享来源在用到它的页面额外加载自己的 Bundle。模块实例标识包含共享来源 ID，以避免与正文模块混用。小工具资源使用 `page-widget-N/` 目录。

共享内容没有独立的 canonical 页面时，模块内预览入口会隐藏。请使用站点或 Blocksy 的前台预览，在它实际显示的页面核验效果。共享内容的相对 CSS URL 默认以网站根 URL 为基准，也可使用 **CSS resource base URL** 指定资源目录。更新在多个页面显示的共享模板后，还需要通过缓存插件清理这些宿主页面的旧 HTML 缓存。

## 安装

需要 WordPress 6.7+、PHP 8.1+（含 Composer 依赖所需的 mbstring 扩展），以及可写的 WordPress uploads 目录。建议在有 HTTPS 的暂存站先验收主题和缓存配置。

1. 打开 **插件 → 安装插件 → 上传插件**，选择 `modular-code-studio.zip`，然后激活。
2. 在 **设置 → Modular Code Studio** 检查支持的文章类型、uploads 路径和编辑器默认值。
3. 确认编辑账号拥有目标页面的编辑权限和 WordPress 的 `unfiltered_html` 能力。Multisite 由核心决定该能力，通常只有超级管理员具备。
4. 如果服务器使用 Nginx，或忽略 uploads 内的服务器配置，请在上线前应用下方的资源目录规则。

安装 ZIP 已包含 JavaScript、样式和 PHP 运行依赖，可直接激活。

## 创建和编辑模块

在区块插入器搜索 **Modular Code Module**。填写模块名，打开 HTML、CSS 或 JavaScript 标签。**Find / replace** 打开搜索替换面板，**Format** 按当前语言运行本地 Prettier，**Full screen** 扩展编辑区。格式化失败时保留原缓冲区。CodeMirror 提供独立标签光标、选择和历史、语法高亮、行号、折叠、补全、缩进和括号匹配。JavaScript 错误显示在编辑器和构建诊断中。

区块设置可以单独开关各语言、简码和预览。模块默认输出 HTML 本身。启用 **Wrap module HTML** 会增加带 `data-mcs-instance` 的容器。HTML 可以写 `[contact-form-7 id="123"]`、WooCommerce 简码或已注册的其他简码。简码由 WordPress 在当前页面上下文执行，输出及其 enqueue 调用沿用 WordPress 流程。第三方插件须已激活且已配置相应表单、产品或其他资源。

JavaScript 标签支持经典脚本中的函数体，可直接使用 `window`、`document`、`root`、`instanceId`。请在 **JavaScript dependencies** 填写已注册的脚本 handle，用空格或逗号分隔。主题或插件已注册并排队的同一 handle 由 WordPress 去重。直接写在主题模板或 WPCodeBox HTML 中的 script 标签需要资源提供者先注册稳定的 WordPress handle，才能参与依赖排序。

无外包容器且未设置选择器时，`root` 为 `document`。设置 **Custom root selector** 后，`root` 是模块自身内容中的首个匹配元素，找不到时为 `null`。无外包容器的自定义选择器通过一对不可见的 `template` 元素界定模块范围。作用域独立，初始化按页面顺序执行，每个实例每个文档执行一次。异步操作的完成顺序由用户代码决定。静态 `import/export` 和顶层 `await` 不属于支持的经典脚本输入。

## 预览和 WPCodeBox 2

1. 打开模块的 **Preview**。可选择 **Focus module**（保持完整前台 DOM，并滚动到模块）或 **Full page**。
2. 使用 Desktop 1440 px、Tablet 768 px、Mobile 390 px。每个设备的 iframe 具有实际 CSS 视口宽度，小画布等比缩放显示。
3. 自动更新经过防抖，也可切换为手动刷新。侧栏 **Preview page** 用于预览已保存页面。预览内的其他模块使用已保存内容，尚未保存的新模块会临时追加到内容末尾。
4. 在侧栏 **View diagnostics** 查看已观察到的资源 URL、WordPress handle、未知脚本依赖、构建和运行错误。

预览由经授权的 REST 调用创建 120 秒的一次性会话，用 HttpOnly、SameSite=Strict Cookie 和 POST 表单请求前台页面。已发布页面的地址保留 canonical 路径及原有查询字符串，草稿使用 WordPress 的标准预览地址。加载原页面的主查询、主题模板、`wp_head`、`wp_footer` 和正常 enqueue 流程。WPCodeBox 继续运行其配置的前台和页面条件，包括采用 URL 的条件。插件不调用 WPCodeBox 私有接口。主题、子主题、WPCodeBox CSS/JS 和简码排队的资源均通过原前台流程进入预览。模块预览 CSS 在 `wp_head` 的末尾输出。可在设置中追加绝对 HTTP(S) CSS 或 JS URL，应先通过诊断确认有缺失。

预览 iframe 允许脚本且使用不透明来源。编辑器、REST 和前台需要同源。表单提交、同源存储、需要同源凭据的 AJAX 和来源敏感的资源可能受 sandbox 或站点 CSP 限制。请在真实前台完成表单提交和 WooCommerce 交互验收。反代或 CDN 应将该 POST 请求传给源站，并遵循 `private, no-store`。安全插件或主题若阻止 iframe、内联预览代码或跳过常规内容 Hook，可能需要站点级适配。WPCodeBox Functionality Plugin 的状态没有可靠的公开检测方式，实际行为请按测试报告中的步骤核验。

## 页面资源

默认在保存文章后递归遍历其内容和已发布的 Synced Patterns。引用的 pattern 改动时会重建已索引的引用页面。同一 pattern 放置多次会保留每次放置的实例。资源按文章 ID 保存于 `uploads/modular-code-studio/page-N/`。CSS 与 JS 独立按内容哈希命名，原子发布文件和 manifest，未使用的旧资源默认从退役时起保留 30 天。构建失败后侧栏会显示错误；需要修复权限或上传目录问题再重新构建。页面已删除全部模块时会移除 manifest。

CSS 用 Wikimedia CSS 语法解析器改写资源路径，普通规则按模块顺序输出，而且保留标准 CSS 嵌套、媒体查询、自定义属性、动画与字体。相对 `url()` 和 `@import` 默认按页面 URL 解析，也可配置全站 **CSS resource base URL**。绝对、协议相对、片段和 data URL 保持原值。CSS 语法要求 `@import` 和 `@namespace` 位于普通规则前，所以打包时将它们提前。涉及跨模块的命名空间前缀或 @layer 导入顺序时，建议集中定义。SCSS 请通过 WPCodeBox 等工具编译为 CSS 后使用。

前台按当前单篇文章 ID 加载页面资源。已保存的共享模板和小工具通过独立 Bundle 加载，资源随实际模块渲染排队。由第三方临时拼接、没有已保存来源的代码需要该第三方管理其资源。手动 **Rebuild CSS / JS / all** 使用已保存内容。源数据变化时重新核对两种产物，只写变化或缺失的文件。全站重建依赖 WP-Cron 批处理。若关闭自动构建，保存代码后要手动重建其公开资源。

发布且无密码的受支持文章和共享模板，以及保存的区块小工具，会生成公开 CSS 和 JS。公开过的旧资源可能在保留期内继续可取用。请将前台可公开的代码写入这些来源。具备 canonical 前台 URL 的草稿和私密文章可通过认证预览运行临时代码。签名使用当前站点的 WordPress salt。跨站导入、切换站点 ID 或更换 salt 后，请由拥有代码能力的账号重新保存来源内容，再重建和清理页面缓存。

### Apache、LiteSpeed、Nginx 与 IIS

插件为 Apache/LiteSpeed 生成 `.htaccess`，为 IIS 生成 `web.config`。确认站点认可这些目录级配置，禁止目录列表，并限制脚本执行。Nginx 可在站点的通用 PHP location 前插入这类规则，将路径替换为实际 uploads 路径并保留既有静态缓存设置。

```nginx
location ~ ^/wp-content/uploads/(?:sites/[0-9]+/)?modular-code-studio/page-(?:widget-)?[1-9][0-9]*\/page-(?:widget-)?[1-9][0-9]*\.[0-9a-f]+\.(?:css|js)$ {
    autoindex off;
    try_files $uri =404;
}
location ~ ^/wp-content/uploads/(?:sites/[0-9]+/)?modular-code-studio(?:/|$) {
    return 404;
}
```

确认插件静态资产可取用，并验证对目录、`manifest.json` 和 PHP 路径的请求被拒绝。如果使用自定义 uploads 路径、独立 CDN 或对象存储，需在对应服务器应用等效规则。多机部署需要所有写入者共享支持锁和原子重命名的文件系统。签名及预览 token 也依赖所有节点的 WordPress 配置和共享会话一致。

## LiteSpeed Cache

CSS 用 `wp_enqueue_style()`，JS 用 `wp_enqueue_script()` 及其依赖。LiteSpeed 对正式页面使用常规优化流程。插件在检测到 `litespeed_purge_url` 监听器时只请求清理当前 canonical URL。侧栏显示请求时间，实际缓存命中或清理需要检查公开请求的 `X-LiteSpeed-Cache`。预览使用公开 no-cache 和禁用当前请求优化的机制。

- **UCSS**：打开 Gutenberg 侧栏的 **UCSS selector allowlist**，复制交互状态所需的实际选择器。进入 **LiteSpeed Cache → Page Optimization → Tuning - CSS → UCSS Allowlist**，每行粘贴一条。请验证菜单展开、Modal、标签页、slider 和移动导航。
- **JS 冲突**：使用该页的排除选项。LiteSpeed 的 Deferred 和 Delayed 共用匹配列表，因此这两项作用于该站实际启用的模式。Guest Mode 有独立的 JS 延迟排除列表。关闭 **Allow LiteSpeed JS optimization** 时追加当前 bundle 的常规 JS 排除和两类执行顺序排除。Guest Optimization 仍可能对脚本做其它处理，取决于 LiteSpeed 版本与配置。
- **手动配置**：将页面侧栏显示的 bundle 路径用于 JS Excludes 或 JS Deferred/Delayed Excludes。CDN 或缓存层如果保留历史 HTML，应将 CSS/JS 保留期设置得覆盖该缓存周期。

参考 [LiteSpeed 公开 API](https://docs.litespeedtech.com/lscache/lscwp/api/)、[LiteSpeed 页面优化配置](https://docs.litespeedtech.com/lscache/lscwp/pageopt/) 和 [WPCodeBox 前台执行规则](https://docs.wpcodebox.com/how_to_run_a_wordpress_code_snippet)。

## Blocksy

启用 Blocksy 或其子主题并使用常规页面模板即可沿用其前台资源。模块 CSS 默认在常规 enqueue 的晚期加入，优先级可设置。直接在更晚的 Hook 输出的样式仍按浏览器实际出现顺序层叠。需要确认样式顺序时，请查看预览诊断和浏览器 DevTools。真实 Blocksy Header Builder、Footer Builder 和 WooCommerce 联合验收见 [测试报告](TEST-REPORT.md)。

## 从源码构建

```text
composer install --no-dev --prefer-dist
npm ci
npm run build
npm test
npm run package
```

打包脚本需要 PowerShell 7。带源码的工作区包含测试和打包脚本。发布 ZIP 包含可执行生产文件、依赖、源码、锁文件和许可证。构建工具的 Node 版本应满足锁定的 `@wordpress/scripts` 要求。
