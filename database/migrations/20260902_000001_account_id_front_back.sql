ALTER TABLE account_requests
  ADD COLUMN id_front_path VARCHAR(255) NULL AFTER office_id,
  ADD COLUMN id_front_original_name VARCHAR(255) NULL AFTER id_front_path,
  ADD COLUMN id_front_mime VARCHAR(150) NULL AFTER id_front_original_name,
  ADD COLUMN id_front_size BIGINT UNSIGNED NULL AFTER id_front_mime,
  ADD COLUMN id_back_path VARCHAR(255) NULL AFTER id_front_size,
  ADD COLUMN id_back_original_name VARCHAR(255) NULL AFTER id_back_path,
  ADD COLUMN id_back_mime VARCHAR(150) NULL AFTER id_back_original_name,
  ADD COLUMN id_back_size BIGINT UNSIGNED NULL AFTER id_back_mime;
