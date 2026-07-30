CREATE DATABASE IF NOT EXISTS mpahira CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mpahira;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(150) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    is_super TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- A system user (role='admin') only sees the admin modules listed here unless
-- is_super=1, in which case they have full, unrestricted access (including
-- managing other users' permissions).
CREATE TABLE user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module VARCHAR(50) NOT NULL,
    UNIQUE KEY uniq_user_module (user_id, module),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- parent_id supports two levels of nesting: a top-level product (parent_id NULL)
-- can have Variants (e.g. "Red"), and each Variant can have Packages (e.g. "1kg",
-- with its own price) -- the Package is the actual purchasable item. A node with
-- no children of its own is directly purchasable, whichever level it's at.
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    parent_id INT DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    image VARCHAR(255),
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES products(id) ON DELETE CASCADE
);

-- product_meta: free-form key/value attributes (e.g. Origin: Rwanda) that also feed search
CREATE TABLE product_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value VARCHAR(255) NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_meta_value (meta_value)
);

CREATE TABLE carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cart_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    fee_type ENUM('fixed','negotiable') NOT NULL DEFAULT 'fixed',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    zone_id INT,
    province VARCHAR(100),
    district VARCHAR(100),
    sector VARCHAR(100),
    cell VARCHAR(100),
    village VARCHAR(100),
    address TEXT,
    phone VARCHAR(20),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL
);

CREATE TABLE settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL
);

CREATE TABLE payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    requires_proof TINYINT(1) NOT NULL DEFAULT 0,
    instructions TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address_id INT,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivery_fee_type ENUM('fixed','negotiable') NOT NULL DEFAULT 'fixed',
    payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
    payment_proof VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','confirmed','preparing','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (address_id) REFERENCES addresses(id) ON DELETE SET NULL
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id)
);

CREATE TABLE order_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status VARCHAR(30) NOT NULL,
    comment VARCHAR(500) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_order_status_history_order (order_id)
);

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Default admin account (password: admin123 -- change after first login)
INSERT INTO users (name, phone, email, password, role) VALUES
('Admin', '0780000000', 'admin@mpahira.rw', '$2y$10$fO8xiX7zWLil4e/6fiAuouEHOk87aT1LF.L/N7fP47r5brPjcthlO', 'admin');

INSERT INTO payment_methods (name, status) VALUES
('Cash on Delivery', 'active'),
('Mobile Money', 'active');

INSERT INTO settings (`key`, `value`) VALUES ('min_order_total', '0');
