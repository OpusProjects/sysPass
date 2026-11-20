-- Whether an administrator requires this user to sign in with a second factor.
--
-- Appended as the last column, so rows written positionally before it keep their meaning, and
-- NOT NULL DEFAULT 0, so every existing user is left exactly as they were: nobody is required
-- until an administrator says so. One statement: it applies whole or not at all.

DELIMITER $$

ALTER TABLE `User`
    ADD COLUMN `isTotpRequired` tinyint(1) NOT NULL DEFAULT 0$$
