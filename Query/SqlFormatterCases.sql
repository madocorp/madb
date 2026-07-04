-- CASE: create table generated
-- INPUT
create table invoice_item (
id int unsigned not null auto_increment,
invoice_id int unsigned not null,
line_no smallint unsigned not null default 1 comment 'visible order',
item_code varchar(32) character set utf8mb4 collate utf8mb4_unicode_ci not null,
price decimal(10, 2) not null default 0.00,
kind enum('service', 'product', 'discount') not null default 'service',
flags set('taxable', 'exported', 'manual') not null default 'taxable',
created_at timestamp not null default current_timestamp,
primary key (id),
unique key invoice_line (invoice_id, line_no),
key item_code (item_code),
constraint invoice_item_invoice foreign key (invoice_id) references invoice (id) on delete cascade
) engine=InnoDB default charset=utf8mb4 collate utf8mb4_unicode_ci comment='Invoice lines';
-- EXPECT
CREATE TABLE `invoice_item` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `line_no` SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'visible order',
  `item_code` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `kind` ENUM('service', 'product', 'discount') NOT NULL DEFAULT 'service',
  `flags` SET('taxable', 'exported', 'manual') NOT NULL DEFAULT 'taxable',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_line` (`invoice_id`, `line_no`),
  KEY `item_code` (`item_code`),
  CONSTRAINT `invoice_item_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoice` (`id`) ON DELETE CASCADE
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE utf8mb4_unicode_ci
COMMENT = 'Invoice lines';
-- END

-- CASE: alter table generated
-- INPUT
alter table invoice_item add column tax_rate decimal(5, 2) unsigned not null default 0.00 after price, add key tax_rate (tax_rate), add constraint invoice_item_product foreign key (item_code) references product (code) on update cascade on delete restrict;
-- EXPECT
ALTER TABLE `invoice_item`
  ADD COLUMN `tax_rate` DECIMAL(5, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER `price`,
  ADD KEY `tax_rate` (`tax_rate`),
  ADD CONSTRAINT `invoice_item_product` FOREIGN KEY (`item_code`) REFERENCES `product` (`code`) ON UPDATE CASCADE ON DELETE RESTRICT;
-- END

-- CASE: single alter add column
-- INPUT
alter table invoice_item add column tax_rate decimal(5, 2) unsigned not null default 0.00 after price;
-- EXPECT
ALTER TABLE `invoice_item` ADD COLUMN `tax_rate` DECIMAL(5, 2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER `price`;
-- END

-- CASE: single alter add index
-- INPUT
alter table invoice_item add index tax_rate (tax_rate);
-- EXPECT
ALTER TABLE `invoice_item` ADD INDEX `tax_rate` (`tax_rate`);
-- END

-- CASE: copy table query
-- INPUT
insert into archive.invoice_item (id, invoice_id, item_code, price, kind) select id, invoice_id, item_code, price, kind from live.invoice_item where invoice_id in (1, 2, 3) order by line_no;
-- EXPECT
INSERT INTO `archive`.`invoice_item` (
  `id`,
  `invoice_id`,
  `item_code`,
  `price`,
  `kind`
)
SELECT
  `id`,
  `invoice_id`,
  `item_code`,
  `price`,
  `kind`
FROM `live`.`invoice_item`
WHERE `invoice_id` IN(1, 2, 3)
ORDER BY `line_no`;
-- END

-- CASE: select join aliases
-- INPUT
select i.id, i.invoice_id, customer.name as customer, sum(i.price) as total from invoice_item i inner join invoice inv on inv.id=i.invoice_id left join customer on customer.id=inv.customer_id where i.kind in ('service', 'product') and inv.deleted_at is null group by i.invoice_id, customer.name having total > 0 order by customer.name asc, i.invoice_id desc limit 50;
-- EXPECT
SELECT
  `i`.`id`,
  `i`.`invoice_id`,
  `customer`.`name` AS `customer`,
  SUM(`i`.`price`) AS `total`
FROM `invoice_item` AS `i`
INNER JOIN `invoice` AS `inv` ON `inv`.`id` = `i`.`invoice_id`
LEFT JOIN `customer` ON `customer`.`id` = `inv`.`customer_id`
WHERE
  `i`.`kind` IN ('service', 'product') AND
  `inv`.`deleted_at` IS NULL
GROUP BY
  `i`.`invoice_id`,
  `customer`.`name`
HAVING `total` > 0
ORDER BY
  `customer`.`name` ASC,
  `i`.`invoice_id` DESC
LIMIT 50;
-- END

-- CASE: update set list
-- INPUT
update invoice_item set price=price*1.2, flags='taxable,manual', updated_at=now() where id = :id and kind <> 'discount';
-- EXPECT
UPDATE `invoice_item`
SET
  `price` = `price` * 1.2,
  `flags` = 'taxable,manual',
  `updated_at` = NOW()
WHERE
  `id` = :id AND
  `kind` <> 'discount';
-- END

-- CASE: short select
-- INPUT
select id from user where id=1;
-- EXPECT
SELECT `id`
FROM `user`
WHERE `id` = 1;
-- END

-- CASE: long select list
-- INPUT
select id, first_name, last_name, email, phone, status, created_at, updated_at from customer where status = 'active' order by last_name, first_name;
-- EXPECT
SELECT
  `id`,
  `first_name`,
  `last_name`,
  `email`,
  `phone`,
  `status`,
  `created_at`,
  `updated_at`
FROM `customer`
WHERE `status` = 'active'
ORDER BY
  `last_name`,
  `first_name`;
-- END

-- CASE: join multiple conditions
-- INPUT
select o.id, c.name, p.sku from orders o join customer c on c.id=o.customer_id and c.tenant_id=o.tenant_id left join product p on p.id=o.product_id and p.deleted_at is null and p.status <> 'archived' where o.created_at between :from and :to and o.status in ('open', 'paid');
-- EXPECT
SELECT
  `o`.`id`,
  `c`.`name`,
  `p`.`sku`
FROM `orders` AS `o`
JOIN `customer` AS `c` ON
  `c`.`id` = `o`.`customer_id` AND
  `c`.`tenant_id` = `o`.`tenant_id`
LEFT JOIN `product` AS `p` ON
  `p`.`id` = `o`.`product_id` AND
  `p`.`deleted_at` IS NULL AND
  `p`.`status` <> 'archived'
WHERE
  `o`.`created_at` BETWEEN :from AND
  :to AND
  `o`.`status` IN ('open', 'paid');
-- END

-- CASE: complex create table
-- INPUT
create table order_audit (id bigint unsigned not null auto_increment, order_id bigint unsigned not null, old_status enum('new','paid','cancelled','refunded') not null, new_status enum('new','paid','cancelled','refunded') not null, changed_by varchar(64) null, amount decimal(12, 4) unsigned not null default 0.0000, tags set('manual','api','batch','import') not null default 'api', payload json null, changed_at datetime not null default current_timestamp, primary key (id), key order_status (order_id, new_status, changed_at), constraint order_audit_order foreign key (order_id) references orders (id) on update cascade on delete cascade) engine=InnoDB default charset=utf8mb4 collate utf8mb4_unicode_ci comment='Order status history';
-- EXPECT
CREATE TABLE `order_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `old_status` ENUM('new', 'paid', 'cancelled', 'refunded') NOT NULL,
  `new_status` ENUM('new', 'paid', 'cancelled', 'refunded') NOT NULL,
  `changed_by` VARCHAR(64) NULL,
  `amount` DECIMAL(12, 4) UNSIGNED NOT NULL DEFAULT 0.0000,
  `tags` SET('manual', 'api', 'batch', 'import') NOT NULL DEFAULT 'api',
  `payload` JSON NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_status` (`order_id`, `new_status`, `changed_at`),
  CONSTRAINT `order_audit_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE utf8mb4_unicode_ci
COMMENT = 'Order status history';
-- END

-- CASE: complex alter table
-- INPUT
alter table order_audit add column source varchar(32) not null default 'system' after changed_by, modify column amount decimal(14, 4) unsigned not null default 0.0000, drop key order_status, add unique key order_status_source (order_id, new_status, source, changed_at), add constraint order_audit_user foreign key (changed_by) references user (login) on update cascade on delete set null;
-- EXPECT
ALTER TABLE `order_audit`
  ADD COLUMN `source` VARCHAR(32) NOT NULL DEFAULT 'system' AFTER `changed_by`,
  MODIFY COLUMN `amount` DECIMAL(14, 4) UNSIGNED NOT NULL DEFAULT 0.0000,
  DROP KEY `order_status`,
  ADD UNIQUE KEY `order_status_source` (`order_id`, `new_status`, `source`, `changed_at`),
  ADD CONSTRAINT `order_audit_user` FOREIGN KEY (`changed_by`) REFERENCES `user` (`login`) ON UPDATE CASCADE ON DELETE SET NULL;
-- END

-- CASE: insert values list
-- INPUT
insert into customer_status (id, label, sort_order) values (1, 'active', 10), (2, 'locked', 20), (3, 'deleted', 30);
-- EXPECT
INSERT INTO `customer_status` (`id`, `label`, `sort_order`)
VALUES
  (1, 'active', 10),
  (2, 'locked', 20),
  (3, 'deleted', 30);
-- END

-- CASE: insert values
-- INPUT
insert into customer_status (id, label, sort_order) values (1, 'active', 10);
-- EXPECT
INSERT INTO `customer_status` (`id`, `label`, `sort_order`)
VALUES (1, 'active', 10);
-- END

-- CASE: longer insert values list
-- INPUT
insert into customer_status (id, label, sort_order, a, b, c) values (1, 'active', 10, 1, 2, 3), (2, 'locked', 20, 4, 5, 6);
-- EXPECT
INSERT INTO `customer_status` (
  `id`, `label`, `sort_order`, `a`,
  `b`, `c`
)
VALUES (
  1, 'active', 10, 1,
  2, 3
), (
  2, 'locked', 20, 4,
  5, 6
);
-- END

-- CASE: case expression
-- INPUT
select id, case when status='paid' then 'closed' when status='cancelled' then 'closed' else 'open' end as bucket from orders where tenant_id = [TENANT_ID] and deleted_at is null;
-- EXPECT
SELECT
  `id`,
  CASE
    WHEN `status` = 'paid' THEN 'closed'
    WHEN `status` = 'cancelled' THEN 'closed'
    ELSE 'open'
  END AS `bucket`
FROM `orders`
WHERE
  `tenant_id` = [TENANT_ID] AND
  `deleted_at` IS NULL;
-- END

-- CASE: implicit aliases
-- INPUT
select first_name name, count(*) total from customer c group by first_name;
-- EXPECT
SELECT
  `first_name` AS `name`,
  COUNT(*) AS `total`
FROM `customer` AS `c`
GROUP BY `first_name`;
-- END

-- CASE: union all
-- INPUT
select id, name from customer where status='active' union all select id, name from archived_customer where status='active' order by name;
-- EXPECT
(
  SELECT
    `id`,
    `name`
  FROM `customer`
  WHERE `status` = 'active'
)
UNION ALL
(
  SELECT
    `id`,
    `name`
  FROM `archived_customer`
  WHERE `status` = 'active'
)
ORDER BY `name`;
-- END

-- CASE: full line comment
-- INPUT
select id, name from customer
-- active customers
where active=1;
-- EXPECT
SELECT
  `id`,
  `name`
FROM `customer`
-- active customers
WHERE `active` = 1;
-- END

-- CASE: trailing line comment
-- INPUT
select id -- primary key
from customer where active=1;
-- EXPECT
SELECT `id` -- primary key
FROM `customer`
WHERE `active` = 1;
-- END

-- CASE: block comment
-- INPUT
select id from customer /* filter active only */ where active=1;
-- EXPECT
SELECT `id`
FROM `customer`
/* filter active only */
WHERE `active` = 1;
-- END

-- CASE: list trailing comment
-- INPUT
select id, -- primary key
name from customer;
-- EXPECT
SELECT
  `id`, -- primary key
  `name`
FROM `customer`;
-- END
