create table if not exists contacts (
  id bigint unsigned primary key auto_increment,
  email varchar(255) not null unique,
  name varchar(255) not null default '',
  company varchar(255) not null default '',
  source varchar(255) not null default '',
  vars_json json null,
  created_at datetime not null default current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table if not exists suppressions (
  email varchar(255) primary key,
  reason varchar(100) not null,
  created_at datetime not null default current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table if not exists templates (
  id bigint unsigned primary key auto_increment,
  name varchar(255) not null,
  subject varchar(500) not null,
  html_body mediumtext not null,
  text_body mediumtext not null,
  created_at datetime not null default current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table if not exists campaigns (
  id bigint unsigned primary key auto_increment,
  template_id bigint unsigned not null,
  name varchar(255) not null,
  interval_seconds int unsigned not null default 60,
  status enum('queued','sending','done','stopped') not null default 'queued',
  created_at datetime not null default current_timestamp,
  started_at datetime null,
  finished_at datetime null,
  index idx_campaigns_template_id (template_id),
  constraint fk_campaigns_template foreign key (template_id) references templates(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table if not exists send_queue (
  id bigint unsigned primary key auto_increment,
  campaign_id bigint unsigned not null,
  contact_id bigint unsigned not null,
  email varchar(255) not null,
  status enum('queued','processing','sent','failed','skipped') not null default 'queued',
  error text null,
  created_at datetime not null default current_timestamp,
  locked_at datetime null,
  sent_at datetime null,
  index idx_queue_campaign_status (campaign_id, status, id),
  index idx_queue_status_id (status, id),
  constraint fk_queue_campaign foreign key (campaign_id) references campaigns(id),
  constraint fk_queue_contact foreign key (contact_id) references contacts(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table if not exists worker_status (
  name varchar(100) primary key,
  pid int unsigned null,
  current_queue_id bigint unsigned null,
  current_email varchar(255) null,
  state varchar(50) not null default 'idle',
  heartbeat_at datetime not null,
  updated_at datetime not null default current_timestamp on update current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

insert into templates (name, subject, html_body, text_body)
select '产品更新通知',
       '{{company}} 产品更新：本月改进摘要',
       '<p>{{name}} 您好，</p><p>这里是 {{sender_name}}。我们整理了近期产品更新，方便您了解已经上线的改进和后续计划。</p><ul><li>稳定性和性能优化</li><li>常用流程的细节改进</li><li>文档和支持内容更新</li></ul><p>如果您有任何问题，可以直接回复这封邮件。</p><p style="font-size:12px;color:#667085">如果不想继续接收此类邮件，可点击 <a href="{{unsubscribe_url}}">退订</a>。</p>',
       '{{name}} 您好，

这里是 {{sender_name}}。我们整理了近期产品更新，方便您了解已经上线的改进和后续计划。

- 稳定性和性能优化
- 常用流程的细节改进
- 文档和支持内容更新

如果您有任何问题，可以直接回复这封邮件。

退订：{{unsubscribe_url}}'
where not exists (select 1 from templates limit 1);

insert into templates (name, subject, html_body, text_body)
select '客户服务跟进',
       '{{company}} 服务跟进',
       '<p>{{name}} 您好，</p><p>我们想确认您近期使用过程中是否还有需要协助的地方。您可以直接回复这封邮件，我们会继续跟进。</p><p>感谢您的时间。</p><p style="font-size:12px;color:#667085">{{sender_name}} 发送。退订链接：<a href="{{unsubscribe_url}}">{{unsubscribe_url}}</a></p>',
       '{{name}} 您好，

我们想确认您近期使用过程中是否还有需要协助的地方。您可以直接回复这封邮件，我们会继续跟进。

感谢您的时间。

退订：{{unsubscribe_url}}'
where (select count(*) from templates) = 1;

insert into templates (name, subject, html_body, text_body)
select '系统维护通知',
       '{{company}} 系统维护通知',
       '<p>{{name}} 您好，</p><p>我们计划进行一次例行维护。维护期间部分服务可能短暂不可用，完成后服务会自动恢复。</p><p>如需安排特殊支持，请回复本邮件联系我们。</p><p style="font-size:12px;color:#667085">退订或停止接收通知：<a href="{{unsubscribe_url}}">{{unsubscribe_url}}</a></p>',
       '{{name}} 您好，

我们计划进行一次例行维护。维护期间部分服务可能短暂不可用，完成后服务会自动恢复。

如需安排特殊支持，请回复本邮件联系我们。

退订：{{unsubscribe_url}}'
where (select count(*) from templates) = 2;
