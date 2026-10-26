-- Create tables for homepage tools access widget
START TRANSACTION;

CREATE TABLE IF NOT EXISTS tools_access_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(100) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tools_access_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_id INT NOT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  INDEX (key_id),
  CONSTRAINT fk_tools_access_log_key FOREIGN KEY (key_id)
    REFERENCES tools_access_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

