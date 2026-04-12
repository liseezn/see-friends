# SEE~Friends 友情链接管理插件

[![GitHub Stars](https://img.shields.io/github/stars/liseezn/see-friends?style=flat-square)](https://github.com/liseezn/see-friends)
[![GitHub Forks](https://img.shields.io/github/forks/liseezn/see-friends?style=flat-square)](https://github.com/liseezn/see-friends)
[![GitHub Issues](https://img.shields.io/github/issues/liseezn/see-friends?style=flat-square)](https://github.com/liseezn/see-friends/issues)
[![GitHub License](https://img.shields.io/github/license/liseezn/see-friends?style=flat-square)](https://github.com/liseezn/see-friends/blob/main/LICENSE)
[![WordPress Version](https://img.shields.io/badge/WordPress-5.6+-0073aa?style=flat-square)](https://cn.wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-777bb4?style=flat-square)](https://www.php.net/)

专为 WordPress 打造的**智能化友情链接管理插件**，支持前端申请、后台审核、智能反链检测、定时监控、邮件提醒、自助修改、RSS展示，一键管理所有友链。

项目仓库：[https://github.com/liseezn/see-friends](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=)

---

## ✨ 插件简介
- 基于 WordPress 原生链接管理器开发，无冗余代码、轻量高效
- 支持**智能穿透式反链检测**，覆盖主页/导航/折叠菜单/友人帐/友链子页面/图片友链全场景
- 自带可视化后台配置面板，无需修改代码即可自定义所有功能
- 支持申请审核、状态同步、自动清理、批量检测、掉链提醒、自助修改全流程自动化
- 完美适配深色/浅色双主题，样式自适应不错乱，前端卡片布局美观响应式
- 完全开源，持续维护，欢迎提交 Issue & PR

---

## 🚀 核心功能
### 1. 智能反链检测（核心）
- 全场景覆盖：主页直链、导航栏、页脚、折叠菜单、友人帐、友链子页面、纯图片友链
- 后台**自定义反链关键词**，支持中英文多场景识别
- 自动检测 `/friend`/`/link`/`/links` 等常见友链路由
- 支持单条手动检测 + 批量全量检测 + 定时自动检测，结果实时留存
- 防拦截、防超时、异常自动跳过，稳定不崩溃

### 2. 可视化后台配置
- 独立配置面板，挂载在 WordPress 后台「链接」菜单下
- 分模块配置：基础设置/反链检测/邮件通知/前端显示/数据同步
- 一键开关所有功能，自定义参数无需修改代码

### 3. 友链申请与审核
- 前端申请表单短代码，支持用户自助提交友链申请
- 后台一键审核：通过/拒绝/检测反链，操作便捷
- 审核状态自动同步至原生链接管理器，上线/下架自动处理

### 4. 友链自助修改（新增）
- 前端修改表单短代码，用户通过「网站地址+邮箱」验证身份
- 支持修改网站名称、链接、图标、描述、RSS地址
- 修改提交后自动通知管理员审核，全程留痕

### 5. 数据同步与管理
- 原生链接 → 插件申请列表**一键同步**，统一管理存量友链
- 自动清理过期待审核/已拒绝申请，支持自定义过期天数
- 回收站防误触，所有危险操作增加二次确认弹窗

### 6. 自动化通知提醒
- 申请通过/拒绝/修改申请，自动发送邮件通知申请人
- 新申请/用户修改，自动发送邮件通知管理员
- 友链失效自动发邮件提醒，支持自定义重复提醒间隔

### 7. RSS 订阅展示（新增）
- 申请/编辑页支持填写网站 RSS 订阅地址
- 前端友链卡片自动展示 RSS 订阅图标，可后台开关
- 同步原生链接时自动兼容 RSS 字段

### 8. 前端展示
- 随机排序友链短代码，支持图标/描述/尺寸/RSS自定义
- 响应式卡片布局，完美适配手机/电脑端
- 原生兼容深色模式，样式无错乱、对比度正常

---
## 📋 环境要求
- WordPress 5.6 及以上版本
- PHP 7.4 及以上版本
- 服务器未禁用 `wp_remote_get` 函数（支持外部网络请求）

---
## 📦 安装步骤
### 方式一：GitHub 手动下载安装
1. 从仓库 [Releases 页面](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends%2Freleases&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=) 下载最新版 `see-friends.zip` 压缩包
2. 进入 WordPress 后台 → 插件 → 安装插件 → 上传插件，选择下载的 ZIP 包
3. 安装完成后，点击「启用插件」即可

### 方式二：单文件手动安装
1. 从仓库 [Releases 页面](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends%2Freleases&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=) 下载最新版 `see-friends.php` 单文件
2. 上传至网站 `/wp-content/plugins/` 目录
3. 后台启用插件

> 插件启用后会自动初始化配置、注册定时任务，无需额外手动配置

---
## ⚡ 快速使用
### 前端短代码
1. **友链申请表单**
```
[link_apply_form]
```
2. **友链自助修改表单**（新增）
```
[link_modify_form]
```
3. **随机友链列表**
```
[random_bookmarks]
```
支持自定义参数：

| 参数 | 说明 | 默认值 |
| :--- | :--- | :--- |
| `category` | 链接分类ID | 空（全部分类） |
| `limit` | 显示链接数量 | -1（全部显示） |
| `show_image` | 是否显示网站图标 | true |
| `show_description` | 是否显示网站描述 | true |
| `image_size` | 图标尺寸（单位：px） | 40 |
| `target` | 链接打开方式 | _blank |
| `show_rss` | 是否显示RSS订阅图标 | true |

示例：
```
[random_bookmarks limit="20" show_image="true" show_description="true" image_size="32" show_rss="true"]
```

### 后台操作
1. **链接 → 链接申请**：审核申请、处理修改请求、单条反链检测
2. **链接 → 友链设置**：全局配置、批量检测、数据同步、关键词自定义
3. **链接 → 所有链接**：管理已上线友链、分类管理

---

## ⚙️ 配置面板说明
### 基础设置
- 自动清理过期申请开关
- 申请过期天数（默认30天）

### 反链检测设置（新增扩展）
- 定时自动检测开关
- 检测频率：每天/每天两次/每周
- 失效友链提醒邮箱
- 重复提醒间隔设置
- **自定义反链检测关键词**
- **自动检测常见友链路径开关**
- **图片友链检测开关**

### 邮件通知设置（新增扩展）
- 审核通过/拒绝邮件通知（申请人）
- 新申请邮件通知（管理员）
- **用户修改友链邮件通知（管理员）**

### 前端显示设置（新增扩展）
- 前端申请表单开关
- **前端自助修改表单开关**
- 友链列表默认显示配置
- 图标默认尺寸&范围设置
- **RSS订阅地址显示开关**

### 数据同步
- 一键同步原生链接至插件申请列表
- 自动去重，不重复创建数据

---

## 📝 更新日志

### v3.1.1（最新版）
#### 🎉 新增功能
1. 新增**前端友链自助修改功能**，短代码 `[link_modify_form]`
2. 新增**RSS订阅地址支持**，申请/编辑可填写，前端展示RSS图标
3. 新增**反链关键词自定义设置**，无需改代码直接配置
4. 新增**自动检测常见友链路径**（/friend/link/links等）
5. 新增**图片友链检测开关**，支持识别图片alt/src/title内反链
6. 新增**友链修改邮件通知**，管理员实时接收修改申请

#### 🐛 问题修复
1. **彻底修复深色模式样式错乱**：重构CSS变量体系，完美适配系统/主题深色模式
2. 修复纯图片友链无法被检测到的漏检问题
3. 修复友链在独立子页面无法识别的问题
4. 优化表单样式，深浅模式自动适配无亮块

#### ⚡ 体验优化
1. 优化反链检测逻辑，最多检测10个候选页面，效率更高
2. 优化检测请求间隔，避免被目标站点屏蔽
3. 完善表单验证，身份验证更安全
4. 同步逻辑优化，兼容RSS字段同步

### v3.1.0
#### 🎉 新增功能
##### 新增「原生链接一键同步到插件」功能
后台新增「数据同步」选项卡，一键把「链接」菜单里的所有友链同步到插件申请列表
同步后的链接状态为「已通过」，自动关联原生链接，支持反链检测
自动去重，已存在的链接不会重复创建，可放心重复点击

#### 🐛 问题修复
##### 修复「一键检测所有已上线友链」致命错误
增加set_time_limit(0)取消 PHP 执行时间限制，避免大量链接检测超时崩溃
每个链接检测增加异常捕获，单个链接请求失败不会中断整个检测流程
优化请求超时时间、增加间隔，避免被目标服务器拦截
增加内存清理，避免大量链接检测时内存溢出
增加二次确认弹窗，避免误触触发检测

#### 解决按钮误触问题
所有危险操作（通过、拒绝、移到回收站）都增加二次确认弹窗，点击后必须确认才会执行
按钮样式优化：通过按钮绿色加粗、拒绝按钮红色加粗，和普通按钮明显区分
按钮增加间距，避免挤在一起误点
操作按钮和编辑、回收站按钮分组，逻辑更清晰

### v3.0.0
#### 🎉 新增功能
1. 新增**可视化后台配置面板**，全功能可视化设置，无需修改代码
2. 新增**智能穿透式反链检测**，支持友人帐/折叠菜单/子页面/多语言关键词全场景适配
3. 新增**原生链接一键同步**功能，统一管理存量友链
4. 新增**定时自动批量检测**，支持每日/每日两次/每周自动巡检
5. 新增**友链失效邮件提醒**，自定义重复提醒间隔，防掉链
6. 新增**操作二次确认**，解决回收站/审核按钮误触问题
7. 新增完整的权限校验与安全防护机制

#### 🐛 问题修复
1. 修复回收站操作「安全验证失败，请刷新重试」的核心问题
2. 修复批量检测导致网站崩溃/500错误的问题
3. 修复审核通过后不同步至链接管理器的问题
4. 修复短代码不解析、直接显示原文的问题
5. 修复反链检测漏检、误判的问题
6. 修复深色主题样式错乱、不兼容的问题

#### ⚡ 性能优化
1. 检测逻辑增加超时保护、异常捕获、内存清理，避免大量链接检测时超时
2. 优化请求频率，增加间隔机制，避免被目标服务器拦截
3. 精简冗余代码，轻量高效，不影响网站加载速度
4. 申请列表UI优化，状态标识更清晰，操作更便捷

完整更新日志：[GitHub Releases](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends%2Freleases&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=)

---

## ❓ 常见问题
### 1. 批量检测报错/网站崩溃？
v3.0.0 已彻底修复该问题，增加了超时保护、异常捕获、内存清理机制，可放心使用。如仍有问题，可前往 [GitHub Issues](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends%2Fissues&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=) 提交反馈。

### 2. 反链检测不到/图片友链不识别？
v3.1.1 已支持图片友链检测与常见友链路径检测，可在后台开启对应开关并自定义关键词；如遇特殊站点检测不到，可提交 Issue 反馈。

### 3. 深色模式下友链框发白/文字看不清？
v3.1.1 已彻底重构深色模式适配逻辑，使用CSS变量自动切换，无样式错乱问题。

### 4. 如何让用户自己修改友链信息？
新建页面插入短代码 `[link_modify_form]`，用户通过网址+邮箱验证后即可提交修改申请。

### 5. 如何同步之前的存量友链？
进入后台「链接 → 友链设置 → 数据同步」，点击「一键同步所有链接」即可，自动去重，不会重复创建数据。

### 6. 申请表单不显示？
检查「前端显示设置」是否开启了申请表单开关，页面内使用短代码 `[link_apply_form]` 即可。

---

## 🤝 贡献指南
欢迎参与本项目的开发与维护！
1. Fork 本仓库：[https://github.com/liseezn/see-friends](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=)
2. 创建你的功能分支：`git checkout -b feature/AmazingFeature`
3. 提交你的修改：`git commit -m 'Add some AmazingFeature'`
4. 推送到分支：`git push origin feature/AmazingFeature`
5. 提交 Pull Request

---

## 📞 问题反馈
- 功能建议、Bug 反馈：[GitHub Issues](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends%2Fissues&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=)
- 作者博客：[https://liseezn.top](sslocal://flow/file_open?url=https%3A%2F%2Fliseezn.top&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=)

---

## 📄 开源协议
本项目采用 **GNU General Public License v3.0** 协议开源，相关权利与限制详见 [LICENSE](sslocal://flow/file_open?url=https%3A%2F%2Fgithub.com%2Fliseezn%2Fsee-friends%2Fblob%2Fmain%2FLICENSE&flow_extra=eyJsaW5rX3R5cGUiOiJjb2RlX2ludGVycHJldGVyIn0=)。
