-- Run once against an existing mpahira database: requires an admin to leave a
-- comment whenever they change an order's status, and keeps a history of it.

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
