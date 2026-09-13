-- Account-request PRMSU ID verification and legacy archive metadata.

ALTER TABLE account_requests
    ADD COLUMN id_photo_path VARCHAR(255) NULL AFTER office_id,
    ADD COLUMN id_photo_original_name VARCHAR(255) NULL AFTER id_photo_path,
    ADD COLUMN id_photo_mime VARCHAR(150) NULL AFTER id_photo_original_name,
    ADD COLUMN id_photo_size BIGINT UNSIGNED NULL AFTER id_photo_mime;

ALTER TABLE documents
    ADD COLUMN document_creator VARCHAR(180) NULL AFTER document_name;
