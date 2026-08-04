-- Run once against an existing mpahira database to index users.name with
-- FULLTEXT, used by admin/orders.php's customer search filter.

ALTER TABLE users
    ADD FULLTEXT INDEX ft_users_name (name);
