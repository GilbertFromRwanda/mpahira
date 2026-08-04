-- Run once against an existing mpahira database to add the has_variants /
-- display_price cache columns used by shop.php, and backfill them for
-- existing top-level products. From this point on, admin/products.php keeps
-- these columns in sync on every write.

ALTER TABLE products
    ADD COLUMN has_variants TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN display_price DECIMAL(10,2) NULL;

UPDATE products p
JOIN (
    SELECT p2.id,
           EXISTS (SELECT 1 FROM products v WHERE v.parent_id = p2.id AND v.status = 'active') AS has_variants,
           COALESCE(
               NULLIF(
                   LEAST(
                       COALESCE(
                           (SELECT MIN(v.price) FROM products v
                            WHERE v.parent_id = p2.id AND v.status = 'active'
                              AND NOT EXISTS (SELECT 1 FROM products g WHERE g.parent_id = v.id AND g.status = 'active')),
                           999999999.99
                       ),
                       COALESCE(
                           (SELECT MIN(g.price) FROM products v
                            JOIN products g ON g.parent_id = v.id
                            WHERE v.parent_id = p2.id AND v.status = 'active' AND g.status = 'active'),
                           999999999.99
                       )
                   ),
                   999999999.99
               ),
               p2.price
           ) AS display_price
    FROM products p2
    WHERE p2.parent_id IS NULL
) t ON t.id = p.id
SET p.has_variants = t.has_variants, p.display_price = t.display_price;
