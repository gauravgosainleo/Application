-- =====================================================================
--  G VI Fan Hub - database schema (MySQL 5.7+ / MariaDB 10.3+)
--  All DATETIME columns are stored in UTC.
--  install.php runs this file automatically. You can also import it
--  manually from phpMyAdmin (Hostinger hPanel -> Databases -> phpMyAdmin).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(64)  NOT NULL PRIMARY KEY,
  `value`     MEDIUMTEXT   NULL,
  updated_at  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(80)  NOT NULL DEFAULT 'Administrator',
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rewards (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(120) NOT NULL,
  description    VARCHAR(500) NULL,
  reward_type    VARCHAR(10)  NOT NULL DEFAULT 'badge',   -- badge | title | frame | code
  icon           VARCHAR(700) NULL,                       -- emoji or uploads/... path
  style          VARCHAR(30)  NULL,                       -- frame / badge colour style
  required_level INT UNSIGNED NOT NULL DEFAULT 1,
  secret_code    VARCHAR(120) NULL,                       -- revealed after claiming (type=code)
  quantity       INT UNSIGNED NULL,                       -- NULL = unlimited
  claimed_count  INT UNSIGNED NOT NULL DEFAULT 0,
  is_event_only  TINYINT(1)   NOT NULL DEFAULT 0,         -- only granted through events
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  expires_at     DATETIME     NULL,
  created_at     DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(80)  NOT NULL,
  username          VARCHAR(30)  NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  email_verified_at DATETIME     NULL,
  avatar            VARCHAR(255) NULL,
  cover             VARCHAR(255) NULL,
  bio               VARCHAR(300) NULL,
  platform          VARCHAR(30)  NULL,
  fav_character     VARCHAR(30)  NULL,
  country           VARCHAR(60)  NULL,
  rockstar_id       VARCHAR(40)  NULL,
  psn_id            VARCHAR(40)  NULL,
  xbox_id           VARCHAR(40)  NULL,
  presence          VARCHAR(12)  NOT NULL DEFAULT 'online', -- online | away | busy | invisible
  presence_text     VARCHAR(80)  NULL,
  xp                INT UNSIGNED NOT NULL DEFAULT 0,
  equipped_title_id INT UNSIGNED NULL,
  equipped_frame_id INT UNSIGNED NULL,
  login_otp_enabled TINYINT(1)   NOT NULL DEFAULT 0,
  dm_privacy        VARCHAR(10)  NOT NULL DEFAULT 'friends', -- everyone | friends
  profile_privacy   VARCHAR(10)  NOT NULL DEFAULT 'public',  -- public | friends
  theme             VARCHAR(20)  NOT NULL DEFAULT 'vice',
  banned_until      DATETIME     NULL,
  ban_reason        VARCHAR(255) NULL,
  login_streak      INT UNSIGNED NOT NULL DEFAULT 0,
  last_streak_date  DATE         NULL,
  last_seen_at      DATETIME     NULL,
  last_login_at     DATETIME     NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NULL,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subject_type VARCHAR(10)  NOT NULL,                     -- user | admin
  subject_id   INT UNSIGNED NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  remember     TINYINT(1)   NOT NULL DEFAULT 0,
  user_agent   VARCHAR(255) NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME     NOT NULL,
  last_used_at DATETIME     NOT NULL,
  expires_at   DATETIME     NOT NULL,
  UNIQUE KEY uq_session_token (token_hash),
  KEY idx_session_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_otps (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(190) NOT NULL,
  purpose     VARCHAR(12)  NOT NULL,                      -- register | reset | login
  code_hash   VARCHAR(255) NOT NULL,
  ref_token   CHAR(64)     NULL,                          -- pending-login token hash
  user_id     INT UNSIGNED NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  consumed_at DATETIME     NULL,
  KEY idx_otp_email (email, purpose, created_at),
  KEY idx_otp_ref (ref_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key       VARCHAR(190) NOT NULL PRIMARY KEY,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_bans (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  admin_id     INT UNSIGNED NULL,
  reason       VARCHAR(255) NOT NULL,
  days         INT UNSIGNED NULL,                         -- NULL = permanent
  context      VARCHAR(30)  NULL,                         -- general | discussion | comment ...
  context_ref  VARCHAR(160) NULL,
  banned_until DATETIME     NOT NULL,
  created_at   DATETIME     NOT NULL,
  lifted_at    DATETIME     NULL,
  KEY idx_bans_user (user_id),
  CONSTRAINT fk_bans_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- news
CREATE TABLE IF NOT EXISTS news_sources (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL,
  feed_url        VARCHAR(500) NOT NULL,
  site_url        VARCHAR(255) NULL,
  enabled         TINYINT(1)   NOT NULL DEFAULT 1,
  last_fetched_at DATETIME     NULL,
  last_status     VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title                 VARCHAR(255) NOT NULL,
  summary               TEXT         NULL,
  content               MEDIUMTEXT   NULL,
  image_url             VARCHAR(700) NULL,
  source_name           VARCHAR(120) NOT NULL,
  source_url            VARCHAR(1000) NULL,
  origin                VARCHAR(10)  NOT NULL DEFAULT 'manual', -- manual | auto
  status                VARCHAR(12)  NOT NULL DEFAULT 'pending', -- pending | published | rejected
  news_source_id        INT UNSIGNED NULL,
  external_hash         CHAR(40)     NULL,
  title_hash            CHAR(40)     NULL,
  external_published_at DATETIME     NULL,
  is_featured           TINYINT(1)   NOT NULL DEFAULT 0,
  views                 INT UNSIGNED NOT NULL DEFAULT 0,
  created_by            INT UNSIGNED NULL,
  reviewed_by           INT UNSIGNED NULL,
  reviewed_at           DATETIME     NULL,
  published_at          DATETIME     NULL,
  created_at            DATETIME     NOT NULL,
  updated_at            DATETIME     NULL,
  UNIQUE KEY uq_news_external (external_hash),
  KEY idx_news_status (status, published_at),
  KEY idx_news_title (title_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- trailers
CREATE TABLE IF NOT EXISTS trailers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200) NOT NULL,
  description   TEXT         NULL,
  youtube_id    VARCHAR(20)  NULL,
  video_url     VARCHAR(700) NULL,
  thumbnail_url VARCHAR(700) NULL,
  released_on   DATE         NULL,
  is_featured   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------- interactive map
CREATE TABLE IF NOT EXISTS map_markers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(100) NOT NULL,
  note       TEXT         NULL,
  category   VARCHAR(20)  NOT NULL DEFAULT 'destination',
  pos_x      DECIMAL(9,6) NOT NULL,                      -- 0..1 fraction of map width
  pos_y      DECIMAL(9,6) NOT NULL,                      -- 0..1 fraction of map height (from top)
  visibility VARCHAR(10)  NOT NULL DEFAULT 'private',     -- private | friends | public
  is_done    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL,
  updated_at DATETIME     NULL,
  KEY idx_markers_user (user_id),
  KEY idx_markers_visibility (visibility),
  CONSTRAINT fk_markers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------- friends/chat
CREATE TABLE IF NOT EXISTS friendships (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  requester_id INT UNSIGNED NOT NULL,
  addressee_id INT UNSIGNED NOT NULL,
  pair_key     VARCHAR(30)  NOT NULL,                     -- "<lowId>:<highId>"
  status       VARCHAR(10)  NOT NULL DEFAULT 'pending',   -- pending | accepted
  created_at   DATETIME     NOT NULL,
  responded_at DATETIME     NULL,
  UNIQUE KEY uq_friend_pair (pair_key),
  KEY idx_friend_addressee (addressee_id, status),
  KEY idx_friend_requester (requester_id, status),
  CONSTRAINT fk_friend_req FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_friend_add FOREIGN KEY (addressee_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_blocks (
  blocker_id INT UNSIGNED NOT NULL,
  blocked_id INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (blocker_id, blocked_id),
  CONSTRAINT fk_block_a FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_block_b FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type            VARCHAR(8)   NOT NULL,                  -- direct | group
  name            VARCHAR(80)  NULL,
  avatar          VARCHAR(255) NULL,
  direct_key      VARCHAR(30)  NULL,
  created_by      INT UNSIGNED NULL,
  last_message_id BIGINT UNSIGNED NULL,
  last_message_at DATETIME     NULL,
  created_at      DATETIME     NOT NULL,
  UNIQUE KEY uq_conv_direct (direct_key),
  KEY idx_conv_last (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_members (
  conversation_id INT UNSIGNED    NOT NULL,
  user_id         INT UNSIGNED    NOT NULL,
  role            VARCHAR(8)      NOT NULL DEFAULT 'member', -- owner | admin | member
  last_read_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  joined_at       DATETIME        NOT NULL,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_cm_user (user_id),
  CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED    NOT NULL,
  user_id         INT UNSIGNED    NULL,
  kind            VARCHAR(8)      NOT NULL DEFAULT 'text', -- text | image | system
  body            TEXT            NULL,
  attachment      VARCHAR(255)    NULL,
  created_at      DATETIME        NOT NULL,
  deleted_at      DATETIME        NULL,
  KEY idx_msg_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- status
CREATE TABLE IF NOT EXISTS statuses (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  kind       VARCHAR(8)   NOT NULL DEFAULT 'text',        -- text | image
  text_body  VARCHAR(500) NULL,
  bg         VARCHAR(20)  NULL,
  image      VARCHAR(255) NULL,
  visibility VARCHAR(10)  NOT NULL DEFAULT 'friends',     -- friends | public
  created_at DATETIME     NOT NULL,
  expires_at DATETIME     NOT NULL,
  KEY idx_status_user (user_id, expires_at),
  KEY idx_status_expiry (expires_at),
  CONSTRAINT fk_status_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_views (
  status_id INT UNSIGNED NOT NULL,
  viewer_id INT UNSIGNED NOT NULL,
  viewed_at DATETIME     NOT NULL,
  PRIMARY KEY (status_id, viewer_id),
  CONSTRAINT fk_sv_status FOREIGN KEY (status_id) REFERENCES statuses (id) ON DELETE CASCADE,
  CONSTRAINT fk_sv_user FOREIGN KEY (viewer_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ discussions
CREATE TABLE IF NOT EXISTS discussions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  title            VARCHAR(200) NOT NULL,
  body             MEDIUMTEXT   NOT NULL,
  category         VARCHAR(30)  NOT NULL DEFAULT 'General',
  cover_image      VARCHAR(255) NULL,
  likes_count      INT UNSIGNED NOT NULL DEFAULT 0,
  comments_count   INT UNSIGNED NOT NULL DEFAULT 0,
  views            INT UNSIGNED NOT NULL DEFAULT 0,
  is_pinned        TINYINT(1)   NOT NULL DEFAULT 0,
  is_locked        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL,
  updated_at       DATETIME     NULL,
  last_activity_at DATETIME     NOT NULL,
  KEY idx_disc_created (created_at),
  KEY idx_disc_category (category),
  KEY idx_disc_user (user_id),
  CONSTRAINT fk_disc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discussion_comments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discussion_id INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  parent_id     INT UNSIGNED NULL,
  body          TEXT         NOT NULL,
  likes_count   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL,
  KEY idx_dc_discussion (discussion_id, created_at),
  CONSTRAINT fk_dc_disc FOREIGN KEY (discussion_id) REFERENCES discussions (id) ON DELETE CASCADE,
  CONSTRAINT fk_dc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_dc_parent FOREIGN KEY (parent_id) REFERENCES discussion_comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discussion_likes (
  discussion_id INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (discussion_id, user_id),
  CONSTRAINT fk_dl_disc FOREIGN KEY (discussion_id) REFERENCES discussions (id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_likes (
  comment_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (comment_id, user_id),
  CONSTRAINT fk_cl_comment FOREIGN KEY (comment_id) REFERENCES discussion_comments (id) ON DELETE CASCADE,
  CONSTRAINT fk_cl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT UNSIGNED NULL,
  target_type VARCHAR(12)  NOT NULL,                      -- discussion | comment | user | status | gallery | message
  target_id   INT UNSIGNED NOT NULL,
  reason      VARCHAR(255) NOT NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'open',       -- open | resolved | dismissed
  created_at  DATETIME     NOT NULL,
  resolved_at DATETIME     NULL,
  resolved_by INT UNSIGNED NULL,
  KEY idx_reports_status (status, created_at),
  CONSTRAINT fk_reports_user FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- catalogue
CREATE TABLE IF NOT EXISTS catalogue_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  slug        VARCHAR(70)  NOT NULL,
  icon        VARCHAR(16)  NULL,
  description VARCHAR(255) NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogue_items (
  id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id  INT UNSIGNED    NOT NULL,
  name         VARCHAR(120)    NOT NULL,
  subtype      VARCHAR(60)     NULL,
  manufacturer VARCHAR(60)     NULL,
  description  TEXT            NULL,
  image        VARCHAR(700)    NULL,
  price        BIGINT UNSIGNED NULL,
  status       VARCHAR(30)     NOT NULL DEFAULT 'Confirmed',
  stats        TEXT            NULL,                       -- JSON [{"label":"Speed","value":80}]
  sort_order   INT             NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL,
  updated_at   DATETIME        NULL,
  KEY idx_items_category (category_id),
  CONSTRAINT fk_items_cat FOREIGN KEY (category_id) REFERENCES catalogue_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogue_favorites (
  item_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (item_id, user_id),
  KEY idx_fav_user (user_id),
  CONSTRAINT fk_fav_item FOREIGN KEY (item_id) REFERENCES catalogue_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- gallery
CREATE TABLE IF NOT EXISTS gallery_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,                          -- NULL = posted by admin
  is_official TINYINT(1)   NOT NULL DEFAULT 0,
  media_type  VARCHAR(8)   NOT NULL,                      -- photo | video
  title       VARCHAR(150) NULL,
  caption     VARCHAR(500) NULL,
  file_path   VARCHAR(255) NULL,
  video_url   VARCHAR(700) NULL,
  youtube_id  VARCHAR(20)  NULL,
  thumbnail   VARCHAR(700) NULL,
  status      VARCHAR(10)  NOT NULL DEFAULT 'approved',   -- pending | approved | rejected
  likes_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  KEY idx_gallery_status (status, created_at),
  CONSTRAINT fk_gallery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery_likes (
  item_id    INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (item_id, user_id),
  CONSTRAINT fk_gl_item FOREIGN KEY (item_id) REFERENCES gallery_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_gl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ polls
CREATE TABLE IF NOT EXISTS polls (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question           VARCHAR(255) NOT NULL,
  description        TEXT         NULL,
  is_multiple        TINYINT(1)   NOT NULL DEFAULT 0,
  results_visibility VARCHAR(12)  NOT NULL DEFAULT 'after_vote', -- always | after_vote | after_close
  status             VARCHAR(8)   NOT NULL DEFAULT 'open',       -- open | closed
  ends_at            DATETIME     NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_options (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  poll_id     INT UNSIGNED NOT NULL,
  label       VARCHAR(150) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  votes_count INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_po_poll (poll_id),
  CONSTRAINT fk_po_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_votes (
  poll_id    INT UNSIGNED NOT NULL,
  option_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (poll_id, option_id, user_id),
  KEY idx_pv_user (user_id, poll_id),
  CONSTRAINT fk_pv_poll FOREIGN KEY (poll_id) REFERENCES polls (id) ON DELETE CASCADE,
  CONSTRAINT fk_pv_option FOREIGN KEY (option_id) REFERENCES poll_options (id) ON DELETE CASCADE,
  CONSTRAINT fk_pv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------- events/rewards
CREATE TABLE IF NOT EXISTS events (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(150) NOT NULL,
  description      TEXT         NULL,
  event_type       VARCHAR(30)  NOT NULL DEFAULT 'Community',
  banner           VARCHAR(700) NULL,
  location         VARCHAR(150) NULL,
  starts_at        DATETIME     NOT NULL,
  ends_at          DATETIME     NULL,
  reward_xp        INT UNSIGNED NOT NULL DEFAULT 0,
  reward_id        INT UNSIGNED NULL,
  max_participants INT UNSIGNED NULL,
  is_published     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL,
  KEY idx_events_start (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_participants (
  event_id          INT UNSIGNED NOT NULL,
  user_id           INT UNSIGNED NOT NULL,
  joined_at         DATETIME     NOT NULL,
  reward_claimed_at DATETIME     NULL,
  PRIMARY KEY (event_id, user_id),
  KEY idx_ep_user (user_id),
  CONSTRAINT fk_ep_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_ep_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_rewards (
  user_id    INT UNSIGNED NOT NULL,
  reward_id  INT UNSIGNED NOT NULL,
  claimed_at DATETIME     NOT NULL,
  source     VARCHAR(30)  NULL,                           -- claim | event:<id> | admin
  PRIMARY KEY (user_id, reward_id),
  KEY idx_ur_reward (reward_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_reward FOREIGN KEY (reward_id) REFERENCES rewards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xp_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  amount     INT          NOT NULL,
  reason     VARCHAR(30)  NOT NULL,
  ref_id     VARCHAR(40)  NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL,
  UNIQUE KEY uq_xp_once (user_id, reason, ref_id),
  KEY idx_xp_user (user_id, created_at),
  CONSTRAINT fk_xp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------- notifications
CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED    NOT NULL,
  type       VARCHAR(30)     NOT NULL,
  title      VARCHAR(160)    NOT NULL,
  body       VARCHAR(300)    NULL,
  link       VARCHAR(255)    NULL,
  actor_id   INT UNSIGNED    NULL,
  is_read    TINYINT(1)      NOT NULL DEFAULT 0,
  created_at DATETIME        NOT NULL,
  KEY idx_notif_user (user_id, is_read, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
