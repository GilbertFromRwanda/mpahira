-- Run once against an existing mpahira database to let an order be assigned
-- to a specific admin ("incharge") responsible for handling it. Only admins
-- with the 'orders' permission (or super admins) are eligible assignees.

ALTER TABLE orders ADD COLUMN incharge_id INT NULL AFTER status;
ALTER TABLE orders ADD CONSTRAINT fk_orders_incharge FOREIGN KEY (incharge_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE orders ADD INDEX idx_orders_incharge (incharge_id);
