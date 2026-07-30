-- Run once against an existing mpahira database to add granular per-module
-- admin permissions. Existing admins are grandfathered in as super admins
-- (full access, including managing other users' permissions) so nobody
-- loses access when this ships.

ALTER TABLE users ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
UPDATE users SET is_super = 1 WHERE role = 'admin';

CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    module VARCHAR(50) NOT NULL,
    UNIQUE KEY uniq_user_module (user_id, module),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
