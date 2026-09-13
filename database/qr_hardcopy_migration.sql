-- PRMSU Iba Campus QR hardcopy workflow migration.
-- The application also applies these changes automatically in includes/functions.php.

ALTER TABLE documents
    ADD COLUMN qr_token CHAR(64) NULL AFTER tracking_no,
    ADD COLUMN document_person_name VARCHAR(180) NULL AFTER document_name,
    ADD COLUMN file_sha256 CHAR(64) NULL AFTER attachment_size,
    ADD COLUMN page_count INT UNSIGNED NULL AFTER file_sha256,
    ADD COLUMN qr_issued_at DATETIME NULL AFTER page_count,
    ADD COLUMN validated_at DATETIME NULL AFTER qr_issued_at,
    ADD COLUMN validated_by INT UNSIGNED NULL AFTER validated_at;

ALTER TABLE documents
    ADD UNIQUE INDEX idx_documents_qr_token (qr_token);

ALTER TABLE documents
    MODIFY status ENUM(
        'Draft',
        'Submitted',
        'Rejected',
        'Under Action',
        'Completed',
        'Archived'
    ) NOT NULL DEFAULT 'Draft';

ALTER TABLE documents
    ADD COLUMN released_at DATETIME NULL AFTER ocr_review_note,
    ADD COLUMN released_by INT UNSIGNED NULL AFTER released_at,
    ADD COLUMN submitted_at DATETIME NULL AFTER released_by,
    ADD COLUMN submitted_by INT UNSIGNED NULL AFTER submitted_at,
    ADD COLUMN reviewed_at DATETIME NULL AFTER submitted_by,
    ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER reviewed_at,
    ADD COLUMN review_note TEXT NULL AFTER reviewed_by;

ALTER TABLE documents
    ADD INDEX idx_documents_person_name (document_person_name),
    ADD INDEX idx_documents_received_date (received_date),
    ADD INDEX idx_documents_status (status),
    ADD INDEX idx_documents_document_number (document_number);

ALTER TABLE document_routes
    ADD COLUMN handoff_type ENUM('records_to_office', 'office_to_records') NULL AFTER route_status,
    MODIFY route_status ENUM('pending_receipt', 'received', 'cancelled') NOT NULL DEFAULT 'received';

ALTER TABLE document_routes
    ADD INDEX idx_document_routes_handoff_type (handoff_type);

CREATE TABLE document_scan_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    route_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    office_id INT UNSIGNED NULL,
    action ENUM('scan','validate','route','receive','flag_mismatch','return_to_records','resolve_hold','complete','archive','location_update','restore_archive') NOT NULL DEFAULT 'scan',
    result ENUM('valid','invalid','mismatch','unauthorized') NOT NULL DEFAULT 'valid',
    remarks TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scan_document (document_id),
    KEY idx_scan_route (route_id),
    KEY idx_scan_user (user_id),
    KEY idx_scan_office (office_id),
    CONSTRAINT fk_scan_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_scan_route FOREIGN KEY (route_id) REFERENCES document_routes(id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE document_timeline_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    route_id INT UNSIGNED NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_office_id INT UNSIGNED NULL,
    counterparty_office_id INT UNSIGNED NULL,
    event_type ENUM(
        'registered',
        'draft_created',
        'submitted',
        'released',
        'rejected',
        'intake_validated',
        'forwarded',
        'office_forwarded',
        'received',
        'returned_to_records',
        'remarked',
        'hold_placed',
        'hold_resolved',
        'completed',
        'archived',
        'file_validated',
        'ocr_verified',
        'ocr_rejected',
        'location_updated',
        'archive_restored'
    ) NOT NULL,
    stage_after VARCHAR(30) NOT NULL,
    remarks TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_timeline_document (document_id),
    KEY idx_timeline_route (route_id),
    KEY idx_timeline_user (actor_user_id),
    KEY idx_timeline_office (actor_office_id),
    CONSTRAINT fk_timeline_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_timeline_route FOREIGN KEY (route_id) REFERENCES document_routes(id) ON DELETE SET NULL,
    CONSTRAINT fk_timeline_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_timeline_actor_office FOREIGN KEY (actor_office_id) REFERENCES offices(id) ON DELETE SET NULL,
    CONSTRAINT fk_timeline_counterparty_office FOREIGN KEY (counterparty_office_id) REFERENCES offices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE document_validation_checklists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    route_id INT UNSIGNED NULL,
    office_id INT UNSIGNED NULL,
    validated_by INT UNSIGNED NULL,
    context ENUM('records_intake','office_receipt','records_return') NOT NULL,
    qr_match TINYINT(1) NOT NULL DEFAULT 0,
    tracking_number_match TINYINT(1) NOT NULL DEFAULT 0,
    subject_match TINYINT(1) NOT NULL DEFAULT 0,
    source_office_match TINYINT(1) NOT NULL DEFAULT 0,
    destination_office_match TINYINT(1) NOT NULL DEFAULT 0,
    page_count_match TINYINT(1) NOT NULL DEFAULT 0,
    signatures_match TINYINT(1) NOT NULL DEFAULT 0,
    remarks TEXT NULL,
    result ENUM('pass','fail') NOT NULL DEFAULT 'pass',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_checklist_document (document_id),
    KEY idx_checklist_route (route_id),
    KEY idx_checklist_context (context),
    KEY idx_checklist_result (result),
    CONSTRAINT fk_checklist_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_checklist_route FOREIGN KEY (route_id) REFERENCES document_routes(id) ON DELETE SET NULL,
    CONSTRAINT fk_checklist_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL,
    CONSTRAINT fk_checklist_user FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE auth_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(40) NOT NULL,
    identity_hash CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_rate_scope_identity (scope, identity_hash),
    KEY idx_auth_rate_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
