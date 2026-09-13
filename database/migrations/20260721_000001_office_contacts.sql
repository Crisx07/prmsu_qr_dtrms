CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(120) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @has_office_trunk_line := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'offices'
      AND column_name = 'trunk_line'
);
SET @ddl := IF(
    @has_office_trunk_line = 0,
    'ALTER TABLE offices ADD COLUMN trunk_line VARCHAR(50) NULL AFTER code',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_office_local_number := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'offices'
      AND column_name = 'local_number'
);
SET @ddl := IF(
    @has_office_local_number = 0,
    'ALTER TABLE offices ADD COLUMN local_number VARCHAR(50) NULL AFTER trunk_line',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

INSERT IGNORE INTO schema_migrations (migration, applied_at)
VALUES ('20260721_000001_office_contacts', NOW());
