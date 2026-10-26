START TRANSACTION;

CREATE TABLE IF NOT EXISTS commission_agent_tiers (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tier_min INT NOT NULL,
  tier_max INT NULL,
  percentage DECIMAL(6,3) NOT NULL,
  UNIQUE KEY uq_agent_tier (user_id, tier_min, tier_max),
  INDEX idx_agent (user_id),
  CONSTRAINT fk_commission_agent_tiers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
