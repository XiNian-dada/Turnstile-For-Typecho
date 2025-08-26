# Typecho Turnstile 插件

基于 [NKXingXh](https://github.com/nkxingxh/Typecho-Turnstile) 的 Turnstile 插件修改而来，为 Typecho 博客系统提供 **Cloudflare Turnstile 人机验证功能**。

---

## 原项目信息

- **原项目地址**：[https://github.com/nkxingxh/Typecho-Turnstile](https://github.com/nkxingxh/Typecho-Turnstile)  
- **原作者**：NKXingXh  
- **原版本**：1.3.1  

---

## 修改内容

### 🔧 修复 jQuery 相关问题
- 移除了 jQuery 相关代码，解决了在 jQuery 环境下可能导致的验证码无法加载的问题  
- 优化了脚本加载逻辑，减少外部依赖  

### ⚠️ 优化错误提示
- 改进了验证失败时的错误提示信息  
- 添加了更详细的调试信息输出  

### 💡 增强稳定性
- 添加了验证令牌存储机制，防止用户交互导致验证状态丢失  
- 增加了验证状态持久化功能  

### 🧹 代码优化
- 优化了 JavaScript 代码结构  
- 改进了事件处理逻辑  

---

## 功能特点

- 支持在评论和登录页面添加 **Turnstile 验证**  
- 支持 **亮色、暗色和自动主题模式**  
- 支持 **严格模式**（验证提交 IP 与验证 IP 是否一致）  
- 支持 **PJAX 页面加载**  
- 可选择使用 **cURL** 或 **file_get_contents** 进行验证  
- 提供 **救援模式**，可在验证出现问题时临时关闭验证  

---

## 安装方法

1. 下载本插件，将文件夹重命名为 `Turnstile` 并上传到 Typecho 的 `/usr/plugins/` 目录  
2. 在 Typecho 后台 **激活插件**  
3. 前往插件设置页面，配置您的 **Cloudflare Turnstile Site Key 和 Secret Key**  
4. 选择需要启用验证的功能（评论、登录）  

---

## 使用方法

### 评论验证
启用评论验证后，需要在主题的评论表单中添加以下代码：

```php
<?php Turnstile_Plugin::output(); ?>
