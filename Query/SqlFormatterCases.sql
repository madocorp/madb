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
FROM `invoice_item` `i`
INNER JOIN `invoice` `inv` ON `inv`.`id` = `i`.`invoice_id`
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
