
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=428 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_rate_limits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `identity_hash` char(64) COLLATE utf8mb4_general_ci NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `last_attempt_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_rate_scope_identity` (`scope`,`identity_hash`),
  KEY `idx_auth_rate_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_number_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_number_sequences` (
  `year` smallint unsigned NOT NULL,
  `last_number` int unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_routes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `from_office_id` int unsigned DEFAULT NULL,
  `to_office_id` int unsigned DEFAULT NULL,
  `from_office` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `to_office` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `route_status` enum('pending_receipt','received','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'received',
  `handoff_type` enum('records_to_office','office_to_records','office_to_office') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `routed_by` int unsigned DEFAULT NULL,
  `routed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_at` datetime DEFAULT NULL,
  `received_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_routes_document` (`document_id`),
  KEY `fk_routes_user` (`routed_by`),
  KEY `idx_document_routes_status` (`route_status`),
  KEY `idx_document_routes_handoff_type` (`handoff_type`),
  CONSTRAINT `fk_routes_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_routes_user` FOREIGN KEY (`routed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_scan_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_scan_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `route_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `office_id` int unsigned DEFAULT NULL,
  `action` enum('scan','validate','route','receive','flag_mismatch','return_to_records','resolve_hold','complete','archive','location_update','restore_archive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'scan',
  `result` enum('valid','invalid','mismatch','unauthorized') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'valid',
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scan_document` (`document_id`),
  KEY `idx_scan_route` (`route_id`),
  KEY `idx_scan_user` (`user_id`),
  KEY `idx_scan_office` (`office_id`),
  CONSTRAINT `fk_scan_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scan_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scan_route` FOREIGN KEY (`route_id`) REFERENCES `document_routes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_scan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_timeline_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_timeline_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `route_id` int unsigned DEFAULT NULL,
  `actor_user_id` int unsigned DEFAULT NULL,
  `actor_office_id` int unsigned DEFAULT NULL,
  `counterparty_office_id` int unsigned DEFAULT NULL,
  `event_type` enum('registered','draft_created','submitted','released','rejected','intake_validated','forwarded','office_forwarded','received','returned_to_records','remarked','hold_placed','hold_resolved','completed','archived','file_validated','ocr_verified','ocr_rejected','location_updated','archive_restored') COLLATE utf8mb4_general_ci NOT NULL,
  `stage_after` varchar(30) COLLATE utf8mb4_general_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_timeline_document` (`document_id`),
  KEY `idx_timeline_route` (`route_id`),
  KEY `idx_timeline_user` (`actor_user_id`),
  KEY `idx_timeline_office` (`actor_office_id`),
  KEY `fk_timeline_counterparty_office` (`counterparty_office_id`),
  CONSTRAINT `fk_timeline_actor_office` FOREIGN KEY (`actor_office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_timeline_counterparty_office` FOREIGN KEY (`counterparty_office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_timeline_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_timeline_route` FOREIGN KEY (`route_id`) REFERENCES `document_routes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_timeline_user` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_validation_checklists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_validation_checklists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `route_id` int unsigned DEFAULT NULL,
  `office_id` int unsigned DEFAULT NULL,
  `validated_by` int unsigned DEFAULT NULL,
  `context` enum('records_intake','office_receipt','records_return') COLLATE utf8mb4_general_ci NOT NULL,
  `qr_match` tinyint(1) NOT NULL DEFAULT '0',
  `tracking_number_match` tinyint(1) NOT NULL DEFAULT '0',
  `subject_match` tinyint(1) NOT NULL DEFAULT '0',
  `source_office_match` tinyint(1) NOT NULL DEFAULT '0',
  `destination_office_match` tinyint(1) NOT NULL DEFAULT '0',
  `page_count_match` tinyint(1) NOT NULL DEFAULT '0',
  `signatures_match` tinyint(1) NOT NULL DEFAULT '0',
  `remarks` text COLLATE utf8mb4_general_ci,
  `result` enum('pass','fail') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pass',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_checklist_document` (`document_id`),
  KEY `idx_checklist_route` (`route_id`),
  KEY `idx_checklist_context` (`context`),
  KEY `idx_checklist_result` (`result`),
  KEY `fk_checklist_office` (`office_id`),
  KEY `fk_checklist_user` (`validated_by`),
  CONSTRAINT `fk_checklist_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_checklist_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_checklist_route` FOREIGN KEY (`route_id`) REFERENCES `document_routes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_checklist_user` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tracking_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `document_number` varchar(80) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `document_name` varchar(180) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `document_creator` varchar(180) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `document_person_name` varchar(180) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `qr_token` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `category_id` int unsigned DEFAULT NULL,
  `origin_office_id` int unsigned DEFAULT NULL,
  `current_office_id` int unsigned DEFAULT NULL,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `origin_office` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `current_office` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `received_date` date NOT NULL,
  `status` enum('Draft','Submitted','Rejected','Under Action','Completed','Archived') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Draft',
  `attachment_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `attachment_original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `attachment_mime` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `attachment_size` bigint unsigned DEFAULT NULL,
  `file_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `page_count` int unsigned DEFAULT NULL,
  `qr_issued_at` datetime DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `validated_by` int unsigned DEFAULT NULL,
  `ocr_raw_text` mediumtext COLLATE utf8mb4_general_ci,
  `ocr_confidence` decimal(5,2) DEFAULT NULL,
  `ocr_status` varchar(30) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'manual',
  `ocr_reviewed_by` int unsigned DEFAULT NULL,
  `ocr_reviewed_at` datetime DEFAULT NULL,
  `ocr_review_note` text COLLATE utf8mb4_general_ci,
  `released_at` datetime DEFAULT NULL,
  `released_by` int unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `submitted_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `review_note` text COLLATE utf8mb4_general_ci,
  `archive_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `deleted_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_no` (`tracking_no`),
  UNIQUE KEY `idx_documents_qr_token` (`qr_token`),
  KEY `fk_documents_created_by` (`created_by`),
  KEY `idx_documents_current_office_id` (`current_office_id`),
  KEY `idx_documents_category_id` (`category_id`),
  KEY `idx_documents_deleted_at` (`deleted_at`),
  KEY `idx_documents_ocr_status` (`ocr_status`),
  KEY `idx_documents_received_date` (`received_date`),
  KEY `idx_documents_status` (`status`),
  KEY `idx_documents_tracking_no` (`tracking_no`),
  KEY `idx_documents_document_number` (`document_number`),
  KEY `idx_documents_person_name` (`document_person_name`),
  CONSTRAINT `fk_documents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_otps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `purpose` enum('login','recovery') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `requested_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_otps_user_purpose` (`user_id`,`purpose`),
  KEY `idx_email_otps_expires_at` (`expires_at`),
  CONSTRAINT `fk_email_otps_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `trunk_line` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `local_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `role` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'office_staff',
  `office_id` int unsigned DEFAULT NULL,
  `office_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_office_id` (`office_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

