CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(120) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @has_physical_handler_name := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'document_scan_logs'
      AND column_name = 'physical_handler_name'
);
SET @ddl := IF(
    @has_physical_handler_name = 0,
    'ALTER TABLE document_scan_logs ADD COLUMN physical_handler_name VARCHAR(150) NULL AFTER remarks',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_document_fulltext_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'documents'
      AND index_name = 'idx_documents_fulltext_search'
);
SET @ddl := IF(
    @has_document_fulltext_index = 0,
    'ALTER TABLE documents ADD FULLTEXT INDEX idx_documents_fulltext_search (
        tracking_no,
        document_number,
        document_name,
        document_person_name,
        subject,
        description,
        ocr_raw_text,
        archive_note
    )',
    'SELECT 1'
);
PREPARE migration_stmt FROM @ddl;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

INSERT IGNORE INTO schema_migrations (migration, applied_at)
VALUES ('20260716_000001_hardening_search_filters', NOW());
