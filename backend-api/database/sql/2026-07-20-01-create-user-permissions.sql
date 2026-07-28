CREATE TABLE `user_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `permission_code` VARCHAR(64) NOT NULL,
  `granted_by` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_permissions_user_code_unique` (`user_id`, `permission_code`),
  KEY `user_permissions_code_index` (`permission_code`),
  KEY `user_permissions_granted_by_index` (`granted_by`),
  CONSTRAINT `user_permissions_user_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_granted_by_fk`
    FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permission_change_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_user_id` INT NOT NULL,
  `target_account` VARCHAR(100) NOT NULL,
  `permission_code` VARCHAR(64) NOT NULL,
  `action` VARCHAR(16) NOT NULL,
  `operator_user_id` INT NOT NULL,
  `operator_account` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `permission_logs_target_created_index` (`target_user_id`, `created_at`),
  KEY `permission_logs_operator_created_index` (`operator_user_id`, `created_at`),
  KEY `permission_logs_code_created_index` (`permission_code`, `created_at`),
  CONSTRAINT `permission_logs_action_check`
    CHECK (`action` IN ('grant', 'revoke'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
