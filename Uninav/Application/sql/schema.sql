-- =====================================================================
-- Uninav Society Management - Database Schema
-- Database: u694536902_uninav
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------
-- Users (residents + admin). Admin is seeded.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(100) NOT NULL UNIQUE,
    tower           VARCHAR(2)   NULL,
    house_number    VARCHAR(20)  NULL,
    owner_name      VARCHAR(150) NULL,
    email           VARCHAR(150) NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','resident','guest') NOT NULL DEFAULT 'resident',
    email_verified  TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('pending','active','deleted') NOT NULL DEFAULT 'pending',
    ad_restricted   TINYINT(1) NOT NULL DEFAULT 0,
    photo           VARCHAR(255) NULL,
    phone           VARCHAR(25)  NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (tower, house_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- OTP and password reset tokens
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS otp_codes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(150) NOT NULL,
    otp         VARCHAR(10)  NOT NULL,
    purpose     ENUM('register','reset','change_email') NOT NULL DEFAULT 'register',
    expires_at  DATETIME NOT NULL,
    used        TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (email), INDEX (otp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(100) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    used        TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- User flags (resident-reported "not my family member")
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_flags (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    flagged_user_id INT NOT NULL,
    flagged_by      INT NOT NULL,
    reason          VARCHAR(500) NULL,
    status          ENUM('open','cleared','user_deleted') NOT NULL DEFAULT 'open',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME NULL,
    resolved_by     INT NULL,
    INDEX (flagged_user_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Events / Calendar
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200) NOT NULL,
    description TEXT NULL,
    event_date  DATE NOT NULL,
    event_time  TIME NULL,
    location    VARCHAR(200) NULL,
    image       VARCHAR(255) NULL,
    created_by  INT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Notices
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notices (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200) NOT NULL,
    body        TEXT NOT NULL,
    image       VARCHAR(255) NULL,
    posted_on   DATE NOT NULL,
    created_by  INT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Complaints + history + comments
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS complaints (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    subject      VARCHAR(200) NOT NULL,
    description  TEXT NOT NULL,
    image        VARCHAR(255) NULL,
    related_type VARCHAR(50) NULL,
    related_id   INT NULL,
    status       ENUM('Complaint Raised','Work In Progress','Completed','Ignored') NOT NULL DEFAULT 'Complaint Raised',
    ignore_reason TEXT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS complaint_comments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    admin_id     INT NULL,
    comment      TEXT NOT NULL,
    image        VARCHAR(255) NULL,
    status_change VARCHAR(50) NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Finance
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS finance_months (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    month_year     VARCHAR(7) NOT NULL UNIQUE, -- e.g. 2026-04
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    monthly_income DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finance_expenses (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    month_year  VARCHAR(7) NOT NULL,
    expense_date DATE NOT NULL,
    description VARCHAR(250) NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (month_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Third-party Vendors (maids, press, security, housekeeping, others)
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vendors (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    category       ENUM('maid','press','security','housekeeping','other') NOT NULL,
    name           VARCHAR(150) NOT NULL,
    photo          VARCHAR(255) NULL,
    police_verified TINYINT(1) NOT NULL DEFAULT 0,
    contact_number VARCHAR(30) NULL,
    status         ENUM('Available','DND') NOT NULL DEFAULT 'Available',
    notes          VARCHAR(250) NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Many-to-many: which houses a vendor is associated with
CREATE TABLE IF NOT EXISTS vendor_houses (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id    INT NOT NULL,
    tower        VARCHAR(2) NOT NULL,
    house_number VARCHAR(20) NOT NULL,
    INDEX (vendor_id), INDEX (tower, house_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Advertisements
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS advertisements (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    title         VARCHAR(200) NOT NULL,
    description   TEXT NOT NULL,
    photo         VARCHAR(255) NULL,
    price         DECIMAL(10,2) NULL,
    contact_name  VARCHAR(150) NOT NULL,
    contact_phone VARCHAR(25) NOT NULL,
    status        ENUM('active','hidden') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Polls
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS polls (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    question    VARCHAR(300) NOT NULL,
    description TEXT NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_options (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    poll_id     INT NOT NULL,
    option_text VARCHAR(250) NOT NULL,
    INDEX(poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_votes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    poll_id     INT NOT NULL,
    option_id   INT NOT NULL,
    user_id     INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vote (poll_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------------
-- Seed admin + guest (passwords hashed at install time)
-- NOTE: install.php replaces the placeholders with real password_hash values.
-- -------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 1;
