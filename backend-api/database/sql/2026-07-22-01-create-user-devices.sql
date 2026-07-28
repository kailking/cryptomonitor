CREATE TABLE `user_devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `device_token_hash` CHAR(64) NOT NULL,
  `device_label` VARCHAR(100) NOT NULL,
  `browser_id` VARCHAR(128) NULL,
  `user_agent` VARCHAR(500) NULL,
  `last_proxy_ip` VARCHAR(100) NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `login_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_alert_code` VARCHAR(32) NULL,
  `last_alert_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_devices_user_token_unique` (`user_id`, `device_token_hash`),
  KEY `user_devices_token_index` (`device_token_hash`),
  KEY `user_devices_user_seen_index` (`user_id`, `last_seen_at`),
  CONSTRAINT `user_devices_user_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
