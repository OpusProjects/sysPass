-- Text stored by a version that escaped HTML on the way in.
--
-- Filter::getString() ran htmlspecialchars() over every submitted value, so what is in these
-- columns is the entity form of what somebody typed: a category named "Q&A" is stored as
-- "Q&amp;A", and an LDAP filter "(&(objectClass=user))" was sent to the directory with an
-- "&amp;" in it. The application stores text as typed now, and this brings the rows that were
-- written before it in line.
--
-- The ampersand is decoded LAST in each chain, and that ordering is the whole correctness
-- argument: somebody who literally typed "&lt;" had it stored as "&amp;lt;", and decoding the
-- ampersand first would silently turn their text into a "<".
--
-- Only columns that received a submitted value are touched. EventLog is deliberately left alone:
-- it is a record of what happened, and rewriting it would be rewriting history.
--
-- This is not a decode that can be applied twice, and no decode of stored text could be: run
-- again, "&amp;amp;" would go from "&amp;" to "&". What keeps it to one run is the database
-- version, which UpgradeDatabase records once the file has been applied — and the transaction
-- below, so that a run interrupted half way leaves the rows as they were rather than half of them
-- decoded and no version recorded. Every statement here is DML, which InnoDB will roll back.

DELIMITER $$

start transaction$$

update `Account`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `Account`
    set `login` = REPLACE(REPLACE(REPLACE(`login`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `login` like '%&%'$$

update `Account`
    set `url` = REPLACE(REPLACE(REPLACE(`url`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `url` like '%&%'$$

update `Account`
    set `notes` = REPLACE(REPLACE(REPLACE(`notes`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `notes` like '%&%'$$

update `AccountHistory`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `AccountHistory`
    set `login` = REPLACE(REPLACE(REPLACE(`login`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `login` like '%&%'$$

update `AccountHistory`
    set `url` = REPLACE(REPLACE(REPLACE(`url`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `url` like '%&%'$$

update `AccountHistory`
    set `notes` = REPLACE(REPLACE(REPLACE(`notes`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `notes` like '%&%'$$

update `Category`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `Category`
    set `description` = REPLACE(REPLACE(REPLACE(`description`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `description` like '%&%'$$

update `Client`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `Client`
    set `description` = REPLACE(REPLACE(REPLACE(`description`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `description` like '%&%'$$

update `CustomFieldDefinition`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `CustomFieldDefinition`
    set `help` = REPLACE(REPLACE(REPLACE(`help`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `help` like '%&%'$$

update `Notification`
    set `type` = REPLACE(REPLACE(REPLACE(`type`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `type` like '%&%'$$

update `Notification`
    set `component` = REPLACE(REPLACE(REPLACE(`component`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `component` like '%&%'$$

update `Notification`
    set `description` = REPLACE(REPLACE(REPLACE(`description`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `description` like '%&%'$$

update `Tag`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `User`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `User`
    set `login` = REPLACE(REPLACE(REPLACE(`login`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `login` like '%&%'$$

update `User`
    set `ssoLogin` = REPLACE(REPLACE(REPLACE(`ssoLogin`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `ssoLogin` like '%&%'$$

update `User`
    set `email` = REPLACE(REPLACE(REPLACE(`email`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `email` like '%&%'$$

update `User`
    set `notes` = REPLACE(REPLACE(REPLACE(`notes`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `notes` like '%&%'$$

update `UserGroup`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

update `UserGroup`
    set `description` = REPLACE(REPLACE(REPLACE(`description`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `description` like '%&%'$$

update `UserProfile`
    set `name` = REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `name` like '%&%'$$

-- The web upload escaped a file's name with ENT_QUOTES rather than the ENT_NOQUOTES the
-- request filter used, so this one carries the two quote entities as well.
update `AccountFile`
    set `name` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`name`,'&lt;','<'),'&gt;','>'),'&quot;','"'),'&#039;',''''),'&amp;','&')
    where `name` like '%&%'$$

-- A custom field's value is stored in the clear only when its definition is not an encrypted
-- one. The encrypted values went through the same filter and cannot be reached from here: reading
-- them needs the master password, which an upgrade does not have.
update `CustomFieldData` `d`
    join `CustomFieldDefinition` `f` on `f`.`id` = `d`.`definitionId` and `f`.`isEncrypted` = 0
    set `d`.`data` = REPLACE(REPLACE(REPLACE(`d`.`data`,'&lt;','<'),'&gt;','>'),'&amp;','&')
    where `d`.`data` like '%&%'$$

commit$$
