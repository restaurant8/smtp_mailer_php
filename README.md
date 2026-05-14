# SMTP Mailer PHP

这是 `SMTP Mailer` 的 PHP + MySQL 版本，适合 aaPanel / 宝塔环境部署。

## 技术栈

- PHP 8.2
- MySQL 5.7 / MariaDB
- Nginx
- PHPMailer
- Supervisor 常驻发信进程

## 功能

- 批量导入联系人，支持 CSV 或每行一个邮箱。
- 自定义邮件模板，支持 HTML 和纯文本正文。
- 创建发送活动并写入 MySQL 队列。
- Supervisor worker 从队列逐封发送。
- 每个活动可设置每封发送间隔秒数。
- 发送日志记录每封邮件状态、失败原因、入队时间、发送时间。
- 网页控制台实时监控队列、发送进度、worker 心跳和最近日志。
- `/api/status` 提供 JSON 状态接口，前端每 2 秒轮询刷新。
- 支持退订链接和退订黑名单。

## aaPanel 部署

### 1. 上传项目

上传整个 `smtp_mailer_php` 目录到服务器，例如：

```bash
/www/wwwroot/smtp_mailer_php
```

网站运行目录建议设置为：

```bash
/www/wwwroot/smtp_mailer_php/public
```

### 2. 安装 Composer 依赖

SSH 进入服务器：

```bash
cd /www/wwwroot/smtp_mailer_php
composer install --no-dev
```

如果没有 Composer，可以在 aaPanel 软件商店或终端安装 Composer。

### 3. 创建 MySQL 数据库

在 aaPanel 创建数据库，例如：

```text
数据库名：smtp_mailer
用户名：smtp_mailer
密码：自己生成强密码
```

然后导入：

```bash
mysql -u smtp_mailer -p smtp_mailer < database/schema.sql
```

也可以在 aaPanel 数据库管理里导入 `database/schema.sql`。

如果你已经导入过旧版表结构，需要额外执行一次：

```sql
create table if not exists worker_status (
  name varchar(100) primary key,
  pid int unsigned null,
  current_queue_id bigint unsigned null,
  current_email varchar(255) null,
  state varchar(50) not null default 'idle',
  heartbeat_at datetime not null,
  updated_at datetime not null default current_timestamp on update current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;
```

### 4. 配置项目

```bash
cd /www/wwwroot/smtp_mailer_php
cp config.example.php config.php
nano config.php
```

重点修改：

```php
'app_url' => 'https://你的域名',
'app_secret' => '改成很长的随机字符串',

'db' => [
    'database' => 'smtp_mailer',
    'username' => 'smtp_mailer',
    'password' => '你的数据库密码',
],

'smtp' => [
    'host' => 'smtp.example.com',
    'port' => 587,
    'username' => '你的SMTP账号',
    'password' => '你的SMTP授权码',
    'encryption' => 'tls',
    'from_email' => '你的发件邮箱',
    'from_name' => '你的发件名称',
]
```

465 端口通常这样：

```php
'port' => 465,
'encryption' => 'ssl',
```

### 5. Nginx 伪静态

aaPanel 网站设置里添加伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 6. 配置 Supervisor

aaPanel 打开 Supervisor，添加守护进程：

```text
名称：smtp-mailer-worker
运行目录：/www/wwwroot/smtp_mailer_php
启动命令：php /www/wwwroot/smtp_mailer_php/bin/send-worker.php
进程数：1
自动启动：开启
```

worker 日志：

```bash
/www/wwwroot/smtp_mailer_php/storage/logs/worker.log
```

### 7. DNS 发件域配置

为了正常投递，发件域需要配置：

- SPF
- DKIM
- DMARC

DKIM 通常由邮箱服务商提供。

## 发信速度怎么控制

发送活动里有 `每封发送间隔秒数`。

Supervisor worker 的逻辑是：

```text
取一封 queued 邮件
标记 processing
调用 SMTP 发送
成功写 sent，失败写 failed 和错误原因
检查活动是否完成
sleep(interval_seconds)
继续下一封
```

所以它是单进程串行发送，不是并发群发。

## 使用流程

1. 打开后台首页。
2. 导入联系人。
3. 检查或新建模板。
4. 创建发送活动，设置发送间隔。
5. Supervisor worker 自动发送。
6. 在 `发送日志` 页面查看逐封状态和失败原因。

## 实时监控

控制台首页会自动刷新：

- 联系人、队列中、处理中、已发送、失败、跳过数量。
- Supervisor worker 是否在线。
- worker 最近心跳时间。
- 当前正在处理的队列 ID 和邮箱。
- 最近发送活动进度。
- 最近 30 条发送日志。

判断 worker 在线的规则是：`worker_status.heartbeat_at` 距当前时间不超过 20 秒。

## 注意

- 不要提交 `config.php`，里面有数据库和 SMTP 密码。
- 不要导入未授权、购买或抓取的邮箱列表。
- 新 SMTP 账号建议 60-120 秒一封起步。
- 如果 Supervisor 没启动，活动会停在 `queued`，不会自动发送。
