-- The group form/controller already reads and writes this field; the column
-- was missing after the responsive/group rebuilds.
ALTER TABLE `groups`
    ADD COLUMN `schedule_days` VARCHAR(64) NOT NULL DEFAULT 'Mon,Wed,Fri' AFTER `capacity`;
