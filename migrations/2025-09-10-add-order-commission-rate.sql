-- Add per-invoice commission rate to orders (idempotent)
START TRANSACTION;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS commission_rate DECIMAL(5,2) NULL AFTER total;

-- Backfill from existing commissions where available (sales_agent percentage)
UPDATE orders o
JOIN (
  SELECT order_id, MAX(percentage) AS pct
  FROM commissions
  WHERE commission_type = 'sales_agent'
  GROUP BY order_id
) c ON c.order_id = o.id
SET o.commission_rate = c.pct
WHERE o.commission_rate IS NULL;

COMMIT;


