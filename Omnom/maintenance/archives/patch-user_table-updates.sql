-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: maintenance/abstractSchemaChanges/patch-user_table-updates.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  /*_*/user
CHANGE  user_name user_name VARBINARY(255) DEFAULT '' NOT NULL,
CHANGE  user_real_name user_real_name VARBINARY(255) DEFAULT '' NOT NULL,
CHANGE  user_touched user_touched BINARY(14) NOT NULL;