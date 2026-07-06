-- Default provisioning for a fresh SQLite store, so the machine boots ready to
-- vend. Run once, only when the database is empty (see bin/vending-machine).
-- Amounts are integer cents. inserted_money is intentionally left empty: a fresh
-- machine holds no customer coins.

INSERT INTO products (selector, price_cents) VALUES
    ('WATER', 65),
    ('JUICE', 100),
    ('SODA', 150);

INSERT INTO inventory (selector, count) VALUES
    ('WATER', 5),
    ('JUICE', 5),
    ('SODA', 5);

INSERT INTO coin_bank (cents, count) VALUES
    (100, 10),
    (25, 20),
    (10, 20),
    (5, 20);
