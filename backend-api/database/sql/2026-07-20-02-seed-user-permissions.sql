START TRANSACTION;

CREATE TEMPORARY TABLE `seed_permission_root_guard` (
  `root_user_id` INT NOT NULL,
  CHECK (`root_user_id` = 31)
) ENGINE=InnoDB;

INSERT INTO `seed_permission_root_guard` (`root_user_id`)
SELECT COALESCE(
  (SELECT `id` FROM `users` WHERE `id` = 31 LIMIT 1),
  0
);

CREATE TEMPORARY TABLE `seed_permission_catalog` (
  `permission_code` VARCHAR(64) NOT NULL PRIMARY KEY,
  `root_only` TINYINT(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `seed_permission_catalog` (`permission_code`, `root_only`) VALUES
  ('users.view', 0),
  ('users.create', 0),
  ('users.edit', 0),
  ('users.renew', 0),
  ('users.force_logout', 0),
  ('settings.market.view', 0),
  ('settings.market.update', 0),
  ('system.logs.view', 0),
  ('system.server.view', 0),
  ('system.server.restart', 0),
  ('system.platform.restart', 0),
  ('platform.address.configure', 0),
  ('permissions.manage', 1);

CREATE TEMPORARY TABLE `seed_permission_grants` (
  `user_id` INT NOT NULL,
  `permission_code` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`user_id`, `permission_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `seed_permission_grants` (`user_id`, `permission_code`)
SELECT `u`.`id`, `c`.`permission_code`
FROM `users` AS `u`
CROSS JOIN `seed_permission_catalog` AS `c`
LEFT JOIN `user_permissions` AS `existing`
  ON `existing`.`user_id` = `u`.`id`
 AND `existing`.`permission_code` = `c`.`permission_code`
WHERE `u`.`is_admin` = 1
  AND (`c`.`root_only` = 0 OR `u`.`id` = 31)
  AND `existing`.`id` IS NULL;

INSERT INTO `user_permissions`
  (`user_id`, `permission_code`, `granted_by`, `created_at`, `updated_at`)
SELECT `user_id`, `permission_code`, 31, NOW(), NOW()
FROM `seed_permission_grants`;

INSERT INTO `permission_change_logs`
  (`target_user_id`, `target_account`, `permission_code`, `action`,
   `operator_user_id`, `operator_account`, `created_at`)
SELECT
  `g`.`user_id`,
  `target`.`account`,
  `g`.`permission_code`,
  'grant',
  31,
  `operator`.`account`,
  NOW()
FROM `seed_permission_grants` AS `g`
JOIN `users` AS `target` ON `target`.`id` = `g`.`user_id`
JOIN `users` AS `operator` ON `operator`.`id` = 31;

DROP TEMPORARY TABLE `seed_permission_grants`;
DROP TEMPORARY TABLE `seed_permission_catalog`;
DROP TEMPORARY TABLE `seed_permission_root_guard`;

COMMIT;
