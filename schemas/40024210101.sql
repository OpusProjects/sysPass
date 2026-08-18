-- CustomFieldData's identity is the row it describes, not a surrogate id.
--
-- Both changes are one ALTER on purpose. As two statements the drop committed on its own — DDL
-- always does — and the primary key then failed on any installation holding a duplicate
-- (moduleId, itemId, definitionId), which the old surrogate key allowed. That left the column
-- gone, the database version unchanged because the upgrade throws before writing it, and every
-- retry failing on `Can't DROP COLUMN id` before it could reach the statement that had actually
-- failed. The upgrade could not be completed and could not be repeated.
--
-- As one statement the server applies both or neither, so a duplicate leaves the table untouched:
-- the operator clears the duplicates and runs the upgrade again.
DELIMITER $$

alter table CustomFieldData
    drop column id,
    add primary key (moduleId, itemId, definitionId)$$
