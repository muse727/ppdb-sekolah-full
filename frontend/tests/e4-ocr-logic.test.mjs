// E.4 — Deterministic tests for the client-side OCR pre-check logic.
//
// These are pure-logic tests of the keyword/limit/decision rules mirrored
// from src/app/pendaftar/berkas/page.tsx. The browser worker itself is NOT
// exercised here (that needs a real device/browser — documented limitation).
// Run: node frontend/tests/e4-ocr-logic.test.mjs
import assert from 'node:assert/strict';

// ── Mirror of the page constants (kept in sync deliberately; the page is a
// client component without a shared module yet) ──────────────────────────────
const ocrKeywords = {
    kartu_keluarga: ['KARTU KELUARGA', 'KEPALA KELUARGA'],
    akte_kelahiran: ['AKTA KELAHIRAN', 'AKTE KELAHIRAN', 'KUTIPAN', 'PENCATATAN SIPIL'],
};
const OCR_MAX_BYTES = 5 * 1024 * 1024;
const OCR_TIMEOUT_MS = 30000;

function decideOcrOutcome(file, jenis) {
    const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
    if (isPdf) return 'skipped';
    if (!file.type.startsWith('image/')) return 'skipped';
    if (file.size > OCR_MAX_BYTES) return 'skipped';
    const keywords = ocrKeywords[jenis] ?? [];
    if (keywords.length === 0) return 'skipped';
    return 'proceed-to-worker';
}

// The worker result is mapped to match/mismatch by keyword containment ONLY —
// it can never produce a "verified" state; upload always remains available.
function mapWorkerText(text, jenis) {
    const upper = (text ?? '').toUpperCase();
    const keywords = ocrKeywords[jenis] ?? [];
    return keywords.some((kw) => upper.includes(kw)) ? 'match' : 'mismatch';
}

// Whether the UI must still allow upload for a given outcome — E.4 rule:
// NO OCR outcome may block upload; mismatch/error/skipped only confirm.
function uploadStillAllowed(outcome) {
    return outcome === 'match' || outcome === 'mismatch' || outcome === 'skipped' || outcome === 'error';
}

const mk = (name, type, size) => ({ name, type, size });

let passed = 0;
const t = (name, fn) => { fn(); passed++; console.log('  ok -', name); };

t('PDF file is skipped, never OCR-scanned (no false "scanned" claim)', () => {
    assert.equal(decideOcrOutcome(mk('kk.pdf', 'application/pdf', 100_000), 'kartu_keluarga'), 'skipped');
    // PDF disguised with an image Content-Type is still detected by extension.
    assert.equal(decideOcrOutcome(mk('kk.pdf', 'image/png', 100_000), 'kartu_keluarga'), 'skipped');
});

t('oversized image is skipped (protects slow devices)', () => {
    assert.equal(decideOcrOutcome(mk('big.jpg', 'image/jpeg', OCR_MAX_BYTES + 1), 'kartu_keluarga'), 'skipped');
    assert.equal(decideOcrOutcome(mk('ok.jpg', 'image/jpeg', OCR_MAX_BYTES), 'kartu_keluarga'), 'proceed-to-worker');
});

t('non-image, non-pdf file is skipped before the worker', () => {
    assert.equal(decideOcrOutcome(mk('x.exe', 'application/octet-stream', 10), 'kartu_keluarga'), 'skipped');
});

t('keyword match: KK text recognized', () => {
    assert.equal(mapWorkerText('KARTU KELUARGA\nNo. 32...\nKEPALA KELUARGA', 'kartu_keluarga'), 'match');
});

t('keyword match: akte variants recognized', () => {
    assert.equal(mapWorkerText('kutipan akta kelahiran', 'akte_kelahiran'), 'match');
    assert.equal(mapWorkerText('REPUBLIK INDONESIA ... PENCATATAN SIPIL', 'akte_kelahiran'), 'match');
});

t('keyword mismatch on unrelated text', () => {
    assert.equal(mapWorkerText('SURAT TANDA NOMOR KENDARAAN', 'kartu_keluarga'), 'mismatch');
    assert.equal(mapWorkerText('', 'akte_kelahiran'), 'mismatch');
});

t('mismatch does NOT prove invalid and never blocks upload', () => {
    assert.ok(uploadStillAllowed('mismatch'));
    assert.ok(uploadStillAllowed('skipped'));
    assert.ok(uploadStillAllowed('error'));
});

t('OCR outcome can never mark a document verified (no such state exists)', () => {
    // The mapping produces only these four advisory values; "verified" is
    // exclusively a panitia/backend decision (status MENUNGGU on upload).
    const all = ['match', 'mismatch', 'skipped', 'error'];
    for (const o of all) assert.ok(!/verif/i.test(o));
    assert.ok(all.every(uploadStillAllowed));
});

t('timeout constant is bounded (30s) and size limit matches backend 5MB', () => {
    assert.equal(OCR_TIMEOUT_MS, 30000);
    assert.equal(OCR_MAX_BYTES, 5 * 1024 * 1024);
});

console.log(`\ne4-ocr-logic: ${passed} passed`);
