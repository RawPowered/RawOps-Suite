-- Admin Dashboard Views (Idempotent via DROP/CREATE)
-- Adheres to SQL-FORMATTING-RULES.md: explicit columns, consistent casing.

START TRANSACTION;

DROP VIEW IF EXISTS vw_revenue_daily;
CREATE VIEW vw_revenue_daily AS
SELECT 
    DATE(o.created_at) AS day,
    COUNT(*) AS orders_count,
    SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0 END) AS revenue_total,
    AVG(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE NULL END) AS avg_order_value
FROM orders o
GROUP BY DATE(o.created_at);

DROP VIEW IF EXISTS vw_orders_status_daily;
CREATE VIEW vw_orders_status_daily AS
SELECT 
    DATE(o.created_at) AS day,
    o.status AS status,
    COUNT(*) AS orders_count
FROM orders o
GROUP BY DATE(o.created_at), o.status;

DROP VIEW IF EXISTS vw_commissions_daily;
CREATE VIEW vw_commissions_daily AS
SELECT 
    DATE(c.created_at) AS day,
    c.user_id AS user_id,
    SUM(CASE WHEN c.status = 'pending' THEN c.amount ELSE 0 END) AS pending_amount,
    SUM(CASE WHEN c.status = 'approved' THEN c.amount ELSE 0 END) AS approved_amount
FROM commissions c
GROUP BY DATE(c.created_at), c.user_id;

DROP VIEW IF EXISTS vw_training_completed_daily;
CREATE VIEW vw_training_completed_daily AS
SELECT 
    DATE(tp.completed_at) AS day,
    COUNT(*) AS completed_count
FROM training_progress tp
WHERE tp.status = 'completed' AND tp.completed_at IS NOT NULL
GROUP BY DATE(tp.completed_at);

COMMIT;


