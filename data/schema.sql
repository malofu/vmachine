-- Schema for the SQLite persistence adapter.
--
-- A single vending machine, stored as four count-keyed tables. There is no
-- machine/version anchor row: this is a single-process CLI, so no concurrency
-- control is needed. Amounts are integer cents throughout.
--
-- Run on repository construction (idempotent), or by hand:
--   sqlite3 data/machine.sqlite < data/schema.sql

CREATE TABLE IF NOT EXISTS products (
    selector    TEXT PRIMARY KEY,
    price_cents INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS inventory (
    selector TEXT PRIMARY KEY,
    count    INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS coin_bank (
    cents INTEGER PRIMARY KEY,
    count INTEGER NOT NULL
);

-- Coins the current customer has inserted but not yet spent. Counts only, no
-- insertion order: the order is never observable, only the total and the coins.
CREATE TABLE IF NOT EXISTS inserted_money (
    cents INTEGER PRIMARY KEY,
    count INTEGER NOT NULL
);
