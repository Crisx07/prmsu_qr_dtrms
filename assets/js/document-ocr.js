document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-ocr-form]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const fileInput = form.querySelector('[data-ocr-file]');
    const cameraInput = form.querySelector('[data-ocr-camera-file]');
    const runButton = form.querySelector('[data-ocr-run]');
    const statusEl = form.querySelector('[data-ocr-status]');
    const progressEl = form.querySelector('[data-ocr-progress]');
    const rawEl = form.querySelector('[data-ocr-raw]');
    const confidenceEl = form.querySelector('[data-ocr-confidence]');

    if (!(fileInput instanceof HTMLInputElement) || !(runButton instanceof HTMLButtonElement)) {
        return;
    }

    const attachmentFieldName = fileInput.getAttribute('name') || 'attachment';
    const fileInputWasRequired = fileInput.required;

    const usePrimaryAttachmentInput = () => {
        fileInput.setAttribute('name', attachmentFieldName);
        fileInput.required = fileInputWasRequired;
        if (cameraInput instanceof HTMLInputElement) {
            cameraInput.removeAttribute('name');
            cameraInput.required = false;
        }
    };

    const useCameraAttachmentInput = () => {
        if (!(cameraInput instanceof HTMLInputElement)) {
            return;
        }

        fileInput.removeAttribute('name');
        fileInput.required = false;
        cameraInput.setAttribute('name', attachmentFieldName);
        cameraInput.required = fileInputWasRequired;
    };

    const copyFilesToInput = (target, sourceFiles) => {
        if (!sourceFiles || sourceFiles.length === 0 || typeof DataTransfer !== 'function') {
            return false;
        }

        try {
            const transfer = new DataTransfer();
            Array.from(sourceFiles).forEach((file) => transfer.items.add(file));
            target.files = transfer.files;
            return target.files.length > 0;
        } catch (error) {
            return false;
        }
    };

    const selectedOcrFile = () => {
        if (fileInput.files && fileInput.files[0]) {
            return fileInput.files[0];
        }
        if (cameraInput instanceof HTMLInputElement && cameraInput.files && cameraInput.files[0]) {
            return cameraInput.files[0];
        }

        return null;
    };

    const fields = {
        tracking_no: form.querySelector('[data-ocr-field="tracking_no"]'),
        document_number: form.querySelector('[data-ocr-field="document_number"]'),
        document_name: form.querySelector('[data-ocr-field="document_name"]'),
        document_person_name: form.querySelector('[data-ocr-field="document_person_name"]'),
        subject: form.querySelector('[data-ocr-field="subject"]'),
        description: form.querySelector('[data-ocr-field="description"]'),
        category: form.querySelector('[data-ocr-field="category"]'),
        source_office: form.querySelector('[data-ocr-field="source_office"]'),
        creation_date: form.querySelector('[data-ocr-field="creation_date"]'),
    };

    const MAX_RENDER_EDGE = 3200;
    const MIN_IMAGE_EDGE = 1800;
    const PDF_RENDER_SCALE = 3;
    const LOW_CONFIDENCE_LINE_THRESHOLD = 25;
    const BASE_OCR_PARAMETERS = {
        preserve_interword_spaces: '1',
        user_defined_dpi: '300',
    };
    const REGION_OCR_PARAMETERS = {
        ...BASE_OCR_PARAMETERS,
        tessedit_pageseg_mode: '4',
    };
    const FALLBACK_OCR_PARAMETERS = {
        ...BASE_OCR_PARAMETERS,
        tessedit_pageseg_mode: '3',
    };

    const setStatus = (message) => {
        if (statusEl instanceof HTMLElement) {
            statusEl.textContent = message;
        }
    };

    const setProgress = (ratio) => {
        if (progressEl instanceof HTMLElement) {
            progressEl.style.width = `${Math.max(0, Math.min(1, ratio)) * 100}%`;
        }
    };

    const fieldValue = (field) => {
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
            return field.value.trim();
        }

        return '';
    };

    const setField = (name, value) => {
        const field = fields[name];
        const cleanValue = String(value || '').trim();
        if (!cleanValue || !(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement)) {
            return;
        }

        if ((name === 'source_office' || name === 'category') && field instanceof HTMLSelectElement) {
            const needle = cleanValue.toLowerCase();
            const option = Array.from(field.options).find((item) => {
                const itemName = item.getAttribute(name === 'source_office' ? 'data-office-name' : 'data-category-name') || item.textContent || '';
                return itemName.toLowerCase().includes(needle) || needle.includes(itemName.toLowerCase());
            });
            if (option) {
                field.value = option.value;
            }
            return;
        }

        if (name === 'creation_date' && field instanceof HTMLInputElement) {
            const normalizedDate = normalizeDateSuggestion(cleanValue);
            if (normalizedDate) {
                field.value = normalizedDate;
            }
            return;
        }

        if (fieldValue(field) === '') {
            field.value = cleanValue;
        }
    };

    const normalizeText = (text) => String(text || '')
        .replace(/\r/g, '\n')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{3,}/g, '\n\n')
        .trim();

    const stripUnknownCharacters = (value) => String(value || '')
        .replace(/[\u00A7\u00A9\u00AE\u2122\uFFFD\u25A0-\u25FF\uFFFC]/g, ' ')
        .replace(/[\u200B-\u200D\uFEFF]/g, '')
        .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, ' ');

    const cleanOcrLine = (line) => stripUnknownCharacters(line)
        .replace(/[\u2018\u2019]/g, "'")
        .replace(/[\u201C\u201D]/g, '"')
        .replace(/[\u2010-\u2015]/g, '-')
        .replace(/[\u2022\u00B7]/g, ' ')
        .replace(/[|{}[\]<>~`^_=+\\]/g, ' ')
        .replace(/\bT0\b/g, 'TO')
        .replace(/\bN0\b/g, 'No')
        .replace(/\b0FFICE\b/gi, 'OFFICE')
        .replace(/\b0ffice\b/g, 'Office')
        .replace(/\bOftice\b/gi, 'Office')
        .replace(/\bDocurnent\b/gi, 'Document')
        .replace(/\bNurnber\b/gi, 'Number')
        .replace(/\bUNlVERSITY\b/g, 'UNIVERSITY')
        .replace(/\bUnlversity\b/g, 'University')
        .replace(/\s+/g, ' ')
        .trim();

    const isJunkOcrLine = (line) => {
        const text = cleanOcrLine(line);
        const visible = text.replace(/\s/g, '');
        if (!visible || visible.length <= 1) {
            return true;
        }

        const letters = (text.match(/[A-Za-z]/g) || []).length;
        const digits = (text.match(/\d/g) || []).length;
        const useful = letters + digits;
        const symbols = Math.max(0, visible.length - useful);

        if (visible.length <= 3 && useful === 0) {
            return true;
        }
        if (visible.length >= 4 && useful === 0) {
            return true;
        }
        if (symbols / visible.length > 0.58 && useful < 4) {
            return true;
        }

        return /^(?:[-.,:;'"/*#()]+|\d{1,2}[.,:;'"/*#()]+)$/.test(text);
    };

    const compactLineKey = (line) => cleanOcrLine(line)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '');

    const bigramSet = (value) => {
        const grams = new Set();
        for (let index = 0; index < value.length - 1; index += 1) {
            grams.add(value.slice(index, index + 2));
        }

        return grams;
    };

    const lineSimilarity = (left, right) => {
        if (left === right) {
            return 1;
        }
        if (left.length < 6 || right.length < 6) {
            return 0;
        }

        const leftSet = bigramSet(left);
        const rightSet = bigramSet(right);
        let shared = 0;
        leftSet.forEach((gram) => {
            if (rightSet.has(gram)) {
                shared += 1;
            }
        });

        return (2 * shared) / Math.max(1, leftSet.size + rightSet.size);
    };

    const isDuplicateOcrLine = (key, seenKeys) => {
        if (key.length < 4) {
            return seenKeys.has(key);
        }

        for (const seenKey of seenKeys) {
            if (key === seenKey) {
                return true;
            }
            if (key.length >= 18 && seenKey.length >= 18) {
                const shorter = key.length < seenKey.length ? key : seenKey;
                const longer = key.length < seenKey.length ? seenKey : key;
                if (longer.includes(shorter) && shorter.length / longer.length >= 0.78) {
                    return true;
                }
                if (lineSimilarity(key, seenKey) >= 0.88) {
                    return true;
                }
            }
        }

        return false;
    };

    const cleanOcrText = (text) => {
        const lines = String(text || '')
            .replace(/\r/g, '\n')
            .split('\n')
            .map(cleanOcrLine)
            .filter((line) => line && !isJunkOcrLine(line));
        const seenKeys = new Set();
        const cleaned = [];

        lines.forEach((line) => {
            const key = compactLineKey(line);
            if (!key || isDuplicateOcrLine(key, seenKeys)) {
                return;
            }
            seenKeys.add(key);
            cleaned.push(line);
        });

        return normalizeText(cleaned.join('\n'));
    };

    const cleanFieldValue = (value, maxLength = 180) => cleanOcrLine(value)
        .replace(/^[.:;\-\s]+/, '')
        .replace(/[.:;\-\s]+$/, '')
        .slice(0, maxLength)
        .trim();

    const normalizePrmsuMemoSourceLine = (line) => cleanOcrLine(line)
        .replace(/^(?:R|H)\s+(?=President\s+Ramon)/i, '')
        .replace(/^(?:Qa|Qq|Oa|0a)\s+H\s+(?=Iba\b)/i, '')
        .replace(/^\d+\s+(?=Email\b)/i, '')
        .replace(/^(?:mr|m)\s+(?=Website\b)/i, '')
        .replace(/\bBAGONG\s+PILIPINAS\b/gi, '')
        .replace(/\bPRMS\b$/i, 'PRMSU')
        .replace(/\bprmsu\.cdu\.ph\b/gi, 'prmsu.edu.ph')
        .replace(/https:\s*['"]?\s*/i, 'https://')
        .replace(/\s+/g, ' ')
        .trim();

    const prmsuMemoLines = (text) => cleanOcrText(text)
        .split('\n')
        .map(normalizePrmsuMemoSourceLine)
        .filter(Boolean);

    const isPrmsuPresidentMemoText = (text) => {
        const lines = prmsuMemoLines(text);
        const joined = lines.join('\n').toLowerCase();
        const compact = compactLineKey(joined);
        let score = 0;

        if (compact.includes('presidentramonmagsaysaystateuniversity')) {
            score += 2;
        }
        if (compact.includes('officeoftheuniversitypresident')) {
            score += 2;
        }
        if (/\bmemo(?:randum)?\b/i.test(joined)) {
            score += 1;
        }
        if (/\bno\.?\s*\d+\s*s\.?\s*\d{4}\b/i.test(joined)) {
            score += 1;
        }
        if (lines.some((line) => /^TO\b/i.test(line))) {
            score += 1;
        }
        if (lines.some((line) => /^FROM\b/i.test(line))) {
            score += 1;
        }
        if (lines.some((line) => /^DATE\b/i.test(line))) {
            score += 1;
        }
        if (lines.some((line) => /^SUBJECT\b/i.test(line))) {
            score += 1;
        }

        return score >= 6;
    };

    const isPrmsuMemoNoiseLine = (line) => {
        const text = normalizePrmsuMemoSourceLine(line);
        if (!text) {
            return true;
        }

        return /^(?:republic of the philippines|bagong pilipinas|copy furnished|all concerned|hrmo|records office|oup file|university president)$/i.test(text)
            || /^(?:prmsu|president ramon magsaysay state university seal)$/i.test(text);
    };

    const cleanMemoFieldValue = (value, maxLength = 220) => cleanFieldValue(value, maxLength)
        .replace(/^[+.:;\-\s]+/, '')
        .replace(/[~\/]+$/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    const extractPrmsuDocumentNumber = (lines) => {
        const joined = lines.join('\n');
        const match = joined.match(/\bNo\.?\s*([A-Za-z0-9-]+)\s*s\.?\s*(\d{4})\b/i);

        return match ? `No. ${match[1]} s. ${match[2]}` : '';
    };

    const prmsuFieldLinePattern = (label) => new RegExp(`^${label}\\s*:?\\s*(.*)$`, 'i');

    const extractPrmsuSingleField = (lines, label) => {
        const pattern = prmsuFieldLinePattern(label);
        for (const line of lines) {
            const match = line.match(pattern);
            if (match) {
                return cleanMemoFieldValue(match[1] || '', 220).toUpperCase();
            }
        }

        return '';
    };

    const extractPrmsuToLines = (lines) => {
        const toLines = [];
        let collecting = false;

        for (const line of lines) {
            if (!collecting) {
                const match = line.match(/^TO\s*:?\s*(.*)$/i);
                if (!match) {
                    continue;
                }
                collecting = true;
                const firstValue = cleanMemoFieldValue(match[1] || '', 220).toUpperCase();
                if (firstValue) {
                    toLines.push(firstValue);
                }
                continue;
            }

            if (/^(?:FROM|DATE|SUBJECT|RE)\b/i.test(line) || /^\d+(?:\.\d+)?\b/.test(line)) {
                break;
            }
            if (isPrmsuMemoNoiseLine(line)) {
                continue;
            }

            const value = cleanMemoFieldValue(line, 220).toUpperCase();
            if (value) {
                toLines.push(value);
            }
        }

        return toLines;
    };

    const cleanMemoBodyLine = (line) => cleanOcrLine(line)
        .replace(/^[+.:;\-\s]+/, '')
        .replace(/[~\/]+$/g, '')
        .replace(/\s+([,.;:])/g, '$1')
        .replace(/\s+/g, ' ')
        .trim();

    const normalizePrmsuSignature = (line) => {
        if (/roy\b.*villalobos/i.test(line)) {
            return 'ROY N. VILLALOBOS, DPA';
        }

        return '';
    };

    const extractNumberedMemoBody = (lines) => {
        const items = [];
        let current = '';
        let started = false;

        const pushCurrent = () => {
            const value = current.trim();
            if (value) {
                items.push(value);
            }
            current = '';
        };

        for (const line of lines) {
            const cleaned = cleanMemoBodyLine(line);
            if (!cleaned) {
                continue;
            }
            if (/^(?:copy furnished|all concerned|hrmo|records office|oup file)\b/i.test(cleaned)) {
                break;
            }
            if (normalizePrmsuSignature(cleaned) || /^university president$/i.test(cleaned)) {
                if (started) {
                    break;
                }
                continue;
            }

            const itemMatch = cleaned.match(/^(\d+(?:\.\d+)?)\s+(.+)$/);
            if (itemMatch) {
                started = true;
                pushCurrent();
                current = `${itemMatch[1]} ${itemMatch[2]}`;
                continue;
            }

            if (started && !/^(?:TO|FROM|DATE|SUBJECT|MEMORANDUM|No\.?)\b/i.test(cleaned)) {
                current += `${current ? ' ' : ''}${cleaned}`;
            }
        }

        pushCurrent();

        return items;
    };

    const normalizePrmsuMemoText = (text) => {
        if (!isPrmsuPresidentMemoText(text)) {
            return cleanOcrText(text);
        }

        const lines = prmsuMemoLines(text).filter((line) => !isPrmsuMemoNoiseLine(line));
        const documentNumber = extractPrmsuDocumentNumber(lines);
        const toLines = extractPrmsuToLines(lines);
        const from = extractPrmsuSingleField(lines, 'FROM') || 'UNIVERSITY PRESIDENT';
        const date = extractPrmsuSingleField(lines, 'DATE');
        const subject = extractPrmsuSingleField(lines, 'SUBJECT');
        const bodyItems = extractNumberedMemoBody(lines);
        const signature = lines.map(normalizePrmsuSignature).find(Boolean) || '';
        const output = [
            'President Ramon Magsaysay State University',
            'Iba, Zambales, Philippines 2201',
            'Email: universitypresident@prmsu.edu.ph | rmtupresident@yahoo.com',
            'Website: https://prmsu.edu.ph/PRMSU',
            'Telephone No.: (047) 602-6120-24',
            'OFFICE OF THE UNIVERSITY PRESIDENT',
            '--000--',
        ];

        if (documentNumber) {
            output.push(documentNumber);
        }
        if (toLines.length) {
            output.push(`TO : ${toLines[0]}`);
            output.push(...toLines.slice(1));
        }
        if (from) {
            output.push(`FROM: : ${from}`);
        }
        if (date) {
            output.push(`DATE: ${date}`);
        }
        if (subject) {
            output.push(`SUBJECT : ${subject}`);
        }
        if (bodyItems.length) {
            output.push('', ...bodyItems);
        }
        if (signature) {
            output.push('', signature);
        }

        return normalizeText(output.join('\n'));
    };

    const normalizeDateSuggestion = (value) => {
        const text = cleanFieldValue(value, 80);
        let match = text.match(/\b(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})\b/);
        if (match) {
            return `${match[1]}-${match[2].padStart(2, '0')}-${match[3].padStart(2, '0')}`;
        }

        match = text.match(/\b(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})\b/);
        if (match) {
            const year = match[3].length === 2 ? `20${match[3]}` : match[3];
            return `${year}-${match[1].padStart(2, '0')}-${match[2].padStart(2, '0')}`;
        }

        match = text.match(/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan\.?|Feb\.?|Mar\.?|Apr\.?|Jun\.?|Jul\.?|Aug\.?|Sep\.?|Sept\.?|Oct\.?|Nov\.?|Dec\.?)\s+(\d{1,2}),?\s+(\d{4})\b/i);
        if (match) {
            const monthNames = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
            const month = monthNames.findIndex((name) => match[1].toLowerCase().startsWith(name)) + 1;
            if (month > 0) {
                return `${match[3]}-${String(month).padStart(2, '0')}-${match[2].padStart(2, '0')}`;
            }
        }

        return '';
    };

    const textLooksUseful = (text, minLength = 40) => {
        const normalized = cleanOcrText(text);
        const letters = (normalized.match(/[A-Za-z]/g) || []).length;
        return normalized.length >= minLength && letters >= Math.min(24, minLength / 2);
    };

    const textLines = (text) => cleanOcrText(text)
        .split('\n')
        .map(cleanOcrLine)
        .filter(Boolean);

    const valueAfterLabel = (text, labels, maxLength = 180) => {
        const lines = textLines(text);
        for (const line of lines) {
            for (const label of labels) {
                const pattern = new RegExp(`^${label}\\s*[:\\-]?\\s*(.+)$`, 'i');
                const match = line.match(pattern);
                if (match && match[1]) {
                    return cleanFieldValue(match[1], maxLength);
                }
            }
        }

        return '';
    };

    const valueAfterLabelBlock = (lines, labels, maxLength = 180, maxContinuationLines = 2) => {
        const labelPattern = new RegExp(`^(?:${labels.join('|')})\\s*[:\\-]?\\s*(.*)$`, 'i');
        const stopPattern = /^(?:memorandum|document\s*(?:number|name)|doc\.?\s*(?:number|name)|no\.?|to|from|date|subject|re|description|particulars|purpose|remarks|copy furnished)\b|^\d+(?:\.\d+)?\b/i;

        for (let index = 0; index < lines.length; index += 1) {
            const match = lines[index].match(labelPattern);
            if (!match) {
                continue;
            }

            const parts = [];
            if (match[1]) {
                parts.push(match[1]);
            }

            for (let offset = 1; offset <= maxContinuationLines && index + offset < lines.length; offset += 1) {
                const next = lines[index + offset];
                if (stopPattern.test(next)) {
                    break;
                }
                parts.push(next);
            }

            const value = cleanFieldValue(parts.join(' '), maxLength);
            if (value) {
                return value;
            }
        }

        return '';
    };

    const firstUsefulLine = (text) => {
        const ignored = /^(republic|president ramon|campus|office|tel|email|date)\b/i;
        return textLines(text)
            .filter((line) => line.length >= 8 && !ignored.test(line))[0] || '';
    };

    const extractMemoDocumentNumber = (lines) => {
        const memoIndex = lines.findIndex((line) => /^memorandum\b/i.test(line));
        const searchLines = memoIndex >= 0 ? lines.slice(memoIndex, memoIndex + 5) : lines;

        for (const line of searchLines) {
            const match = line.match(/\b(?:no|n0)(?:\.|\b)\s*([A-Za-z0-9][A-Za-z0-9.\-\s]*(?:s\.?\s*\d{4}|\d{4})?)/i);
            if (match && match[1]) {
                return cleanFieldValue(`No. ${match[1]}`, 80)
                    .replace(/\bS\.\s*(\d{4})$/i, 's. $1')
                    .replace(/\s+/g, ' ');
            }
        }

        return '';
    };

    const extractTrackingNumber = (text) => {
        const labeled = valueAfterLabel(text, [
            'document\\s*tracking\\s*(?:number|no\\.?|#)',
            'tracking\\s*(?:number|no\\.?|#)',
            'tracking\\b',
            'control\\s*(?:number|no\\.?)',
        ], 80);
        if (labeled) {
            return labeled;
        }

        const match = normalizeText(text).match(/\b\d{4}-[A-Z0-9]{2,}(?:-[A-Z0-9]{2,})*-\d{3,6}\b/i);
        return match ? cleanFieldValue(match[0], 80).toUpperCase() : '';
    };

    const inferArchiveCategory = (text) => {
        const normalized = normalizeText(text);
        if (/\btravel\s+orders?\b/i.test(normalized)) {
            return 'Travel Order';
        }
        if (/\boffice\s+orders?\b/i.test(normalized)) {
            return 'Office Order';
        }
        if (/\bmemo(?:randum|randa)?\b/i.test(normalized)) {
            return 'Memorandum';
        }

        return '';
    };

    const extractMemoSuggestions = (text) => {
        const lines = textLines(text);
        const joined = lines.join('\n');
        const hasMemo = /\bmemo(?:randum)?\b/i.test(joined) || isPrmsuPresidentMemoText(joined);
        const sourceOfficeLine = lines.find((line) => /office\s+of\s+the\s+university\s+president/i.test(line));

        return {
            tracking_no: extractTrackingNumber(joined),
            document_number: hasMemo ? extractMemoDocumentNumber(lines) : '',
            document_name: hasMemo ? 'MEMORANDUM' : '',
            description: hasMemo ? extractNumberedMemoBody(lines).join('\n') : '',
            category: hasMemo ? 'Memorandum' : '',
            subject: valueAfterLabelBlock(lines, [
                'subject\\b',
                'subj(?:ect)?\\b',
                're\\b',
            ], 220, 2),
            creation_date: valueAfterLabel(joined, [
                'date',
                'date\\s+of\\s+creation',
                'created\\s+on',
            ], 80),
            source_office: sourceOfficeLine ? 'Office of the University President' : '',
        };
    };

    const parseSuggestions = (text, filename) => {
        const normalized = normalizeText(text);
        const cleanName = filename.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
        const memoSuggestions = extractMemoSuggestions(normalized);

        return {
            tracking_no: memoSuggestions.tracking_no || extractTrackingNumber(normalized),
            document_number: memoSuggestions.document_number || valueAfterLabel(normalized, [
                'document\\s*(?:number|no\\.?|#)',
                'doc\\.?\\s*(?:number|no\\.?|#)',
                'reference\\s*(?:number|no\\.?)',
                'control\\s*(?:number|no\\.?)',
                'no(?:\\.|\\b)',
            ], 80),
            document_name: memoSuggestions.document_name || valueAfterLabel(normalized, [
                'document\\s*name',
                'title',
            ], 180) || cleanName,
            document_person_name: valueAfterLabel(normalized, [
                'name\\s+in\\s+document',
                'person\\s*name',
                'employee\\s*name',
                'student\\s*name',
                'recipient\\s*name',
                'applicant\\s*name',
                'name',
            ], 180),
            subject: memoSuggestions.subject || valueAfterLabel(normalized, [
                'subject\\b',
                're\\b',
            ], 180) || firstUsefulLine(normalized),
            description: memoSuggestions.description || valueAfterLabel(normalized, [
                'description',
                'particulars',
                'purpose',
                'remarks',
            ], 600),
            category: memoSuggestions.category || valueAfterLabel(normalized, [
                'category',
                'classification',
                'document\\s*type',
                'type',
            ], 150) || inferArchiveCategory(normalized),
            source_office: memoSuggestions.source_office || valueAfterLabel(normalized, [
                'source\\s*office',
                'origin\\s*(?:office|unit)',
                'from',
                'office',
            ], 150),
            creation_date: memoSuggestions.creation_date || valueAfterLabel(normalized, [
                'date\\s+of\\s+creation',
                'creation\\s+date',
                'created\\s+on',
                'date',
            ], 80),
        };
    };

    const averageConfidence = (values) => {
        const numbers = values.filter((value) => typeof value === 'number' && !Number.isNaN(value));
        if (!numbers.length) {
            return '';
        }

        return numbers.reduce((sum, value) => sum + value, 0) / numbers.length;
    };

    const usefulCharacterCount = (text) => {
        const letters = (text.match(/[A-Za-z]/g) || []).length;
        const digits = (text.match(/\d/g) || []).length;

        return letters + digits;
    };

    const cleanRecognizedText = (data) => {
        const lines = Array.isArray(data.lines) ? data.lines : [];
        if (lines.length) {
            const cleanedLines = lines
                .map((line) => ({
                    text: cleanOcrLine(line && line.text ? line.text : ''),
                    confidence: line && typeof line.confidence === 'number' ? line.confidence : null,
                }))
                .filter((line) => line.text && !isJunkOcrLine(line.text))
                .filter((line) => line.confidence === null
                    || line.confidence >= LOW_CONFIDENCE_LINE_THRESHOLD
                    || usefulCharacterCount(line.text) >= 8)
                .map((line) => line.text);
            const textFromLines = cleanOcrText(cleanedLines.join('\n'));
            if (textFromLines) {
                return textFromLines;
            }
        }

        return cleanOcrText(data.text || '');
    };

    const groupTextItemsIntoLines = (items) => {
        const heights = items.map((item) => item.height).filter((height) => height > 0).sort((a, b) => a - b);
        const medianHeight = heights.length ? heights[Math.floor(heights.length / 2)] : 8;
        const yTolerance = Math.max(3, medianHeight * 0.75);
        const rows = [];

        items
            .slice()
            .sort((a, b) => b.y - a.y || a.x - b.x)
            .forEach((item) => {
                let row = rows.find((candidate) => Math.abs(candidate.y - item.y) <= yTolerance);
                if (!row) {
                    row = { y: item.y, items: [] };
                    rows.push(row);
                }
                row.items.push(item);
            });

        return rows
            .sort((a, b) => b.y - a.y)
            .map((row) => row.items
                .sort((a, b) => a.x - b.x)
                .map((item) => item.str)
                .join(' '))
            .map(cleanOcrLine)
            .filter(Boolean)
            .join('\n');
    };

    const extractPdfTextLayer = async (page) => {
        const content = await page.getTextContent({ normalizeWhitespace: true });
        const viewport = page.getViewport({ scale: 1 });
        const items = (content.items || [])
            .map((item) => {
                const str = cleanOcrLine(item.str || '');
                const transform = item.transform || [];
                if (!str || transform.length < 6) {
                    return null;
                }

                return {
                    str,
                    x: Number(transform[4]) || 0,
                    y: Number(transform[5]) || 0,
                    width: Number(item.width) || 0,
                    height: Math.abs(Number(transform[3]) || Number(item.height) || 0),
                };
            })
            .filter(Boolean);

        if (!items.length) {
            return '';
        }

        const isWideTwoUp = viewport.width > viewport.height * 1.15;
        if (!isWideTwoUp) {
            return cleanOcrText(groupTextItemsIntoLines(items));
        }

        const midpoint = viewport.width / 2;
        const leftText = groupTextItemsIntoLines(items.filter((item) => item.x < midpoint));
        const rightText = groupTextItemsIntoLines(items.filter((item) => item.x >= midpoint));

        return cleanOcrText([leftText, rightText].filter(Boolean).join('\n\n'));
    };

    const loadPdfFirstPage = async (file) => {
        if (!window.pdfjsLib) {
            throw new Error('PDF.js is not available.');
        }

        const buffer = await file.arrayBuffer();
        const pdf = await window.pdfjsLib.getDocument({ data: new Uint8Array(buffer) }).promise;
        return pdf.getPage(1);
    };

    const renderPdfPage = async (page) => {
        const baseViewport = page.getViewport({ scale: 1 });
        const maxBaseEdge = Math.max(baseViewport.width, baseViewport.height);
        const scale = Math.min(PDF_RENDER_SCALE, MAX_RENDER_EDGE / maxBaseEdge);
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        if (!(context instanceof CanvasRenderingContext2D)) {
            throw new Error('Canvas rendering is not available.');
        }

        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);

        await page.render({
            canvasContext: context,
            viewport,
        }).promise;

        return canvas;
    };

    const loadImageCanvas = async (file) => new Promise((resolve, reject) => {
        const image = new Image();
        const url = URL.createObjectURL(file);

        image.onload = () => {
            try {
                const sourceWidth = image.naturalWidth || image.width;
                const sourceHeight = image.naturalHeight || image.height;
                const sourceEdge = Math.max(sourceWidth, sourceHeight);
                const scale = Math.min(
                    MAX_RENDER_EDGE / sourceEdge,
                    sourceEdge < MIN_IMAGE_EDGE ? Math.min(2, MIN_IMAGE_EDGE / sourceEdge) : 1
                );
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                if (!(context instanceof CanvasRenderingContext2D)) {
                    throw new Error('Canvas rendering is not available.');
                }

                canvas.width = Math.max(1, Math.floor(sourceWidth * scale));
                canvas.height = Math.max(1, Math.floor(sourceHeight * scale));
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                resolve(canvas);
            } catch (error) {
                reject(error);
            } finally {
                URL.revokeObjectURL(url);
            }
        };

        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Unable to load the selected image for OCR.'));
        };

        image.src = url;
    });

    const preprocessCanvas = (canvas) => {
        const context = canvas.getContext('2d');
        if (!(context instanceof CanvasRenderingContext2D)) {
            return canvas;
        }

        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
        const data = imageData.data;
        for (let index = 0; index < data.length; index += 4) {
            const gray = (data[index] * 0.299) + (data[index + 1] * 0.587) + (data[index + 2] * 0.114);
            let adjusted = ((gray - 128) * 1.35) + 128;
            adjusted = Math.max(0, Math.min(255, adjusted));
            if (adjusted > 232) {
                adjusted = 255;
            } else if (adjusted < 105) {
                adjusted = 0;
            }

            data[index] = adjusted;
            data[index + 1] = adjusted;
            data[index + 2] = adjusted;
            data[index + 3] = 255;
        }

        context.putImageData(imageData, 0, 0);
        return canvas;
    };

    const trimWhitespace = (canvas, padding = 18) => {
        const context = canvas.getContext('2d');
        if (!(context instanceof CanvasRenderingContext2D)) {
            return canvas;
        }

        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
        const data = imageData.data;
        let minX = canvas.width;
        let minY = canvas.height;
        let maxX = -1;
        let maxY = -1;
        const step = 4;

        for (let y = 0; y < canvas.height; y += step) {
            for (let x = 0; x < canvas.width; x += step) {
                const index = ((y * canvas.width) + x) * 4;
                const gray = (data[index] + data[index + 1] + data[index + 2]) / 3;
                if (gray < 245) {
                    minX = Math.min(minX, x);
                    minY = Math.min(minY, y);
                    maxX = Math.max(maxX, x);
                    maxY = Math.max(maxY, y);
                }
            }
        }

        if (maxX < minX || maxY < minY) {
            return canvas;
        }

        minX = Math.max(0, minX - padding);
        minY = Math.max(0, minY - padding);
        maxX = Math.min(canvas.width - 1, maxX + padding);
        maxY = Math.min(canvas.height - 1, maxY + padding);

        const width = maxX - minX + 1;
        const height = maxY - minY + 1;
        if (width < 80 || height < 40 || (width > canvas.width * 0.95 && height > canvas.height * 0.95)) {
            return canvas;
        }

        const trimmed = document.createElement('canvas');
        const trimmedContext = trimmed.getContext('2d');
        if (!(trimmedContext instanceof CanvasRenderingContext2D)) {
            return canvas;
        }

        trimmed.width = width;
        trimmed.height = height;
        trimmedContext.fillStyle = '#fff';
        trimmedContext.fillRect(0, 0, width, height);
        trimmedContext.drawImage(canvas, minX, minY, width, height, 0, 0, width, height);
        return trimmed;
    };

    const cropCanvas = (source, region) => {
        const x = Math.max(0, Math.floor(source.width * region.x));
        const y = Math.max(0, Math.floor(source.height * region.y));
        const width = Math.min(source.width - x, Math.floor(source.width * region.width));
        const height = Math.min(source.height - y, Math.floor(source.height * region.height));
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        if (!(context instanceof CanvasRenderingContext2D) || width <= 0 || height <= 0) {
            return source;
        }

        canvas.width = width;
        canvas.height = height;
        context.fillStyle = '#fff';
        context.fillRect(0, 0, width, height);
        context.drawImage(source, x, y, width, height, 0, 0, width, height);

        return trimWhitespace(preprocessCanvas(canvas));
    };

    const buildOcrRegions = (canvas) => {
        const isWideTwoUp = canvas.width > canvas.height * 1.15;
        if (isWideTwoUp) {
            return [
                { label: 'left-page metadata OCR', x: 0.02, y: 0.08, width: 0.46, height: 0.34 },
                { label: 'left-page body OCR', x: 0.02, y: 0.43, width: 0.46, height: 0.37 },
                { label: 'right-page body OCR', x: 0.52, y: 0.08, width: 0.46, height: 0.72 },
            ];
        }

        return [
            { label: 'metadata OCR', x: 0.05, y: 0.08, width: 0.90, height: 0.36 },
            { label: 'body OCR', x: 0.05, y: 0.46, width: 0.90, height: 0.42 },
        ];
    };

    const createWorker = async (state, parameters = REGION_OCR_PARAMETERS) => {
        if (!window.Tesseract || typeof window.Tesseract.createWorker !== 'function') {
            throw new Error('Tesseract.js is not available.');
        }

        const worker = await window.Tesseract.createWorker('eng', 1, {
            logger: (message) => {
                if (message && message.status) {
                    setStatus(`${state.label}: ${message.status}`);
                }
                if (message && typeof message.progress === 'number') {
                    setProgress((state.index + message.progress) / Math.max(1, state.total));
                }
            },
        });

        if (typeof worker.setParameters === 'function') {
            await worker.setParameters(parameters);
        }

        return worker;
    };

    const recognizeRegions = async (regions, method, parameters = REGION_OCR_PARAMETERS) => {
        const state = { index: 0, total: regions.length, label: 'OCR' };
        const worker = await createWorker(state, parameters);
        const texts = [];
        const confidences = [];

        try {
            for (let index = 0; index < regions.length; index += 1) {
                state.index = index;
                state.label = regions[index].label;
                const result = await worker.recognize(regions[index].canvas);
                const data = result.data || {};
                const text = cleanRecognizedText(data);
                if (text) {
                    texts.push(text);
                }
                if (typeof data.confidence === 'number') {
                    confidences.push(data.confidence);
                }
            }
        } finally {
            await worker.terminate();
        }

        return {
            text: cleanOcrText(texts.join('\n')),
            confidence: averageConfidence(confidences),
            method,
        };
    };

    const recognizeScannedCanvas = async (canvas) => {
        const regions = buildOcrRegions(canvas).map((region) => ({
            ...region,
            canvas: cropCanvas(canvas, region),
        }));

        const cResult = await recognizeRegions(regions, 'OCR');
        if (textLooksUseful(cResult.text, 35)) {
            return cResult;
        }

        setStatus('OCR found little text. Running full-page fallback...');
        setProgress(0);
        const fullPage = trimWhitespace(preprocessCanvas(cropCanvas(canvas, {
            x: 0,
            y: 0,
            width: 1,
            height: 1,
        })));

        return recognizeRegions([
            { label: 'OCR', canvas: fullPage },
        ], 'OCR', FALLBACK_OCR_PARAMETERS);
    };

    const processPdf = async (file) => {
        setStatus('Checking PDF text layer...');
        setProgress(0.05);
        const page = await loadPdfFirstPage(file);
        const textLayer = await extractPdfTextLayer(page);
        if (textLooksUseful(textLayer)) {
            return {
                text: textLayer,
                confidence: 100,
                method: 'PDF text layer',
            };
        }

        setStatus('Rendering first PDF page for OCR...');
        setProgress(0.1);
        const canvas = await renderPdfPage(page);
        return recognizeScannedCanvas(canvas);
    };

    const processImage = async (file) => {
        setStatus('Preparing image for OCR...');
        setProgress(0.05);
        const canvas = await loadImageCanvas(file);
        return recognizeScannedCanvas(canvas);
    };

    const applyOcrResult = (result, filename) => {
        const text = normalizePrmsuMemoText(result.text || '');
        const confidence = typeof result.confidence === 'number' ? result.confidence : '';

        if (rawEl instanceof HTMLTextAreaElement) {
            rawEl.value = text;
        }
        if (confidenceEl instanceof HTMLInputElement) {
            confidenceEl.value = confidence === '' ? '' : String(Math.round(confidence * 100) / 100);
        }

        const suggestions = parseSuggestions(text, filename || 'document');
        Object.entries(suggestions).forEach(([key, value]) => setField(key, value));

        setProgress(1);
        if (text) {
            setStatus(`${result.method} completed. Review the suggested fields before issuing.`);
        } else {
            setStatus(`${result.method} completed, but no readable text was detected.`);
        }
    };

    const normalizeExistingRawText = () => {
        if (!(rawEl instanceof HTMLTextAreaElement) || !rawEl.value.trim() || !isPrmsuPresidentMemoText(rawEl.value)) {
            return;
        }

        const text = normalizePrmsuMemoText(rawEl.value);
        if (!text || text === rawEl.value.trim()) {
            return;
        }

        rawEl.value = text;
        const suggestions = parseSuggestions(text, 'document');
        Object.entries(suggestions).forEach(([key, value]) => setField(key, value));
    };

    normalizeExistingRawText();

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) {
            usePrimaryAttachmentInput();
            if (cameraInput instanceof HTMLInputElement) {
                cameraInput.value = '';
            }
            setProgress(0);
            setStatus('Attachment selected. Run OCR when ready.');
        }
    });

    if (cameraInput instanceof HTMLInputElement) {
        cameraInput.addEventListener('change', () => {
            if (!cameraInput.files || !cameraInput.files[0]) {
                return;
            }

            if (copyFilesToInput(fileInput, cameraInput.files)) {
                usePrimaryAttachmentInput();
                cameraInput.value = '';
            } else {
                useCameraAttachmentInput();
            }
            setProgress(0);
            setStatus('Photo captured. Run OCR when ready.');
        });
    }

    runButton.addEventListener('click', async () => {
        const file = selectedOcrFile();
        if (!file) {
            setStatus('Choose an attachment or take a photo before running OCR.');
            return;
        }

        const type = (file.type || '').toLowerCase();
        const name = (file.name || '').toLowerCase();
        const isImage = type.startsWith('image/') || /\.(jpe?g|png)$/i.test(name);
        const isPdf = type === 'application/pdf' || /\.pdf$/i.test(name);

        if (!isImage && !isPdf) {
            setStatus('OCR supports JPG, JPEG, PNG, and the first page of PDF attachments only.');
            return;
        }

        runButton.disabled = true;
        setProgress(0);
        setStatus(isPdf ? 'Preparing PDF for OCR...' : 'Preparing image for OCR...');

        try {
            const result = isPdf ? await processPdf(file) : await processImage(file);
            applyOcrResult(result, file.name || 'document');
        } catch (error) {
            setProgress(0);
            setStatus(error instanceof Error ? error.message : 'OCR failed. You can continue with manual entry.');
        } finally {
            runButton.disabled = false;
        }
    });
});
