-- Do not run during a normal application rollback. Use only for a database anomaly with manual approval and a verified complete backup.
DROP TABLE IF EXISTS `permission_change_logs`;
DROP TABLE IF EXISTS `user_permissions`;
