-- Fresh migration: synchronize products to spreadsheet values (idempotent)
START TRANSACTION;

-- Archive legacy product SKUs
UPDATE products SET active = 0 WHERE sku IN ('ELIX-REG-32','ELIX-REG-128','ELIX-HP-32','ELIX-HP-128');

-- Upsert official blends
INSERT INTO products (sku, name, description, wholesale_price, msrp, active, created_at)
VALUES
('BLEND-32-20','32/20 Blend - 32oz','20mg attribute per fl oz. 640mg total per bottle.',79.95,85.02,1,NOW()),
('BLEND-128-20','128/20 Blend - 128oz','20mg attribute per fl oz. 2560mg total per bottle.',179.95,186.10,1,NOW()),
('BLEND-32-75','32/75 Blend - 32oz','75mg attribute per fl oz. 2400mg total per bottle.',169.95,174.40,1,NOW()),
('BLEND-128-75','128/75 Blend - 128oz','75mg attribute per fl oz. 9600mg total per bottle.',519.95,528.50,1,NOW())
ON DUPLICATE KEY UPDATE
 name=VALUES(name), description=VALUES(description), wholesale_price=VALUES(wholesale_price), msrp=VALUES(msrp), active=VALUES(active), updated_at=CURRENT_TIMESTAMP;

COMMIT;

-- Verify
SELECT sku, name, wholesale_price, msrp, active FROM products WHERE sku LIKE 'BLEND-%' ORDER BY sku;

