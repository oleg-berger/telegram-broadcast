CREATE TABLE IF NOT EXISTS bc_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    whatsapp_language VARCHAR(5) CHARACTER SET ascii NOT NULL DEFAULT 'RU',
    manual_email VARCHAR(254) NULL,
    telegram_user_id BIGINT UNSIGNED NULL UNIQUE,
    telegram_email VARCHAR(254) NULL,
    telegram_language VARCHAR(5) CHARACTER SET ascii NULL,
    telegram_status VARCHAR(20) CHARACTER SET ascii NOT NULL DEFAULT 'none',
    telegram_revision BIGINT NOT NULL DEFAULT -1,
    subscription_revision BIGINT NOT NULL DEFAULT -1,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    whatsapp_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    whatsapp_registered TINYINT UNSIGNED NOT NULL DEFAULT 0,
    telegram_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    email_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX bc_active (active,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bc_directory_operations (
    operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    payload_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bc_snapshots (
    snapshot_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    created_at BIGINT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bc_snapshot_recipients (
    snapshot_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    telegram_user_id BIGINT UNSIGNED NULL,
    phone VARCHAR(16) CHARACTER SET ascii NOT NULL,
    channel VARCHAR(10) CHARACTER SET ascii NOT NULL,
    address VARCHAR(254) NOT NULL,
    language VARCHAR(5) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY(snapshot_id,contact_id,channel),
    FOREIGN KEY(snapshot_id) REFERENCES bc_snapshots(snapshot_id),
    FOREIGN KEY(contact_id) REFERENCES bc_contacts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
