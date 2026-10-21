-- =====================================================================
--  MORNING CATCH — Fish pre-booking app
--  Database schema (MySQL 5.7+ / MariaDB 10.3+ — works on Hostinger)
--
--  How to use:
--    1. hPanel → Databases → MySQL Databases → create a DB + user.
--    2. hPanel → phpMyAdmin → select the DB → "Import" this file
--       (or paste it into the "SQL" tab and click Go).
--
--  Tables:
--    products     → the "Catch of the Day" the seller updates each morning
--    orders       → one row per checkout (customer + payment details)
--    order_items  → the fish inside each order (a buyer can book several)
--
--  Why a separate order_items table?
--    Buyers often want 1 kg Mackerel + 2 Pomfret in one go. Keeping items
--    in their own table means one payment, one receipt, one Order ID —
--    and the seller's packing list stays clean.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';   -- store timestamps in IST

-- ---------------------------------------------------------------------
--  1. PRODUCTS — today's catch
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name            VARCHAR(80)      NOT NULL,                 -- "Kingfish"
    local_name      VARCHAR(80)      DEFAULT NULL,             -- "Surmai"
    description     VARCHAR(255)     DEFAULT NULL,             -- short line shown on the card
    category        ENUM('fish','shellfish') NOT NULL DEFAULT 'fish',

    -- Pricing: either per kg or per piece
    unit            ENUM('kg','piece') NOT NULL DEFAULT 'kg',
    price           DECIMAL(10,2)    NOT NULL,                 -- price for ONE unit (1 kg or 1 piece), in ₹

    -- Quantity rules (kg items usually step by 0.5, pieces by 1)
    min_qty         DECIMAL(6,2)     NOT NULL DEFAULT 0.50,
    step_qty        DECIMAL(6,2)     NOT NULL DEFAULT 0.50,

    -- Stock: how much is left to book today. Reduced when an order is PAID.
    stock_qty       DECIMAL(8,2)     NOT NULL DEFAULT 0,
    initial_stock   DECIMAL(8,2)     NOT NULL DEFAULT 0,       -- what landed this morning (for the "left" bar)

    -- Which cleaning/cut options the seller offers for this fish
    -- (comma-separated, shown as a dropdown on the card)
    cut_options     VARCHAR(160)     NOT NULL DEFAULT 'Whole,Cleaned,Curry cut,Fry cut',

    image_url       VARCHAR(255)     DEFAULT NULL,             -- e.g. assets/img/kingfish.jpg (optional)
    badge           VARCHAR(30)      DEFAULT NULL,             -- "Bestseller", "Just landed", etc.
    is_available    TINYINT(1)       NOT NULL DEFAULT 1,       -- 0 = hide from today's menu
    sort_order      INT              NOT NULL DEFAULT 0,

    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_available (is_available, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  2. ORDERS — one row per checkout
--
--  Lifecycle:
--    create_order.php   → inserts the row with payment_status = 'created'
--                         (amount is calculated on the SERVER from the
--                          products table, never trusted from the browser)
--    verify_payment.php → checks Razorpay signature, sets 'paid',
--                         reduces stock, sends Email + WhatsApp receipts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id                    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    order_ref             VARCHAR(24)    NOT NULL,             -- human-friendly ID on receipts: MC-201026-0042

    -- Customer
    customer_name         VARCHAR(100)   NOT NULL,
    customer_phone        VARCHAR(15)    NOT NULL,             -- stored with country code: 919876543210
    customer_email        VARCHAR(150)   NOT NULL,
    fulfilment            ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
    delivery_address      TEXT           DEFAULT NULL,         -- NULL for shop pickup
    delivery_slot         VARCHAR(40)    DEFAULT NULL,         -- "7:00 – 8:00 AM"
    notes                 VARCHAR(255)   DEFAULT NULL,         -- "Ring the bell twice", "Extra cleaning"

    -- Money (₹)
    subtotal              DECIMAL(10,2)  NOT NULL,
    delivery_fee          DECIMAL(10,2)  NOT NULL DEFAULT 0,
    total_amount          DECIMAL(10,2)  NOT NULL,
    currency              CHAR(3)        NOT NULL DEFAULT 'INR',

    -- Razorpay
    razorpay_order_id     VARCHAR(40)    DEFAULT NULL,
    razorpay_payment_id   VARCHAR(40)    DEFAULT NULL,
    razorpay_signature    VARCHAR(128)   DEFAULT NULL,
    payment_status        ENUM('created','paid','failed','refunded') NOT NULL DEFAULT 'created',

    -- Seller side
    order_status          ENUM('pending','kept_aside','out_for_delivery','completed','cancelled')
                          NOT NULL DEFAULT 'pending',

    -- Notification tracking (so failed receipts can be retried)
    email_sent            TINYINT(1)     NOT NULL DEFAULT 0,
    whatsapp_sent         TINYINT(1)     NOT NULL DEFAULT 0,

    created_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at               TIMESTAMP      NULL DEFAULT NULL,
    updated_at            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_order_ref (order_ref),
    UNIQUE KEY uq_rzp_order (razorpay_order_id),
    UNIQUE KEY uq_rzp_payment (razorpay_payment_id),       -- the same payment can never be recorded twice
    KEY idx_status_date (payment_status, created_at),
    KEY idx_phone (customer_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  3. ORDER ITEMS — the fish inside each order
--  Name, unit and price are copied ("snapshotted") at order time, so a
--  receipt stays correct even if the seller changes prices tomorrow.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    order_id        INT UNSIGNED   NOT NULL,
    product_id      INT UNSIGNED   NOT NULL,
    product_name    VARCHAR(80)    NOT NULL,
    unit            ENUM('kg','piece') NOT NULL,
    cut_type        VARCHAR(40)    DEFAULT NULL,
    quantity        DECIMAL(8,2)   NOT NULL,
    unit_price      DECIMAL(10,2)  NOT NULL,
    line_total      DECIMAL(10,2)  NOT NULL,

    PRIMARY KEY (id),
    KEY idx_order (order_id),
    CONSTRAINT fk_items_order   FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  4. SAMPLE DATA — a typical morning's catch (edit freely)
-- ---------------------------------------------------------------------
INSERT INTO products
    (name, local_name, description, category, unit, price, min_qty, step_qty,
     stock_qty, initial_stock, cut_options, badge, sort_order)
VALUES
    ('Kingfish',       'Surmai',   'Firm, meaty steaks. Perfect for rava fry.',          'fish',      'kg',    900.00, 0.50, 0.50, 12.00, 15.00, 'Whole,Cleaned,Steaks (fry cut),Curry cut', 'Bestseller',  1),
    ('Silver Pomfret', 'Paplet',   'Medium size, about 350–400 g each.',                 'fish',      'piece', 380.00, 1.00, 1.00, 18.00, 24.00, 'Whole,Cleaned,Scored for tawa fry',        'Just landed', 2),
    ('Mackerel',       'Bangda',   'Oily and full of flavour. Great for recheado.',      'fish',      'kg',    260.00, 0.50, 0.50, 20.00, 25.00, 'Whole,Cleaned,Slit for masala',            NULL,          3),
    ('Tiger Prawns',   'Kolambi',  'Large, about 20–25 per kg.',                         'shellfish', 'kg',    650.00, 0.50, 0.50,  6.00, 10.00, 'Whole,Peeled & deveined,Head-off',         'Few left',    4),
    ('Sardines',       'Tarle',    'Small and sweet. Best fried crisp.',                 'fish',      'kg',    200.00, 0.50, 0.50, 15.00, 15.00, 'Whole,Cleaned',                            NULL,          5),
    ('Lady Fish',      'Muddoshi', 'Delicate white flesh. A local favourite.',           'fish',      'kg',    520.00, 0.50, 0.50,  8.00, 10.00, 'Whole,Cleaned,Butterflied',                NULL,          6),
    ('Mud Crab',       'Kurlya',   'Live crabs, about 300–400 g each.',                  'shellfish', 'piece', 280.00, 1.00, 1.00, 10.00, 12.00, 'Whole (live),Cleaned & cut',               NULL,          7);


-- ---------------------------------------------------------------------
--  Handy queries for the seller (run in phpMyAdmin)
-- ---------------------------------------------------------------------
-- Today's paid orders with their items (packing list):
--   SELECT o.order_ref, o.customer_name, o.customer_phone, o.delivery_slot,
--          i.product_name, i.quantity, i.unit, i.cut_type
--   FROM orders o JOIN order_items i ON i.order_id = o.id
--   WHERE o.payment_status = 'paid' AND DATE(o.paid_at) = CURDATE()
--   ORDER BY o.delivery_slot, o.id;
--
-- Reset stock for a new morning:
--   UPDATE products SET stock_qty = 15, initial_stock = 15 WHERE id = 1;
