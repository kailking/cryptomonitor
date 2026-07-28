-- Emergency rollback only. Back up user_devices before removing audit history.
DROP TABLE IF EXISTS `user_devices`;
