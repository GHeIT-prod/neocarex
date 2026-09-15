-- oe-module-gheit-prior-auth
-- Run at module install time (wired up via the module installer's SQL hook).

CREATE TABLE IF NOT EXISTS `cds_hooks_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `base_url` varchar(512) NOT NULL COMMENT 'e.g. https://cds.example.org/cds-services',
  `fhir_server` varchar(512) NULL COMMENT 'URL of the FHIR server',
  `tenant_id` int(11) NOT NULL,
  `service_hash` varchar(255) NOT NULL,
  `service_id` varchar(255) NOT NULL COMMENT 'id field from the vendor discovery doc',
  `hook` varchar(64) NOT NULL DEFAULT 'patient-view',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `auth_token` varchar(512) DEFAULT NULL COMMENT 'static bearer token fallback, if no OAuth2 fields set',
  `token_url` varchar(512) DEFAULT NULL COMMENT 'OAuth2 client_credentials token endpoint, e.g. Nucural auth server',
  `client_id` varchar(255) DEFAULT NULL,
  `client_secret` varchar(512) DEFAULT NULL COMMENT 'consider encrypting at rest, not just DB access control',
  `cached_token` varchar(2048) DEFAULT NULL,
  `cached_token_expires_at` datetime DEFAULT NULL,
  `timeout_seconds` int(11) NOT NULL DEFAULT 3,
  `date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cds_hooks_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cds_hooks_crd_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `status` varchar(32) NOT NULL COMMENT 'no-pa, pa-required, or unknown',
  `card_summary` varchar(512) DEFAULT NULL,
  `date` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crd_log_order` (`order_id`),
  KEY `idx_crd_log_patient` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cds_hooks_crd_status` (
    `order_id`        INT NOT NULL PRIMARY KEY,
    `patient_id`      INT NOT NULL,
    `status`          VARCHAR(32) NOT NULL,
    `action`          VARCHAR(32) NOT NULL,
    `dtr_launch_url`  TEXT NULL,
    `card_summary`    TEXT NULL,
    `updated_at`      DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Master feature flag, shows up under Administration -> Globals once installed.
INSERT IGNORE INTO `globals` (`gl_name`, `gl_index`, `gl_value`)
VALUES ('enable_cds_hooks', 0, '0');

-- These two ALTER blocks originally used a bare ADD COLUMN, which is NOT
-- idempotent: the OpenEMR module manager UI re-runs the full table.sql on
-- every reinstall/re-registration, not just on first-ever install (confirmed
-- by the "could not open table.sql, broken form?" error, which was MySQL's
-- "Duplicate column name" failure on this ALTER, surfaced badly by the
-- installer). Rewritten as a single idempotent ALTER using
-- ADD COLUMN IF NOT EXISTS for every column added by this module, so the
-- file can be re-run safely at any time. Requires MariaDB 10.0.2+ or
-- MySQL 8.0.29+; if this deployment runs an older server, say so and this
-- needs a stored-procedure/INFORMATION_SCHEMA guard instead.
ALTER TABLE `cds_hooks_crd_status`
  ADD COLUMN IF NOT EXISTS `encounter_id` INT NULL AFTER `patient_id`,
  ADD COLUMN IF NOT EXISTS `resource_id` VARCHAR(255) NULL AFTER `dtr_launch_url`,
  ADD COLUMN IF NOT EXISTS `authorization_number` VARCHAR(255) NULL AFTER `resource_id`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `card_summary`,
  ADD COLUMN IF NOT EXISTS `approved_quantity` INT DEFAULT NULL AFTER `authorization_number`,
  ADD COLUMN IF NOT EXISTS `denial_reason` VARCHAR(500) DEFAULT NULL AFTER `approved_quantity`,
  ADD COLUMN IF NOT EXISTS `cpt_code` VARCHAR(16) DEFAULT NULL AFTER `denial_reason`,
  ADD COLUMN IF NOT EXISTS `seq` INT DEFAULT NULL AFTER `cpt_code`,
  ADD COLUMN IF NOT EXISTS `occurred_at` DATETIME DEFAULT NULL AFTER `seq`;

-- FR-B-12c: idempotency store, keyed by the Pax event id (UUID). The unique
-- primary key is what makes the already-processed check (StatusSync::
-- alreadyProcessed) cheap and the race safe (FR-B-11).
CREATE TABLE IF NOT EXISTS `pax_status_events` (
  `event_id`     VARCHAR(36) NOT NULL,
  `order_id`     INT NOT NULL,
  `received_at`  DATETIME NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_pax_status_events_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FR-B-12d: a NeoCareX-wide change counter, bumped in the same transaction
-- as every applied event (FR-B-12f). The SSE stream and the polling
-- endpoint watch this single row so a screen can ask "has anything changed
-- since N?" without scanning the status table.
CREATE TABLE IF NOT EXISTS `pax_sync_seq` (
  `counter` BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Already idempotent: only seeds a row if the table is empty.
INSERT INTO `pax_sync_seq` (`counter`)
SELECT 0 WHERE NOT EXISTS (SELECT 1 FROM `pax_sync_seq`);