create table if not exists contact_lists (
  id bigint unsigned primary key auto_increment,
  name varchar(255) not null,
  source varchar(255) not null default '',
  filename varchar(255) not null default '',
  total_count int unsigned not null default 0,
  created_at datetime not null default current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table if not exists contact_list_items (
  list_id bigint unsigned not null,
  contact_id bigint unsigned not null,
  created_at datetime not null default current_timestamp,
  primary key (list_id, contact_id),
  index idx_contact_list_items_contact_id (contact_id),
  constraint fk_contact_list_items_list foreign key (list_id) references contact_lists(id) on delete cascade,
  constraint fk_contact_list_items_contact foreign key (contact_id) references contacts(id) on delete cascade
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

set @sql := if(
  (select count(*) from information_schema.columns where table_schema = database() and table_name = 'campaigns' and column_name = 'list_id') = 0,
  'alter table campaigns add column list_id bigint unsigned null after template_id',
  'select 1'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql := if(
  (select count(*) from information_schema.statistics where table_schema = database() and table_name = 'campaigns' and index_name = 'idx_campaigns_list_id') = 0,
  'alter table campaigns add index idx_campaigns_list_id (list_id)',
  'select 1'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

set @sql := if(
  (select count(*) from information_schema.table_constraints where table_schema = database() and table_name = 'campaigns' and constraint_name = 'fk_campaigns_contact_list') = 0,
  'alter table campaigns add constraint fk_campaigns_contact_list foreign key (list_id) references contact_lists(id) on delete set null',
  'select 1'
);
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;
