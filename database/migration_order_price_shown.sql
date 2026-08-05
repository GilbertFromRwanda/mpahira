-- Run once against an existing mpahira database to let a user with the
-- 'reveal_price' permission show a specific order's pricing to the customer,
-- even while show_price is off store-wide and the order is still pending.

ALTER TABLE orders ADD COLUMN price_shown TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_proof;
