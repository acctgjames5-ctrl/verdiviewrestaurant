/* ============================================
   PURCHASES MODULE
   ============================================ */

CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_date DATE NOT NULL,
    supplier VARCHAR(150) NOT NULL,
    invoice_no VARCHAR(100) DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,2) DEFAULT 1,
    unit_cost DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    payment_method ENUM('Cash','Bank','Credit','Other') DEFAULT 'Cash',
    status ENUM('Paid','Unpaid','Partial') DEFAULT 'Paid',
    branch_id INT DEFAULT 0,
    notes TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_purchase_date (purchase_date),
    INDEX idx_supplier (supplier),
    INDEX idx_branch (branch_id)
);


/* ============================================
   BANK ACCOUNTS
   ============================================ */

CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(150) NOT NULL,
    account_name VARCHAR(150) DEFAULT NULL,
    account_number VARCHAR(100) DEFAULT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


/* ============================================
   BANK TRANSACTIONS
   ============================================ */

CREATE TABLE IF NOT EXISTS bank_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,

    deposit DECIMAL(15,2) DEFAULT 0,
    withdrawal DECIMAL(15,2) DEFAULT 0,

    book_balance DECIMAL(15,2) DEFAULT 0,
    bank_balance DECIMAL(15,2) DEFAULT 0,

    status ENUM('Unmatched','Matched','Reconciled') DEFAULT 'Unmatched',

    reconciliation_date DATE DEFAULT NULL,

    branch_id INT DEFAULT 0,
    notes TEXT DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_bank_date (transaction_date),
    INDEX idx_bank_account (bank_account_id),
    INDEX idx_bank_status (status)
);


/* ============================================
   SAMPLE BANK ACCOUNT
   Remove this if you don't want sample data
   ============================================ */

INSERT INTO bank_accounts
(bank_name, account_name, account_number, opening_balance)
SELECT
'Sample Bank',
'Vianchris Trading Corp.',
'',
0
WHERE NOT EXISTS (
    SELECT 1 FROM bank_accounts
);


/* ============================================
   BANK RECONCILIATIONS
   ============================================ */

CREATE TABLE IF NOT EXISTS bank_reconciliations (
    id INT AUTO_INCREMENT PRIMARY KEY,

    bank_account_id INT NOT NULL,
    statement_date DATE NOT NULL,

    statement_balance DECIMAL(15,2) DEFAULT 0,
    outstanding_checks DECIMAL(15,2) DEFAULT 0,
    deposits_in_transit DECIMAL(15,2) DEFAULT 0,

    adjusted_bank_balance DECIMAL(15,2) DEFAULT 0,
    book_balance DECIMAL(15,2) DEFAULT 0,

    difference DECIMAL(15,2) DEFAULT 0,

    status ENUM('Open','Reconciled') DEFAULT 'Open',

    reconciled_by INT DEFAULT NULL,
    reconciled_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_recon_date (statement_date),
    INDEX idx_recon_account (bank_account_id)
);