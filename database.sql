-- ============================================================
-- Smart Bank Locker Management System - Database Schema
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- Import via phpMyAdmin or: mysql -u root -p < database.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `bank_locker_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `bank_locker_db`;

-- -----------------------------------------------
-- Table: users  (admin / staff / customer roles)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `full_name`    VARCHAR(120) NOT NULL,
  `email`        VARCHAR(180) NOT NULL UNIQUE,
  `phone`        VARCHAR(20)  NOT NULL,
  `password`     VARCHAR(255) NOT NULL,
  `role`         ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer',
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `avatar`       VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: customers  (extended KYC info)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL UNIQUE,
  `address`       TEXT,
  `dob`           DATE,
  `id_type`       ENUM('Aadhar','PAN','Passport','Voter ID','Driving Licence') DEFAULT 'Aadhar',
  `id_number`     VARCHAR(60),
  `id_proof_file` VARCHAR(255) DEFAULT NULL,
  `kyc_status`    ENUM('pending','verified','rejected') DEFAULT 'pending',
  `risk_level`    ENUM('low','medium','high') DEFAULT 'low',
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_customer_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: lockers
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `lockers` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `locker_no`   VARCHAR(20) NOT NULL UNIQUE,
  `size`        ENUM('Small','Medium','Large','Extra Large') NOT NULL DEFAULT 'Small',
  `rent_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`      ENUM('Available','Occupied','Maintenance') NOT NULL DEFAULT 'Available',
  `location`    VARCHAR(100) DEFAULT 'Branch A',
  `description` TEXT,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: locker_assignments
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `locker_assignments` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `locker_id`    INT UNSIGNED NOT NULL,
  `customer_id`  INT UNSIGNED NOT NULL,
  `assigned_by`  INT UNSIGNED NOT NULL,          -- staff/admin user id
  `start_date`   DATE NOT NULL,
  `end_date`     DATE DEFAULT NULL,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `notes`        TEXT,
  `locker_password` VARCHAR(20) DEFAULT NULL,    -- From V3 Migration
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_la_locker`   FOREIGN KEY (`locker_id`)   REFERENCES `lockers`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_la_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_staff`    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: payments
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `assignment_id`  INT UNSIGNED NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `base_amount`    DECIMAL(10,2) DEFAULT NULL,   -- From GST Migration
  `tax_percent`    DECIMAL(5,2) DEFAULT NULL,    -- From GST Migration
  `tax_amount`     DECIMAL(10,2) DEFAULT NULL,   -- From GST Migration
  `other_fees`     DECIMAL(10,2) DEFAULT 0.00,   -- From GST Migration
  `plan_type`      ENUM('Monthly', 'Yearly') DEFAULT 'Monthly',
  `payment_date`   DATE NOT NULL,
  `due_date`       DATE NOT NULL,
  `payment_mode`   ENUM('Cash','UPI','NetBanking','Card','Cheque') DEFAULT 'Cash',
  `status`         ENUM('Paid','Pending','Overdue') DEFAULT 'Pending',
  `transaction_id` VARCHAR(80) DEFAULT NULL,
  `invoice_no`     VARCHAR(40) DEFAULT NULL,
  `receipt_file_path` VARCHAR(255) DEFAULT NULL,
  `remarks`        VARCHAR(255) DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_pay_assign` FOREIGN KEY (`assignment_id`) REFERENCES `locker_assignments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: access_logs
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `access_logs` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id`  INT UNSIGNED NOT NULL,
  `locker_id`    INT UNSIGNED NOT NULL,
  `entry_time`   DATETIME NOT NULL,
  `exit_time`    DATETIME DEFAULT NULL,
  `otp_used`     VARCHAR(10) DEFAULT NULL,
  `qr_token_used` VARCHAR(64) DEFAULT NULL,
  `biometric_ok` TINYINT(1) DEFAULT 0,
  `access_status` ENUM('Success','Failed') DEFAULT 'Success',
  `staff_id`     INT UNSIGNED DEFAULT NULL,      -- who granted access
  `notes`        VARCHAR(255) DEFAULT NULL,
  CONSTRAINT `fk_al_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_al_locker`   FOREIGN KEY (`locker_id`)   REFERENCES `lockers`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: otp_requests
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_requests` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `otp`        VARCHAR(10) NOT NULL,
  `purpose`    VARCHAR(60) DEFAULT 'locker_access',
  `is_used`    TINYINT(1) DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: activity_logs
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(200) NOT NULL,
  `module`     VARCHAR(60) DEFAULT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: csrf_tokens  (lightweight CSRF store)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `csrf_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `token`      VARCHAR(64) NOT NULL UNIQUE,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- NEW SCHEMA FOR PHASE 1
-- ============================================================

CREATE TABLE IF NOT EXISTS `locker_plans` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `size`         ENUM('Small','Medium','Large') NOT NULL UNIQUE,
  `monthly_fee`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `yearly_fee`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `locker_requests` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id`  INT UNSIGNED NOT NULL,
  `size`         ENUM('Small','Medium','Large') NOT NULL,
  `plan_type`    ENUM('Monthly','Yearly') NOT NULL,
  `aadhar_file`  VARCHAR(255) DEFAULT NULL,      -- From V2 Migration
  `photo_file`   VARCHAR(255) DEFAULT NULL,      -- From V2 Migration
  `status`       ENUM('Pending','Verified','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `assigned_locker_id` INT UNSIGNED DEFAULT NULL,
  `payment_mode`  ENUM('Online','Cash') DEFAULT NULL, -- From V2 Migration
  `reject_reason` TEXT DEFAULT NULL,                 -- From V2 Migration
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_lr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lr_locker`   FOREIGN KEY (`assigned_locker_id`) REFERENCES `lockers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `qr_tokens` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id`  INT UNSIGNED NOT NULL,
  `locker_id`    INT UNSIGNED NOT NULL,          -- From V4 Migration
  `token`        VARCHAR(64) NOT NULL UNIQUE,
  `expires_at`   DATETIME NOT NULL,
  `is_used`      TINYINT(1) DEFAULT 0,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_qr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qr_locker`   FOREIGN KEY (`locker_id`)   REFERENCES `lockers`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: notifications  (From V5 Migration)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SAMPLE DATA
-- ============================================================

-- Admin user  (password: Admin@123)
INSERT INTO `users` (`full_name`,`email`,`phone`,`password`,`role`) VALUES
('Super Admin','admin@banklocker.com','9000000001',
 '$2y$12$Kk1lnAIjVD9iWTJ2wHx0dOAFcEWoNb9Bb4Q8VaaCmzPFqGm9bRMWW','admin');

-- Staff user  (password: Staff@123)
INSERT INTO `users` (`full_name`,`email`,`phone`,`password`,`role`) VALUES
('Staff Member','staff@banklocker.com','9000000002',
 '$2y$12$7dfOjGdnXnRjXtT6SuwW2.O9m2LPQM7Z/VBRs9SYsLiJ/LzlJQS0O','staff');

-- Sample customers  (password: Cust@123)
INSERT INTO `users` (`full_name`,`email`,`phone`,`password`,`role`) VALUES
('Rohan Mehta',  'rohan@example.com','9111111111','$2y$12$8PKYKdPz5K5K5FNCMK6oJuIv6z3k7z7z7z7z7z7z7z7z7z7z7z7zC','customer'),
('Priya Sharma', 'priya@example.com','9222222222','$2y$12$8PKYKdPz5K5K5FNCMK6oJuIv6z3k7z7z7z7z7z7z7z7z7z7z7z7zC','customer'),
('Anil Verma',   'anil@example.com', '9333333333','$2y$12$8PKYKdPz5K5K5FNCMK6oJuIv6z3k7z7z7z7z7z7z7z7z7z7z7z7zC','customer');

INSERT INTO `customers` (`user_id`,`address`,`dob`,`id_type`,`id_number`,`kyc_status`,`risk_level`) VALUES
(3,'123 MG Road, Mumbai','1990-05-14','Aadhar','2345 6789 0123','verified','low'),
(4,'45 Green Park, Delhi','1985-11-22','PAN','ABCDE1234F','verified','medium'),
(5,'7 Lake View, Chennai','1995-03-08','Passport','P1234567','pending','low');

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES 
('emergency_global_lock', '0'),
('max_lockers_per_customer', '5'),
('enable_cash_payment', '1'),
('rent_small_monthly', '500'),
('rent_small_yearly', '5000'),
('rent_medium_monthly', '900'),
('rent_medium_yearly', '9500'),
('rent_large_monthly', '1500'),
('rent_large_yearly', '16000'),
('enable_dark_mode_default', '1');

INSERT IGNORE INTO `locker_plans` (`size`, `monthly_fee`, `yearly_fee`) VALUES 
('Small', 500.00, 5000.00),
('Medium', 900.00, 9500.00),
('Large', 1500.00, 16000.00);

-- Sample lockers
INSERT INTO `lockers` (`locker_no`,`size`,`rent_amount`,`status`,`location`) VALUES
('L-001','Small', 500.00,'Occupied',   'Branch A'),
('L-002','Small', 500.00,'Available',  'Branch A'),
('L-003','Medium',900.00,'Occupied',   'Branch A'),
('L-004','Medium',900.00,'Available',  'Branch B'),
('L-005','Large',1500.00,'Maintenance','Branch A'),
('L-006','Large',1500.00,'Available',  'Branch B'),
('L-007','Small', 500.00,'Available',  'Branch B'),
('L-008','Medium',900.00,'Available',  'Branch A');

-- Assignments
INSERT INTO `locker_assignments` (`locker_id`,`customer_id`,`assigned_by`,`start_date`,`is_active`) VALUES
(1,1,1,'2025-01-01',1),
(3,2,1,'2025-03-01',1);

-- Payments
INSERT INTO `payments` (`assignment_id`,`amount`,`payment_date`,`due_date`,`payment_mode`,`status`,`invoice_no`) VALUES
(1,500.00,'2025-01-01','2025-02-01','Cash','Paid','INV-0001'),
(1,500.00,'2025-02-01','2025-03-01','UPI', 'Paid','INV-0002'),
(1,500.00,'2025-03-01','2025-04-01','Cash','Pending','INV-0003'),
(2,900.00,'2025-03-01','2025-04-01','NetBanking','Paid','INV-0004');

-- ============================================================
-- END OF SCHEMA
-- ============================================================
