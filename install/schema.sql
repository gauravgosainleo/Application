-- ============================================================
-- AI Social Media Platform - MySQL Schema
-- Database: u694536902_AIsocialmedia
-- Run once via phpMyAdmin or /install/install.php
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(120) NOT NULL,
  email            VARCHAR(180) NOT NULL UNIQUE,
  phone            VARCHAR(30)  DEFAULT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  profile_picture  VARCHAR(255) DEFAULT NULL,
  bio              TEXT         DEFAULT NULL,
  role             ENUM('user','admin') NOT NULL DEFAULT 'user',
  is_verified      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS otp_codes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(180) NOT NULL,
  code       VARCHAR(10)  NOT NULL,
  purpose    ENUM('register','reset','email_change') NOT NULL DEFAULT 'register',
  expires_at DATETIME NOT NULL,
  used       TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email, purpose, used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(180) NOT NULL,
  token      VARCHAR(128) NOT NULL,
  expires_at DATETIME NOT NULL,
  used       TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED DEFAULT NULL,
  email      VARCHAR(180) NOT NULL,
  action     VARCHAR(80)  NOT NULL,
  status     VARCHAR(40)  NOT NULL,
  ip_address VARCHAR(60)  DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  detail     TEXT         DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (email),
  INDEX (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_posts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id    INT UNSIGNED DEFAULT NULL,
  title       VARCHAR(255) NOT NULL,
  content     MEDIUMTEXT   NOT NULL,
  image       VARCHAR(255) DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  news_id     INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  comment     TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (news_id),
  FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forums (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(255) NOT NULL,
  content     MEDIUMTEXT   NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forum_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  forum_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  comment     TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (forum_id),
  FOREIGN KEY (forum_id) REFERENCES forums(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forum_reactions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  forum_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  reaction    ENUM('like','dislike') NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reaction (forum_id, user_id),
  FOREIGN KEY (forum_id) REFERENCES forums(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_shop_apps (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  app_name          VARCHAR(200) NOT NULL,
  description       MEDIUMTEXT   NOT NULL,
  app_link          VARCHAR(500) DEFAULT NULL,
  comments_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
  status            ENUM('pending_payment','awaiting_approval','approved','rejected') NOT NULL DEFAULT 'pending_payment',
  rejection_reason  VARCHAR(500) DEFAULT NULL,
  payment_id        INT UNSIGNED DEFAULT NULL,
  published_at      DATETIME     DEFAULT NULL,
  expires_at        DATETIME     DEFAULT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_shop_images (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  app_id     INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  FOREIGN KEY (app_id) REFERENCES ai_shop_apps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_shop_comments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  app_id     INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  comment    TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (app_id) REFERENCES ai_shop_apps(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_shop_likes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  app_id     INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_like (app_id, user_id),
  FOREIGN KEY (app_id) REFERENCES ai_shop_apps(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_shop_interests (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  app_id     INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  message    TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (app_id) REFERENCES ai_shop_apps(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NOT NULL,
  app_id              INT UNSIGNED DEFAULT NULL,
  amount              DECIMAL(10,2) NOT NULL,
  currency            VARCHAR(10)  NOT NULL DEFAULT 'INR',
  razorpay_order_id   VARCHAR(100) DEFAULT NULL,
  razorpay_payment_id VARCHAR(100) DEFAULT NULL,
  razorpay_signature  VARCHAR(255) DEFAULT NULL,
  status              ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
  raw_response        TEXT DEFAULT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id       INT UNSIGNED DEFAULT NULL,
  event_name     VARCHAR(200) NOT NULL,
  description    MEDIUMTEXT   NOT NULL,
  link           VARCHAR(500) DEFAULT NULL,
  event_datetime DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (event_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS friend_requests (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id    INT UNSIGNED NOT NULL,
  receiver_id  INT UNSIGNED NOT NULL,
  status       ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pair (sender_id, receiver_id),
  FOREIGN KEY (sender_id)   REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id    INT UNSIGNED NOT NULL,
  receiver_id  INT UNSIGNED NOT NULL,
  message      TEXT NOT NULL,
  read_status  TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (sender_id),
  INDEX (receiver_id),
  FOREIGN KEY (sender_id)   REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
