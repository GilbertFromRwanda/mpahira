-- Run once against an existing mpahira database: lets admins flag a payment
-- method as requiring the customer to upload proof of payment at checkout.

ALTER TABLE payment_methods
    ADD COLUMN requires_proof TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE orders
    ADD COLUMN payment_proof VARCHAR(255) DEFAULT NULL AFTER payment_method;
