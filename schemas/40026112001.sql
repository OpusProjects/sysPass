-- Two-factor sign-in: a user's one-time password secret, and their single-use recovery codes.
--
-- UserTotp holds one row per user. The secret is encrypted with a key derived from the
-- installation's password salt, which lives in config.xml and not in the database, so a dump of
-- the database alone does not yield anybody's second factor. A row with no enabledAt is an
-- enrolment that was started and never confirmed with a code, and is not enforced at sign-in.
-- lastStep is the time step of the last code accepted, which is what refuses a code used twice.
--
-- Both tables are created IF NOT EXISTS. Each CREATE commits on its own, as DDL always does, so a
-- run interrupted between them has to be repeatable rather than failing on the table it already
-- made; neither statement touches existing data.

DELIMITER $$

CREATE TABLE IF NOT EXISTS `UserTotp`
(
    `userId`    smallint(5) unsigned NOT NULL,
    `secret`    varbinary(2000)      NOT NULL,
    `secretKey` varbinary(2000)      NOT NULL,
    `lastStep`  bigint(20) unsigned           DEFAULT NULL,
    `enabledAt` int(10) unsigned              DEFAULT NULL,
    PRIMARY KEY (`userId`),
    CONSTRAINT `fk_UserTotp_userId` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci$$

CREATE TABLE IF NOT EXISTS `UserTotpRecovery`
(
    `id`     int(10) unsigned     NOT NULL AUTO_INCREMENT,
    `userId` smallint(5) unsigned NOT NULL,
    `hash`   varbinary(255)       NOT NULL,
    `used`   tinyint(1)           NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_UserTotpRecovery_01` (`userId`, `used`),
    CONSTRAINT `fk_UserTotpRecovery_userId` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci$$
