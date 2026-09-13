<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SystemHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user']);
    }

    public function testPasswordMinimumIsProductionStrength(): void
    {
        self::assertGreaterThanOrEqual(12, password_min_length());
    }

    public function testCsvCellsAreFormulaSafe(): void
    {
        self::assertSame("'=SUM(A1:A2)", csv_safe_cell('=SUM(A1:A2)'));
        self::assertSame('Regular text', csv_safe_cell('Regular text'));
    }

    public function testQrTokenExtractionAcceptsUrlAndToken(): void
    {
        $token = str_repeat('a', 64);
        self::assertSame($token, extract_qr_token($token));
        self::assertSame($token, extract_qr_token('https://example.test/documents/scan.php?t=' . $token));
        self::assertSame('', extract_qr_token('not-a-token'));
    }

    public function testDocumentQrTokenMatchChecksExpectedDocumentToken(): void
    {
        $token = str_repeat('a', 64);
        $otherToken = str_repeat('b', 64);
        $document = ['qr_token' => $token];

        self::assertTrue(document_qr_token_matches($document, $token));
        self::assertTrue(document_qr_token_matches($document, strtoupper($token)));
        self::assertTrue(document_qr_token_matches($document, 'https://example.test/documents/scan.php?t=' . strtoupper($token)));
        self::assertFalse(document_qr_token_matches($document, $otherToken));
        self::assertFalse(document_qr_token_matches($document, ''));
        self::assertFalse(document_qr_token_matches(['qr_token' => ''], $token));
        self::assertFalse(document_qr_token_matches([], $token));
    }

    public function testSearchHelpersBuildSafeClauses(): void
    {
        $like = build_like_search_clause('travel order', ['d.subject', 'd.description'], 'test_q');
        self::assertStringContainsString('d.subject LIKE :test_q_0', $like['sql']);
        self::assertSame('%travel order%', $like['params']['test_q_0']);

        self::assertSame('+travel* +order*', fulltext_boolean_query('travel order!'));
    }

    public function testOfficeContactLabelFormatsAvailableContacts(): void
    {
        self::assertSame('Trunk: 047-123-4567 | Local: 123', office_contact_label('047-123-4567', '123'));
        self::assertSame('Trunk: 047-123-4567', office_contact_label('047-123-4567', ''));
        self::assertSame('Local: 123', office_contact_label(null, '123'));
        self::assertSame('-', office_contact_label('  ', null));
    }

    public function testArchiveAccessConditionUsesCreatorOfficeForOfficeUsers(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 3,
        ];

        $scope = document_archive_access_condition('d', 'archive_test_');

        self::assertSame('(d.origin_office_id = :archive_test_origin_office_id)', $scope['sql']);
        self::assertSame(['archive_test_origin_office_id' => 3], $scope['params']);
    }

    public function testOfficeUserCanViewRecordsArchivedDocumentFromOwnOriginOffice(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 3,
        ];

        $document = [
            'status' => 'Archived',
            'created_by' => 8,
            'origin_office_id' => 3,
            'current_office_id' => 1,
            'category_name' => 'Travel Order',
        ];

        self::assertTrue(current_user_can_view_archived_source_document($document));
        self::assertTrue(can_access_document($document));
    }

    public function testOfficeUserCannotViewOtherOfficeRecordsArchivedDocument(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 3,
        ];

        $document = [
            'status' => 'Archived',
            'created_by' => 8,
            'origin_office_id' => 4,
            'current_office_id' => 1,
            'category_name' => 'Travel Order',
        ];

        self::assertFalse(current_user_can_view_archived_source_document($document));
        self::assertFalse(can_access_document($document));
    }

    public function testOfficeUserCannotArchiveOrRestoreRecordsOfficeArchiveCategory(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 3,
        ];

        $completedDocument = [
            'status' => 'Completed',
            'origin_office_id' => 3,
            'current_office_id' => 1,
            'category_name' => 'Travel Order',
        ];
        $archivedDocument = [
            'status' => 'Archived',
            'origin_office_id' => 3,
            'current_office_id' => 1,
            'category_name' => 'Travel Order',
        ];

        self::assertFalse(document_can_archive($completedDocument, null));
        self::assertFalse(document_can_restore_archive($archivedDocument));
    }

    public function testRecordsOfficerArchiveAccessRemainsLimitedToRecordsCategories(): void
    {
        $_SESSION['user'] = [
            'id' => 5,
            'role' => 'records_officer',
            'office_id' => 1,
        ];

        $scope = document_archive_access_condition('d', 'records_archive_test_');

        self::assertStringContainsString('d.category_id IN', $scope['sql']);
        self::assertStringNotContainsString('origin_office_id', $scope['sql']);
        self::assertSame([
            'records_archive_test_records_category_0' => 'travel order',
            'records_archive_test_records_category_1' => 'office order',
            'records_archive_test_records_category_2' => 'memorandum',
        ], $scope['params']);
    }

    public function testDocumentIndexStatusFiltersStayCompleteForOfficeUsers(): void
    {
        foreach (['office_staff', 'issuing_authority'] as $role) {
            $_SESSION['user'] = [
                'id' => 7,
                'role' => $role,
                'office_id' => 3,
            ];

            self::assertSame(
                ['Draft', 'Submitted', 'Rejected', 'Under Action', 'Completed'],
                document_index_status_filter_options()
            );
        }
    }

    public function testDocumentIndexStatusFiltersLimitRecordsOfficerChoices(): void
    {
        $_SESSION['user'] = [
            'id' => 5,
            'role' => 'records_officer',
            'office_id' => 1,
        ];

        self::assertSame(['Completed'], document_index_status_filter_options());
    }

    public function testReportStatusFiltersStayCompleteForOfficeAndIssuingUsers(): void
    {
        foreach (['office_staff', 'issuing_authority'] as $role) {
            $_SESSION['user'] = [
                'id' => 7,
                'role' => $role,
                'office_id' => 3,
            ];

            self::assertSame(
                ['Draft', 'Submitted', 'Rejected', 'Under Action', 'Completed', 'Archived'],
                document_report_status_filter_options()
            );
        }
    }

    public function testReportStatusFiltersLimitRecordsOfficerChoices(): void
    {
        $_SESSION['user'] = [
            'id' => 5,
            'role' => 'records_officer',
            'office_id' => 1,
        ];

        self::assertSame(['Completed', 'Archived'], document_report_status_filter_options());
    }

    public function testOfficeReportScopeUsesOnlyCurrentCreator(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 3,
        ];

        $scope = document_report_scope_condition('d', 'report_test_');

        self::assertSame('(d.created_by = :report_test_creator_user_id)', $scope['sql']);
        self::assertStringNotContainsString('origin_office_id', $scope['sql']);
        self::assertStringNotContainsString('current_office_id', $scope['sql']);
        self::assertStringNotContainsString('source_category', $scope['sql']);
        self::assertStringNotContainsString('category_id NOT IN', $scope['sql']);
        self::assertSame([
            'report_test_creator_user_id' => 7,
        ], $scope['params']);
    }

    public function testIssuingAuthorityReportScopeUsesCreatorAndOfficeCreators(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'issuing_authority',
            'office_id' => 3,
        ];

        $scope = document_report_scope_condition('d', 'report_test_');

        self::assertStringContainsString('d.created_by = :report_test_creator_user_id', $scope['sql']);
        self::assertStringContainsString('d.created_by IN (SELECT id FROM users WHERE office_id = :report_test_creator_office_id)', $scope['sql']);
        self::assertStringNotContainsString('origin_office_id', $scope['sql']);
        self::assertStringNotContainsString('current_office_id', $scope['sql']);
        self::assertStringNotContainsString('source_category', $scope['sql']);
        self::assertStringNotContainsString('category_id NOT IN', $scope['sql']);
        self::assertSame([
            'report_test_creator_user_id' => 7,
            'report_test_creator_office_id' => 3,
        ], $scope['params']);
    }

    public function testRecordsOfficerReportScopeRemainsLimitedToRecordsCategories(): void
    {
        $_SESSION['user'] = [
            'id' => 5,
            'role' => 'records_officer',
            'office_id' => 1,
        ];

        $scope = document_report_scope_condition('d', 'records_report_test_');

        self::assertStringContainsString('d.category_id IN', $scope['sql']);
        self::assertStringNotContainsString('origin_office_id', $scope['sql']);
        self::assertSame([
            'records_report_test_records_category_0' => 'travel order',
            'records_report_test_records_category_1' => 'office order',
            'records_report_test_records_category_2' => 'memorandum',
        ], $scope['params']);
    }

    public function testRecordsOfficeArchiveCategoryNameMatcherAllowsOnlyRecordsCategories(): void
    {
        self::assertTrue(records_office_archive_category_name_matches('Memorandum'));
        self::assertTrue(records_office_archive_category_name_matches(' travel order '));
        self::assertTrue(records_office_archive_category_name_matches('OFFICE ORDER'));
        self::assertFalse(records_office_archive_category_name_matches('Purchase Request'));
    }

    public function testOfficeDocumentAccessConditionUsesUniqueCreatorOfficialPlaceholder(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 3,
        ];

        $scope = document_access_condition('d', 'scope_');

        self::assertStringContainsString("d.status IN ('Under Action', 'Completed') AND d.created_by = :scope_creator_official_user_id", $scope['sql']);
        self::assertSame(7, $scope['params']['scope_user_id']);
        self::assertSame(7, $scope['params']['scope_creator_official_user_id']);

        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $scope['sql'], $matches);
        $placeholders = $matches[1];

        self::assertSame($placeholders, array_unique($placeholders));
    }

    public function testCreatorsCanEditOwnDraftRejectedAndOfficialNonArchivedDocuments(): void
    {
        foreach (['office_staff', 'issuing_authority'] as $role) {
            $_SESSION['user'] = [
                'id' => 7,
                'role' => $role,
                'office_id' => 3,
            ];

            foreach (['Draft', 'Rejected', 'Under Action', 'Completed'] as $status) {
                $document = [
                    'status' => $status,
                    'created_by' => 7,
                    'origin_office_id' => 3,
                    'current_office_id' => 4,
                ];

                self::assertTrue(can_edit_document_record($document), $role . ' should edit own ' . $status . ' document.');
                self::assertSame(
                    in_array($status, ['Under Action', 'Completed'], true),
                    can_edit_document_creator_official_metadata($document),
                    $role . ' creator official metadata helper mismatch for ' . $status . '.'
                );
            }
        }
    }

    public function testCreatorsCannotEditOwnSubmittedOrArchivedDocuments(): void
    {
        foreach (['office_staff', 'issuing_authority'] as $role) {
            $_SESSION['user'] = [
                'id' => 7,
                'role' => $role,
                'office_id' => 3,
            ];

            foreach (['Submitted', 'Archived'] as $status) {
                $document = [
                    'status' => $status,
                    'created_by' => 7,
                    'origin_office_id' => 3,
                    'current_office_id' => 4,
                ];

                self::assertFalse(can_edit_document_creator_official_metadata($document));
                self::assertFalse(can_edit_document_record($document), $role . ' should not edit own ' . $status . ' document.');
            }
        }
    }

    public function testNonCreatorsCannotEditOfficialDocumentsThroughCreatorPermission(): void
    {
        foreach (['office_staff', 'issuing_authority'] as $role) {
            $_SESSION['user'] = [
                'id' => 7,
                'role' => $role,
                'office_id' => 3,
            ];

            foreach (['Under Action', 'Completed'] as $status) {
                $document = [
                    'status' => $status,
                    'created_by' => 8,
                    'origin_office_id' => 3,
                    'current_office_id' => 3,
                ];

                self::assertFalse(can_edit_document_creator_official_metadata($document));
                self::assertFalse(can_edit_document_record($document), $role . ' should not edit another creator\'s ' . $status . ' document.');
            }
        }
    }

    public function testRecordsOfficerArchiveLockHidesEditAndLogsButAllowsQrPrinting(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'records_officer',
            'office_id' => 1,
        ];

        foreach (['Completed', 'Archived'] as $status) {
            foreach (records_office_archive_category_names() as $categoryName) {
                $document = [
                    'status' => $status,
                    'category_name' => $categoryName,
                    'created_by' => 7,
                    'origin_office_id' => 1,
                    'current_office_id' => 1,
                    'qr_token' => str_repeat('a', 64),
                ];

                self::assertTrue(document_is_records_office_archive_lock_target($document));
                self::assertTrue(document_is_locked_records_archive_for_current_user($document));
                self::assertFalse(can_edit_document_record($document));
                self::assertFalse(can_view_document_private_logs($document));
                self::assertTrue(document_can_print_qr($document));
            }
        }
    }

    public function testRecordsOfficerArchiveLockDoesNotApplyToOtherRolesOrCategories(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'records_officer',
            'office_id' => 1,
        ];

        $otherCategoryDocument = [
            'status' => 'Completed',
            'category_name' => 'General',
            'created_by' => 8,
            'origin_office_id' => 2,
            'current_office_id' => 3,
            'qr_token' => str_repeat('b', 64),
        ];

        self::assertFalse(document_is_records_office_archive_lock_target($otherCategoryDocument));
        self::assertFalse(document_is_locked_records_archive_for_current_user($otherCategoryDocument));
        self::assertTrue(can_edit_document_record($otherCategoryDocument));
        self::assertTrue(can_view_document_private_logs($otherCategoryDocument));
        self::assertTrue(document_can_print_qr($otherCategoryDocument));

        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 1,
        ];

        $recordsCategoryDocument = [
            'status' => 'Completed',
            'category_name' => 'Travel Order',
            'created_by' => 7,
            'origin_office_id' => 1,
            'current_office_id' => 2,
            'qr_token' => str_repeat('c', 64),
        ];

        self::assertTrue(document_is_records_office_archive_lock_target($recordsCategoryDocument));
        self::assertFalse(document_is_locked_records_archive_for_current_user($recordsCategoryDocument));
        self::assertTrue(can_edit_document_record($recordsCategoryDocument));
        self::assertTrue(can_view_document_private_logs($recordsCategoryDocument));
        self::assertTrue(document_can_print_qr($recordsCategoryDocument));
    }

    public function testOfficeCreatorCanAccessOwnOfficialNonArchivedDocumentOutsideCurrentOffice(): void
    {
        $_SESSION['user'] = [
            'id' => 7,
            'role' => 'office_staff',
            'office_id' => 3,
        ];

        foreach (['Under Action', 'Completed'] as $status) {
            $document = [
                'status' => $status,
                'created_by' => 7,
                'origin_office_id' => 4,
                'current_office_id' => 5,
                'category_name' => 'Travel Order',
            ];

            self::assertTrue(can_access_document($document), 'Creator should access own ' . $status . ' document outside current office.');
        }
    }

    public function testBackupFilenamesAllowEncryptedAndLegacyOnly(): void
    {
        self::assertTrue(restore_backup_filename_is_valid('backup-2026-07-16-120000.zip'));
        self::assertTrue(restore_backup_filename_is_valid('backup-2026-07-16-120000.zip.enc'));
        self::assertFalse(restore_backup_filename_is_valid('../backup-2026-07-16-120000.zip'));
        self::assertFalse(restore_backup_filename_is_valid('backup-latest.zip'));
    }

    public function testBackupEncryptionRoundTrip(): void
    {
        if (!backup_encryption_key_is_configured()) {
            self::markTestSkipped('BACKUP_ENCRYPTION_KEY is not configured.');
        }

        $source = tempnam(sys_get_temp_dir(), 'dtrms-src-');
        $encrypted = tempnam(sys_get_temp_dir(), 'dtrms-enc-');
        $decrypted = tempnam(sys_get_temp_dir(), 'dtrms-dec-');
        self::assertIsString($source);
        self::assertIsString($encrypted);
        self::assertIsString($decrypted);

        try {
            file_put_contents($source, 'encrypted backup payload');
            backup_encrypt_file($source, $encrypted);
            self::assertTrue(backup_file_is_encrypted($encrypted));

            backup_decrypt_file_to($encrypted, $decrypted);
            self::assertSame('encrypted backup payload', file_get_contents($decrypted));
        } finally {
            @unlink($source);
            @unlink($encrypted);
            @unlink($decrypted);
        }
    }
}
