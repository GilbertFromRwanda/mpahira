-- Run once against an existing mpahira database to make payment methods
-- admin-managed instead of hardcoded ("cash" / "momo" in checkout.php).

CREATE TABLE payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO payment_methods (name, status) VALUES
('Cash on Delivery', 'active'),
('Mobile Money', 'active');
