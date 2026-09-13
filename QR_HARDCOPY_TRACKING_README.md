# QR-Based Hardcopy Document Tracking

This version uses QR codes to identify a hardcopy document, show its current physical location, and preserve the final digital archive record.

## Current Flow

1. A Records Officer receives a finished Travel Order, Office Order, or Memorandum.
2. Open **Archive Intake** and upload the scanned PDF, JPG, JPEG, or PNG.
3. Run browser-side OCR with Tesseract.js when useful. OCR produces editable suggestions only.
4. Verify/correct the tracking number if present, document number when applicable, document name, name in document, subject, description, category, source/owner office, actual document creator/issuing authority, date of creation, and page count.
5. Select **Review Archive**. The system performs basic file-type, file-signature/readability, and file-integrity checks and shows an **Archive Intake Review** screen.
6. The Records Officer confirms that the file is readable, all pages are complete, the metadata matches the physical record, and OCR information is correct or was manually checked.
7. Select **Archive Document**. The record is stored with status `Archived` and becomes part of the digital logbook.
8. Archive Intake does **not** require QR/token validation and does not mark the record as normally DTRMS-validated. SHA-256 is retained only as a digital file-integrity mechanism.
9. Archived records remain searchable and exportable by tracking number, document number, date of creation, name in document, subject, description, category, year, month, and archive metadata.

## Stored Metadata

- `documents.qr_token` - secure QR token for normal DTRMS-tracked documents; Archive Intake records do not require a QR token
- `documents.document_person_name` - searchable person/name appearing in the document
- `documents.current_office_id` - current physical location
- `documents.received_date` - official date of creation
- `documents.file_sha256` - SHA-256 digital attachment hash used for file-integrity checking
- `documents.ocr_raw_text` / `documents.ocr_confidence` / `documents.ocr_status` - OCR extraction and review metadata
- `document_scan_logs` - QR scans, file validations, location updates, completion, archive, and review actions
- `document_timeline_events` - record creation, location updates, completion, archiving, OCR review, and file validation

Legacy route tables are preserved for old data, but the active UI no longer uses inbox/outbox handoffs.

## Archive Retrieval

The archive groups records by document category and date-of-creation month/year. Search supports tracking number, optional document number, name in document, subject, description, category, office/location, archive note, and archiver. The archive page and reports can export CSV files that open in Excel.

## Local Testing Note

If testing on XAMPP and scanning from a phone, do not print QR codes using `localhost`. Open the system using the computer LAN IP first, for example:

```text
http://192.168.1.10/prmsu-dtrms-qr_v2
```

Phones cannot open your computer's `localhost`.
