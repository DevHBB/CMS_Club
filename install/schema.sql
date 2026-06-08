-- ============================================================
-- ClubCMS - Schéma de base de données COMPLET
-- Compatible : MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Configuration ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_config` (
  `key`        varchar(100) NOT NULL,
  `value`      text,
  `group`      varchar(50) DEFAULT 'general',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Utilisateurs ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_users` (
  `id`                       int(11) NOT NULL AUTO_INCREMENT,
  `email`                    varchar(191) NOT NULL,
  `password`                 varchar(255) NOT NULL,
  `role`                     varchar(20) NOT NULL DEFAULT 'member',
  `status`                   enum('pending','active','suspended','banned') NOT NULL DEFAULT 'pending',
  `firstname`                varchar(100) DEFAULT NULL,
  `lastname`                 varchar(100) DEFAULT NULL,
  `phone`                    varchar(30) DEFAULT NULL,
  `birthdate`                date DEFAULT NULL,
  `avatar`                   varchar(255) DEFAULT NULL,
  `address`                  text DEFAULT NULL,
  `city`                     varchar(100) DEFAULT NULL,
  `zip`                      varchar(20) DEFAULT NULL,
  `country`                  varchar(100) DEFAULT 'France',
  `license_number`           varchar(100) DEFAULT NULL,
  `license_file`             varchar(255) DEFAULT NULL,
  `license_expiry`           date DEFAULT NULL,
  `license_status`           enum('none','pending','valid','expired','rejected') DEFAULT 'none',
  `member_card_hash`         varchar(64) DEFAULT NULL,
  `member_card_generated_at` datetime DEFAULT NULL,
  `email_verified`           tinyint(1) DEFAULT 0,
  `email_token`              varchar(64) DEFAULT NULL,
  `reset_token`              varchar(64) DEFAULT NULL,
  `reset_expires`            datetime DEFAULT NULL,
  `last_login`               datetime DEFAULT NULL,
  `created_at`               datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Modules ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_modules` (
  `slug`          varchar(50) NOT NULL,
  `label`         varchar(100) NOT NULL,
  `enabled`       tinyint(1) DEFAULT 1,
  `require_login` tinyint(1) DEFAULT 0,
  `settings`      json DEFAULT NULL,
  PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Pages & Articles ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_articles` (
  `id`            int(11) NOT NULL AUTO_INCREMENT,
  `user_id`       int(11) NOT NULL,
  `type`          enum('article','page') DEFAULT 'article',
  `title`         varchar(255) NOT NULL,
  `slug`          varchar(255) NOT NULL,
  `excerpt`       text DEFAULT NULL,
  `content`       longtext NOT NULL,
  `cover`         varchar(255) DEFAULT NULL,
  `published`     tinyint(1) DEFAULT 0,
  `require_login` tinyint(1) DEFAULT 0,
  `created_at`    datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Menu ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_menu` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `label`      varchar(100) NOT NULL,
  `url`        varchar(255) NOT NULL,
  `parent_id`  int(11) DEFAULT NULL,
  `order`      int(11) DEFAULT 0,
  `target`     varchar(10) DEFAULT '_self',
  `access_mode` varchar(20) DEFAULT 'public',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Forum ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_forum_categories` (
  `id`            int(11) NOT NULL AUTO_INCREMENT,
  `name`          varchar(150) NOT NULL,
  `slug`          varchar(150) NOT NULL,
  `description`   text DEFAULT NULL,
  `icon`          varchar(50) DEFAULT NULL,
  `order`         int(11) DEFAULT 0,
  `require_login` tinyint(1) DEFAULT 1,
  `created_at`    datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_forum_topics` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `user_id`     int(11) NOT NULL,
  `title`       varchar(255) NOT NULL,
  `slug`        varchar(255) NOT NULL,
  `pinned`      tinyint(1) DEFAULT 0,
  `locked`      tinyint(1) DEFAULT 0,
  `read_mode`   varchar(20) DEFAULT 'members',
  `reply_mode`  varchar(20) DEFAULT 'members',
  `views`       int(11) DEFAULT 0,
  `created_at`  datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_forum_posts` (
  `id`           int(11) NOT NULL AUTO_INCREMENT,
  `topic_id`     int(11) NOT NULL,
  `user_id`      int(11) NOT NULL,
  `content`      text NOT NULL,
  `is_first_post` tinyint(1) DEFAULT 0,
  `edited_at`    datetime DEFAULT NULL,
  `created_at`   datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `topic_id` (`topic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Boutique ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_shop_categories` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `name`        varchar(150) NOT NULL,
  `slug`        varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `color`       varchar(7) DEFAULT '#6366f1',
  `icon`        varchar(10) DEFAULT NULL,
  `order`       int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_shop_products` (
  `id`             int(11) NOT NULL AUTO_INCREMENT,
  `category_id`    int(11) DEFAULT NULL,
  `name`           varchar(255) NOT NULL,
  `slug`           varchar(255) NOT NULL,
  `description`    text DEFAULT NULL,
  `price`          decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock`          int(11) DEFAULT -1,
  `images`         json DEFAULT NULL,
  `variants`       json DEFAULT NULL,
  `published`      tinyint(1) DEFAULT 1,
  `delivery_mode`  varchar(20) DEFAULT 'both',
  `shipping_price` decimal(10,2) DEFAULT 0.00,
  `pickup_info`    varchar(255) DEFAULT NULL,
  `created_at`     datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_shop_orders` (
  `id`               int(11) NOT NULL AUTO_INCREMENT,
  `user_id`          int(11) DEFAULT NULL,
  `status`           enum('pending','paid','shipped','cancelled','refunded') DEFAULT 'pending',
  `payment_method`   varchar(20) DEFAULT 'offline',
  `payment_id`       varchar(255) DEFAULT NULL,
  `total`            decimal(10,2) NOT NULL DEFAULT 0.00,
  `items`            json NOT NULL,
  `shipping_address` json DEFAULT NULL,
  `notes`            text DEFAULT NULL,
  `created_at`       datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Galerie ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_gallery_folders` (
  `id`            int(11) NOT NULL AUTO_INCREMENT,
  `parent_id`     int(11) DEFAULT NULL,
  `name`          varchar(150) NOT NULL,
  `slug`          varchar(150) NOT NULL,
  `description`   text DEFAULT NULL,
  `cover`         varchar(255) DEFAULT NULL,
  `require_login` tinyint(1) DEFAULT 0,
  `order`         int(11) DEFAULT 0,
  `created_at`    datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_gallery_photos` (
  `id`        int(11) NOT NULL AUTO_INCREMENT,
  `folder_id` int(11) NOT NULL,
  `user_id`   int(11) DEFAULT NULL,
  `filename`  varchar(255) NOT NULL,
  `title`     varchar(255) DEFAULT NULL,
  `caption`   text DEFAULT NULL,
  `order`     int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Planning ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_planning_types` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `slug`       varchar(50) NOT NULL,
  `label`      varchar(100) NOT NULL,
  `color`      varchar(7) NOT NULL DEFAULT '#6366f1',
  `is_system`  tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_planning_slots` (
  `id`                 int(11) NOT NULL AUTO_INCREMENT,
  `title`              varchar(255) NOT NULL,
  `type`               varchar(50) NOT NULL DEFAULT 'open',
  `coach_id`           int(11) DEFAULT NULL,
  `description`        text DEFAULT NULL,
  `date_start`         datetime NOT NULL,
  `date_end`           datetime NOT NULL,
  `recurrence`         varchar(20) DEFAULT 'none',
  `recurrence_end`     date DEFAULT NULL,
  `max_participants`   int(11) DEFAULT NULL,
  `require_booking`    tinyint(1) DEFAULT 0,
  `booking_form`       varchar(20) DEFAULT 'internal',
  `external_url`       varchar(500) DEFAULT NULL,
  `custom_form_fields` json DEFAULT NULL,
  `color`              varchar(7) DEFAULT '#3b82f6',
  `published`          tinyint(1) DEFAULT 1,
  `members_only`       tinyint(1) DEFAULT 0 COMMENT 'Invisible aux visiteurs non connectés',
  `members_message`    tinyint(1) DEFAULT 0 COMMENT 'Visible mais message aux non-membres',
  `members_msg_text`   varchar(500) DEFAULT 'Il faut être membre pour participer.',
  `criteria_ids`       text DEFAULT NULL,
  `criteria_required`  text DEFAULT NULL,
  `booking_mode`       varchar(20) DEFAULT 'auto',
  `created_at`         datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_planning_bookings` (
  `id`             int(11) NOT NULL AUTO_INCREMENT,
  `slot_id`        int(11) NOT NULL,
  `user_id`        int(11) DEFAULT NULL,
  `guest_name`     varchar(150) DEFAULT NULL,
  `guest_email`    varchar(191) DEFAULT NULL,
  `status`         varchar(20) DEFAULT 'confirmed',
  `is_waitlist`    tinyint(1) NOT NULL DEFAULT 0,
  `form_data`      json DEFAULT NULL,
  `criteria_data`  text DEFAULT NULL,
  `created_at`     datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `slot_id` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_planning_criteria` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL,
  `field_type`  varchar(20) NOT NULL DEFAULT 'text',
  `options`     text DEFAULT NULL,
  `use_color`   tinyint(1) NOT NULL DEFAULT 0,
  `color`       varchar(7) NOT NULL DEFAULT '#6366f1',
  `range_min`   int(11) DEFAULT NULL,
  `range_max`   int(11) DEFAULT NULL,
  `range_unit`  varchar(30) NOT NULL DEFAULT '',
  `required`    tinyint(1) NOT NULL DEFAULT 1,
  `allow_other` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order`  int(11) NOT NULL DEFAULT 0,
  `active`      tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_planning_criteria_values` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `user_id`     int(11) NOT NULL,
  `criteria_id` int(11) NOT NULL,
  `value`       varchar(255) NOT NULL DEFAULT '',
  `value2`      varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_crit` (`user_id`,`criteria_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Mail queue ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_mail_queue` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `to_email`   varchar(191) NOT NULL,
  `to_name`    varchar(150) DEFAULT NULL,
  `subject`    varchar(255) NOT NULL,
  `body_html`  text NOT NULL,
  `status`     enum('pending','sent','failed') DEFAULT 'pending',
  `attempts`   int(11) DEFAULT 0,
  `sent_at`    datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Newsletter ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_newsletter_subscribers` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `email`      varchar(191) NOT NULL,
  `firstname`  varchar(100) DEFAULT NULL,
  `active`     tinyint(1) DEFAULT 1,
  `token`      varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_newsletter_campaigns` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `subject`    varchar(255) NOT NULL,
  `body`       text NOT NULL,
  `sent_at`    datetime DEFAULT NULL,
  `sent_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Bénévoles ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cc_benv_events` (
  `id`             int(11) NOT NULL AUTO_INCREMENT,
  `title`          varchar(200) NOT NULL,
  `description`    text,
  `location`       varchar(200) DEFAULT NULL,
  `date_start`     datetime NOT NULL,
  `date_end`       datetime DEFAULT NULL,
  `max_volunteers` int(11) DEFAULT 0,
  `created_by`     int(11) NOT NULL,
  `recurring`      varchar(20) DEFAULT 'none',
  `color`          varchar(7) DEFAULT '#6366f1',
  `created_at`     datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_participations` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `event_id`   int(11) NOT NULL,
  `user_id`    int(11) NOT NULL,
  `status`     varchar(20) DEFAULT 'confirmed',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evt_user` (`event_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_tasks` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `title`       varchar(200) NOT NULL,
  `description` text,
  `status`      varchar(20) DEFAULT 'todo',
  `priority`    varchar(10) DEFAULT 'normal',
  `assigned_to` int(11) DEFAULT NULL,
  `due_date`    date DEFAULT NULL,
  `created_by`  int(11) NOT NULL,
  `recurring`   varchar(20) DEFAULT 'none',
  `color`       varchar(7) DEFAULT '#6366f1',
  `created_at`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_chat` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `user_id`    int(11) NOT NULL,
  `message`    text NOT NULL,
  `channel`    varchar(50) DEFAULT 'general',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_alerts` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `title`      varchar(200) NOT NULL,
  `message`    text,
  `level`      varchar(10) DEFAULT 'info',
  `active`     tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_alerts_seen` (
  `id`       int(11) NOT NULL AUTO_INCREMENT,
  `alert_id` int(11) NOT NULL,
  `user_id`  int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alert_user` (`alert_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_folders` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `name`       varchar(200) NOT NULL,
  `parent_id`  int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_docs` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `folder_id`  int(11) DEFAULT NULL,
  `title`      varchar(200) NOT NULL,
  `type`       varchar(10) DEFAULT 'note',
  `content`    text,
  `filename`    varchar(255) DEFAULT NULL,
  `filesize`    int(11) DEFAULT NULL,
  `mimetype`    varchar(100) DEFAULT NULL,
  `visibility`  varchar(20) DEFAULT 'all',
  `can_download` varchar(20) DEFAULT 'all',
  `allowed_users` text DEFAULT NULL COMMENT 'JSON array of user IDs for specific access',
  `created_by`  int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_profiles` (
  `user_id`          int(11) NOT NULL,
  `skills`           text,
  `notes`            text,
  `blacklisted`      tinyint(1) DEFAULT 0,
  `blacklist_reason` text,
  `can_add_tasks`    tinyint(1) DEFAULT 0,
  `can_upload`       tinyint(1) DEFAULT 0,
  `can_manage_planning` tinyint(1) DEFAULT 0,
  `can_delete_notes` tinyint(1) DEFAULT 0,
  `updated_at`       datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_channels` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `slug`       varchar(100) NOT NULL,
  `open`       tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_chat_muted` (
  `id`       int(11) NOT NULL AUTO_INCREMENT,
  `user_id`  int(11) NOT NULL,
  `muted_by` int(11) NOT NULL,
  `until`    datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_muted` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_coach_access` (
  `coach_id`      int(11) NOT NULL,
  `can_access`    tinyint(1) DEFAULT 0,
  `see_blacklist` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`coach_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_reminders_sent` (
  `id`       int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `user_id`  int(11) NOT NULL,
  `sent_at`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rem` (`event_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_task_volunteers` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `task_id`    int(11) NOT NULL,
  `user_id`    int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tv` (`task_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_slot_volunteers` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `slot_id`    int(11) NOT NULL,
  `user_id`    int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sv` (`slot_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_benv_task_suggestions` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `title`       varchar(200) NOT NULL,
  `description` text,
  `suggested_by` int(11) NOT NULL,
  `status`      varchar(20) DEFAULT 'pending' COMMENT 'pending|approved|rejected',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `created_at`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_video_folders` (
  `id`           int(11) NOT NULL AUTO_INCREMENT,
  `name`         varchar(200) NOT NULL,
  `slug`         varchar(200) NOT NULL UNIQUE,
  `description`  text DEFAULT NULL,
  `require_login` tinyint(1) DEFAULT 0,
  `order`        int(11) DEFAULT 0,
  `created_at`   datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_videos` (
  `id`           int(11) NOT NULL AUTO_INCREMENT,
  `folder_id`    int(11) NOT NULL,
  `title`        varchar(200) NOT NULL,
  `description`  text DEFAULT NULL,
  `filename`     varchar(255) DEFAULT NULL COMMENT 'Fichier local uploadé',
  `embed_url`    varchar(500) DEFAULT NULL COMMENT 'URL YouTube/Vimeo embed',
  `thumbnail`    varchar(255) DEFAULT NULL,
  `allow_download` tinyint(1) DEFAULT 0,
  `require_login` tinyint(1) DEFAULT 0,
  `filesize`     bigint DEFAULT NULL,
  `duration`     int(11) DEFAULT NULL COMMENT 'Durée en secondes',
  `order`        int(11) DEFAULT 0,
  `created_by`   int(11) NOT NULL,
  `created_at`   datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_results_categories` (
  `id`    int(11) NOT NULL AUTO_INCREMENT,
  `name`  varchar(200) NOT NULL,
  `slug`  varchar(200) NOT NULL UNIQUE,
  `icon`  varchar(10)  DEFAULT '🏆',
  `order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_results` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `title`       varchar(200) NOT NULL,
  `date`        date DEFAULT NULL,
  `source_type` varchar(20) DEFAULT 'manual' COMMENT 'manual|iframe|gsheet',
  `iframe_url`  varchar(500) DEFAULT NULL,
  `content`     text DEFAULT NULL COMMENT 'JSON tableau manuel ou embed URL',
  `published`   tinyint(1) DEFAULT 1,
  `created_by`  int(11) NOT NULL,
  `created_at`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_page_views` (
  `id`       int(11) NOT NULL AUTO_INCREMENT,
  `page`     varchar(200) NOT NULL,
  `views`    int(11) DEFAULT 1,
  `date`     date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_date` (`page`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_contact_messages` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `name`       varchar(200) NOT NULL,
  `email`      varchar(200) NOT NULL,
  `subject`    varchar(300) DEFAULT '',
  `message`    text NOT NULL,
  `ip`         varchar(45) DEFAULT '',
  `read_at`    datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_tombola` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `name`            varchar(200) NOT NULL,
  `description`     text DEFAULT NULL,
  `status`          enum('draft','active','closed','done') DEFAULT 'draft',
  `paid`            tinyint(1) DEFAULT 0 COMMENT 'Participation payante',
  `price`           decimal(10,2) DEFAULT 0.00,
  `product_id`      int(11) DEFAULT NULL COMMENT 'Produit boutique lié',
  `multi_entry`     tinyint(1) DEFAULT 0 COMMENT 'Inscriptions multiples autorisées',
  `visibility`      enum('all','members') DEFAULT 'all' COMMENT 'Visibilité',
  `participation`   enum('all','members','coach','admin') DEFAULT 'all' COMMENT 'Qui peut participer',
  `close_at`        datetime DEFAULT NULL COMMENT 'Date clôture inscriptions',
  `msg_waiting`     varchar(500) DEFAULT 'Le tirage au sort aura lieu prochainement. Bonne chance !',
  `winner_id`       int(11) DEFAULT NULL,
  `winner_name`     varchar(200) DEFAULT NULL,
  `drawn_at`        datetime DEFAULT NULL,
  `created_at`      datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cc_tombola_participants` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `tombola_id`  int(11) NOT NULL,
  `user_id`     int(11) DEFAULT NULL,
  `name`        varchar(200) NOT NULL,
  `email`       varchar(200) DEFAULT NULL,
  `tickets`     int(11) DEFAULT 1 COMMENT 'Nombre de tickets (plus de chances)',
  `created_at`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Données initiales ─────────────────────────────────────────
INSERT IGNORE INTO `cc_modules` (`slug`,`label`,`enabled`,`require_login`) VALUES
  ('forum',    'Forum',    1, 1),
  ('shop',     'Boutique', 1, 0),
  ('gallery',  'Galerie',  1, 0),
  ('planning', 'Planning', 1, 0),
  ('members',  'Annuaire', 0, 1),
  ('videos',   'Vidéos',   1, 0);

INSERT IGNORE INTO `cc_benv_channels` (`name`,`slug`,`open`,`created_by`) VALUES
  ('Général', 'general', 1, 1),
  ('Organisation', 'organisation', 1, 1),
  ('Logistique', 'logistique', 1, 1);

INSERT IGNORE INTO `cc_planning_types` (`slug`,`label`,`color`,`is_system`,`sort_order`) VALUES
  ('open',        'Libre',       '#22c55e', 1, 1),
  ('training',    'Entraînement','#3b82f6', 1, 2),
  ('event',       'Événement',   '#f59e0b', 1, 3),
  ('maintenance', 'Fermé',       '#6b7280', 1, 4),
  ('competition', 'Compétition', '#ef4444', 1, 5);
