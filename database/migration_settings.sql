-- Run once against an existing mpahira database to add a generic key/value
-- settings store, starting with an admin-configurable minimum order total.

CREATE TABLE settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL
);

INSERT INTO settings (`key`, `value`) VALUES ('min_order_total', '0');
