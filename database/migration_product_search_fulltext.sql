-- Run once against an existing mpahira database to add the search_blob column
-- used by shop.php's search box, backfill it for existing top-level products,
-- and index it with FULLTEXT. From this point on, admin/products.php keeps
-- search_blob in sync on every write (product/variant/package/meta changes).

ALTER TABLE products
    ADD COLUMN search_blob TEXT NULL;

UPDATE products p
JOIN (
    SELECT p2.id,
           CONCAT_WS(' ',
               p2.name,
               (SELECT GROUP_CONCAT(v.name SEPARATOR ' ') FROM products v WHERE v.parent_id = p2.id),
               (SELECT GROUP_CONCAT(g.name SEPARATOR ' ') FROM products v JOIN products g ON g.parent_id = v.id WHERE v.parent_id = p2.id),
               (SELECT GROUP_CONCAT(pm.meta_value SEPARATOR ' ') FROM product_meta pm WHERE pm.product_id = p2.id)
           ) AS search_blob
    FROM products p2
    WHERE p2.parent_id IS NULL
) t ON t.id = p.id
SET p.search_blob = t.search_blob;

ALTER TABLE products
    ADD FULLTEXT INDEX ft_products_search_blob (search_blob);
