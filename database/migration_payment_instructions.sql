-- Run once against an existing mpahira database to let each payment method
-- carry its own "where to send the amount" instructions (MoMo number, bank
-- account, agent name, etc.), shown to the customer at checkout.

ALTER TABLE payment_methods ADD COLUMN instructions TEXT NULL AFTER requires_proof;
