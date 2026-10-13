// Shapes returned by existing Laravel endpoints; UI checks are not authorization.
export type UserRole = 'orang_tua' | 'guru' | 'admin' | 'super_admin';
export type Jenjang = 'SD' | 'SMP';
export type PaymentStatus = 'PENDING_MANUAL' | 'SUCCESS' | 'REJECTED';
export type PpdbStatus = 'BUKA' | 'TUTUP';

// P5 read-only reports: legacy values remain distinct from no payment row.
export type ReportKind = 'kelulusan' | 'pembayaran';
export type GraduationStatus = 'BELUM' | 'LULUS' | 'TIDAK_LULUS';
export type ReportPaymentStatus = PaymentStatus | 'PENDING' | 'FAILED' | 'EXPIRED';
export type ReportStatusFilter = '' | GraduationStatus | ReportPaymentStatus | 'NO_PAYMENT';
export interface AdminReportFilters {
    status: ReportStatusFilter;
    jenjang: '' | Jenjang;
    jalur_pendaftaran: '' | JalurPendaftaran;
    search: string;
}
export interface ReportPayment {
    id: number;
    status: ReportPaymentStatus;
    status_label: string;
    amount: number;
    invoice_number: string;
    payment_method: string;
    created_at: string | null;
    verified_at: string | null;
    rejection_reason: string | null;
    has_proof: boolean;
}
export interface AdminReportRow {
    id: number;
    name: string;
    email: string;
    jenjang: Jenjang | null;
    jalur_pendaftaran: JalurPendaftaran | null;
    status_kelulusan: GraduationStatus;
    payment: ReportPayment | null;
    payment_label: string;
}
export interface GraduationReportRow extends AdminReportRow {
    status_kelulusan: 'LULUS' | 'TIDAK_LULUS';
}
export interface AdminReportDetail extends AdminReportRow {
    account_name: string;
    biodata: { asal_sekolah: string | null; jenis_kelamin: string | null };
}
export interface AdminReportResponse<T extends AdminReportRow = AdminReportRow> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number; per_page: number };
    payment_statuses: ReportPaymentStatus[];
}
export type GraduationReportResponse = AdminReportResponse<GraduationReportRow>;
export type PaymentReportResponse = AdminReportResponse;
export interface GraduationExportRow {
    nama: string; email: string; jenjang: Jenjang | null;
    jalur_pendaftaran: JalurPendaftaran | null; status_kelulusan: 'LULUS' | 'TIDAK_LULUS';
}
export interface PaymentExportRow {
    nama: string; email: string; jenjang: Jenjang | null;
    jalur_pendaftaran: JalurPendaftaran | null; status_pembayaran: string;
    nominal: number | null; tanggal: string | null; nomor_referensi: string | null;
}
export type JalurPendaftaran = 'PUTRA_PUTRI_GURU' | 'YAYASAN' | 'INTERNAL_TK' | 'UMUM';

/** B.7.2: canonical official-student lifecycle state (server-owned, terminal). */
export type OfficialStudentStatus = 'TERDAFTAR' | 'MENGUNDURKAN_DIRI';

export interface AuthenticatedUser {
    id: number;
    name: string;
    role: UserRole;
    jadwal_observasi?: string | null;
    jam_observasi?: string | null;
    ruang_observasi?: string | null;
    perlengkapan?: string | null;
    status_kelulusan?: string | null;
    status_siswa?: OfficialStudentStatus | null;
    continued_at?: string | null;
}

export interface PublicQuota {
    status: PpdbStatus;
    sd: QuotaEntry;
    smp: QuotaEntry;
}

interface QuotaEntry {
    total: number;
    terpakai: number;
    sisa: number;
    tersedia: boolean;
}

// --- Shared error helpers ---------------------------------------------------

/**
 * Narrow `unknown` to an HTTP error with a JSON `message` body the way the
 * existing pages already do. Only reads the same optional fields, never
 * fabricates data, and falls back to the caller-provided default.
 */
export function httpErrorMessage(error: unknown, fallback: string): string {
    if (typeof error === 'object' && error !== null) {
        const maybe = error as { response?: { data?: { message?: unknown } } };
        const message = maybe.response?.data?.message;
        if (typeof message === 'string' && message.length > 0) return message;
    }
    return fallback;
}

/**
 * True when a 422-style validation payload flags `field` (e.g. `errors.email`).
 * Mirrors the existing `error.response?.data?.errors?.x` checks.
 */
export function httpHasFieldError(error: unknown, field: string): boolean {
    if (typeof error === 'object' && error !== null) {
        const maybe = error as { response?: { data?: { errors?: Record<string, unknown> } } };
        return !!maybe.response?.data?.errors?.[field];
    }
    return false;
}

// --- Users / staff -----------------------------------------------------------

export interface StaffAccount {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    created_at?: string;
}

// --- Whitelist (jalur internal) ----------------------------------------------

export interface WhitelistEntry {
    id: number;
    nama_siswa: string;
    asal_sekolah: string | null;
    jenjang: Jenjang;
    /** Boolean only — the backend never returns the secret registration_number. */
    has_number: boolean;
    is_used: boolean;
    user_id: number | null;
    created_at?: string;
}

// --- Token Umum ---------------------------------------------------------------

export interface TokenMetadata {
    id: number;
    /** Masked by the backend while unused; full value only for used tokens. */
    code: string;
    is_used: boolean;
    is_expired: boolean;
    expires_at: string | null;
    nama_pendaftar: string | null;
    created_at?: string;
}

// --- Observation instrument ---------------------------------------------------

/** Queue entry returned by GET /api/guru/antrean-observasi. */
export interface AntreanObservasi {
    id: number;
    name: string;
    email?: string;
    jadwal_observasi?: string | null;
    jam_observasi?: string | null;
    ruang_observasi?: string | null;
    /** E.12D — exposed by getAntrean; always 'SD' (SMP candidates are scored
     *  via the CBT flow, never this observasi arena). */
    jenjang?: string | null;
    /** withExists('hasilObservasi as sudah_dites') — boolean, not a field. */
    sudah_dites: boolean;
    status_kelulusan?: string | null;
}

export interface KomponenObservasi {
    id: number;
    kategori_id: number;
    kode: string;
    keterampilan: string | null;
    /** NOT NULL text column — always present. */
    deskripsi: string;
    petunjuk: string | null;
    lampiran: string | null;
    saran_jika_kurang: string | null;
}

export interface KategoriObservasi {
    id: number;
    nama_kategori: string;
    /** NOT NULL enum — every kategori belongs to exactly one jenjang. */
    jenjang: Jenjang;
    /** NOT NULL, defaults to 1 — controls display order. */
    urutan: number;
    /** Eager-loaded via `with('komponen')`; both consumers guard before use. */
    komponen?: KomponenObservasi[];
}

// --- CBT -----------------------------------------------------------------------

export type JenisSoal = 'PG' | 'ESAI';

export interface CbtSession {
    id: number;
    nama_ujian: string;
    tanggal_ujian: string;
    jam_mulai: string;
    jam_selesai: string;
    ruangan: string;
    token: string;
    is_active: boolean;
}

/**
 * P4-B — the GURU/ADMIN shape of a CBT question.
 *
 * Only imported by guru-guarded pages (`guru/cbt`, `guru/rekapan-cbt`), which
 * consume GET /api/guru/cbt-sessions/{id}/questions. That endpoint serializes
 * through `CbtQuestionGuruResource`, so `kunci_jawaban` IS present here.
 *
 * The student shape is a different type on purpose (`Question` in
 * `src/app/cbt/page.tsx`, from `CbtQuestionStudentResource`) and deliberately
 * has no `kunci_jawaban` member, so a student page cannot even reference the
 * answer key at compile time.
 */
export interface CbtQuestion {
    id: number;
    cbt_session_id: number;
    jenis_soal: JenisSoal;
    /** NOT NULL text column. */
    pertanyaan: string;
    gambar: string | null;
    opsi_a: string | null;
    opsi_b: string | null;
    opsi_c: string | null;
    opsi_d: string | null;
    /**
     * Present on the guru endpoint, and required for two behaviours there:
     * highlighting the correct option in the question list, and prefilling the
     * edit form. While the model's `$hidden` was the only mechanism, this arrived
     * as `undefined`, so the highlight never rendered and the edit form silently
     * fell back to its default — editing a question could reset the key to 'A'.
     *
     * Still optional/nullable because the column is nullable (an ESAI question
     * legitimately has no PG key).
     */
    kunci_jawaban?: string | null;
}

export interface CbtResult {
    id: number;
    cbt_session_id: number;
    /** Nullable FK — legacy results predate the user link. */
    user_id?: number | null;
    /** Nullable FK, unique per attempt. */
    cbt_attempt_id?: number | null;
    /** From the `cbt_sessions` join in GET /api/guru/cbt-results. */
    nama_ujian: string;
    /** From the same join. */
    token: string;
    nama_siswa: string;
    /** Cast to float by the model, so it arrives as a JSON number. */
    skor: number;
    /** NOT NULL, defaults to 0. */
    benar: number;
    /** NOT NULL, defaults to 0. */
    salah: number;
    /** Cast to float; NOT NULL with a 0 default. */
    skor_esai: number;
    /** Cast to boolean by the model; NOT NULL with a false default. */
    is_koreksi_esai: boolean;
    /** NOT NULL, defaults to 0 — anti-cheat flag counter. */
    jumlah_pelanggaran: number;
    /**
     * Flat `question_id -> answer` map. The student client posts
     * `Record<number, string>` and the backend stores it verbatim
     * (`json_encode($answers)`); `getResults` json_decodes it before
     * responding. PG values are option letters, ESAI values are free text —
     * there is no nested wrapper object, so values render directly.
     */
    jawaban_json?: Record<string, string> | null;
    /** NOT NULL timestamps() column, always selected by getResults(). */
    created_at: string;
    updated_at?: string;
}

// --- Auth ---------------------------------------------------------------------

/**
 * Register/login responses. `user.role` drives the post-auth redirect; it is a
 * convenience for UX only — every privileged action is re-checked by the
 * backend, so a forged role here grants nothing.
 */
export interface AuthResponse {
    user?: { role?: UserRole } | null;
    token?: string;
    message?: string;
}

// --- Biodata (pendaftar) --------------------------------------------------------

/** Columns shared by GET /api/biodata and the POST /api/biodata payload. */
export type BiodataField =
    | 'jalur_pendaftaran'
    | 'asal_sekolah' | 'nama_lengkap' | 'nama_panggilan' | 'jenis_kelamin'
    | 'nisn' | 'nik_siswa' | 'no_kk' | 'tempat_lahir' | 'tanggal_lahir'
    | 'anak_ke' | 'status_peserta_didik'
    | 'nama_ibu' | 'nik_ibu' | 'pendidikan_ibu' | 'pekerjaan_ibu' | 'penghasilan_ibu'
    | 'nama_ayah' | 'nik_ayah' | 'pendidikan_ayah' | 'pekerjaan_ayah' | 'penghasilan_ayah'
    | 'alamat_lengkap' | 'kota' | 'kecamatan' | 'kelurahan' | 'rt' | 'rw'
    | 'no_hp_ortu' | 'email_siswa' | 'nama_wali' | 'no_hp_wali';

/**
 * Row returned by GET /api/biodata (`null` until the student fills the form).
 * Numeric columns such as `anak_ke` arrive as numbers and are coerced to
 * strings for the controlled inputs.
 */
export type BiodataRecord = Partial<Record<BiodataField, string | number | null>> & {
    jalur_pendaftaran?: JalurPendaftaran | null;
    id?: number;
    user_id?: number;
    created_at?: string | null;
    updated_at?: string | null;
};

/**
 * Controlled form state for the biodata wizard — every input is a string.
 * The loader copies the whole response row, so non-form columns may ride
 * along; the backend saves only its own `$request->only()` whitelist.
 */
export interface BiodataFormState extends Record<BiodataField, string> {
    [key: string]: string;
}

// --- Berkas (identity documents) -------------------------------------------------

/**
 * Row returned by GET /api/berkas (`null` until a document is uploaded).
 * `kartu_keluarga` / `akte_kelahiran` hold private storage paths and are only
 * used as presence flags — the file itself is streamed by an owner-checked
 * endpoint, never inlined here.
 */
export interface BerkasRecord {
    id?: number;
    user_id?: number;
    kartu_keluarga?: string | null;
    akte_kelahiran?: string | null;
    status?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
}

// --- Hasil Observasi (SD) -------------------------------------------------------

/**
 * One observation answer, keyed by instrument code inside `jawaban_json`.
 * Mirrors the payload the observation page posts and what the report editor
 * mutates (adding `saran`, editing `catatan`).
 */
export interface JawabanObservasi {
    jawaban?: string;
    skor?: number;
    catatan?: string;
    kategori?: string;
    keterampilan?: string;
    /** Filled in by the report editor for the printable PDF. */
    saran?: string;
}

/**
 * E.12C — canonical `rekomendasi_akhir` contract. Mirrors the DB enum
 * (migration 2026_10_12_000000_align_rekomendasi_akhir_canonical) and the
 * backend validation rule in ObservasiController:
 *   LULUS           — accepted
 *   LULUS BERSYARAT — accepted with special conditions/agreement (decided
 *                     acceptance, e.g. requiring a psychologist's report —
 *                     NOT an "undecided" status)
 *   TIDAK LULUS     — rejected
 * Unrelated to status_kelulusan / status_siswa, which stay untouched.
 */
export type RekomendasiAkhir = 'LULUS' | 'LULUS BERSYARAT' | 'TIDAK LULUS';

/**
 * Row returned by GET /api/guru/rekapan-observasi — the `hasil_observasis`
 * table joined with the student's name. `jawaban_json` is already decoded
 * server-side, so it is an object here rather than a string.
 */
export interface HasilObservasiRecord {
    id: number;
    /** NOT NULL FK to the student being assessed. */
    user_id: number;
    penilai_id?: number | null;
    /** `users.name as nama_siswa` from the join. */
    nama_siswa: string;
    /**
     * NOT NULL json column, decoded by the controller. Decoding can still
     * yield null for malformed stored JSON, so keep it nullable.
     */
    jawaban_json?: Record<string, JawabanObservasi> | null;
    /**
     * E.12C — canonical rekomendasi contract (see RekomendasiAkhir). Nullable
     * because the column is nullable and legacy rows may pre-date the contract.
     */
    rekomendasi_akhir?: RekomendasiAkhir | null;
    catatan_tambahan?: string | null;
    /**
     * E.12G Phase 4 — optimistic-concurrency version from the server
     * (hasil_observasis.version). Sent back as expected_version on edit.
     * Optional because legacy cached rows may pre-date the column.
     */
    version?: number;
    /** NOT NULL timestamps() column; the query orders by it. */
    updated_at: string;
    created_at?: string;
}

// --- Admin: pusat data pendaftar --------------------------------------------------

/**
 * Aggregated row from GET /api/admin/pendaftar. Built by flattening the
 * user's latest payment, biodata, berkas and observation result, so several
 * fields are nullable when the student has not reached that stage yet.
 * `jenjang` is `'-'` until a payment records it.
 */
export interface AdminPendaftar {
    id: number;
    name: string;
    email: string;
    join_date?: string;
    payment_id?: number | null;
    jenjang: Jenjang | '-';
    /** Human-readable status label, not the raw PaymentStatus enum. */
    status_bayar: string;
    proof_of_payment?: string | null;
    rejection_reason?: string | null;
    verified_at?: string | null;
    /** Funnel stage 1..6 derived from how far the student has progressed. */
    step: number;
    biodata?: BiodataRecord | null;
    berkas?: BerkasRecord | null;
    jadwal_observasi?: string | null;
    jam_observasi?: string | null;
    ruang_observasi?: string | null;
    perlengkapan?: string | null;
    hasil_observasi?: boolean;
    status_kelulusan?: string | null;
}

// --- Admin: jadwal observasi & kelulusan -----------------------------------------

/**
 * Row from GET /api/admin/jadwal-observasi — `User` columns plus `jenjang`
 * from the joined payments table. Used to schedule observation sessions.
 */
export interface JadwalObservasiRow {
    id: number;
    name: string;
    email: string;
    jenjang: Jenjang | null;
    jadwal_observasi?: string | null;
    jam_observasi?: string | null;
    ruang_observasi?: string | null;
    perlengkapan?: string | null;
}

/**
 * Row from GET /api/admin/kelulusan — a slim user projection carrying the
 * graduation decision. `status_kelulusan` is null until an admin acts.
 */
export interface KelulusanRow {
    id: number;
    name: string;
    email: string;
    jenjang: Jenjang | null;
    status_kelulusan?: 'LULUS' | 'TIDAK_LULUS' | null;
}

/**
 * Controlled form state for the per-jenjang Surat Keputusan config
 * (Manajemen Kelulusan → "Konfigurasi Surat Keputusan" tab).
 *
 * P4-CB: each jenjang (SD/SMP) has its OWN config row, branding and PDF
 * template. `logoB64`/`ttdB64` are pending uploads the client sends; the
 * server returns `logoPreview`/`ttdPreview` (already-inlined data URIs) so the
 * form can render the stored image without a second fetch.
 */
export interface SuratKelulusanFormState {
    nomor_surat: string;
    tanggal_surat: string;
    nama_kepsek: string;
    nama_sekolah: string;
    tagline: string;
    alamat: string;
    kontak: string;
    isi_lulus: string;
    isi_gagal: string;
    /** Pending upload (base64 data URI); cleared after a successful save. */
    logoB64: string;
    ttdB64: string;
    /** Stored image preview (data URI) echoed back by GET/POST setting-surat. */
    logoPreview: string | null;
    ttdPreview: string | null;
}

// ============================================================================
// B.6B — Admin Daftar Ulang (read-only participant contract + settlement).
// Shapes mirror the real backend responses; the backend is authoritative.
// The client never recalculates pricing and never sends financial identity.
// ============================================================================

/** Backend payment status for a Daftar Ulang payment row. */
export type DuPaymentStatus = 'PENDING' | 'PENDING_MANUAL' | 'SUCCESS' | 'REJECTED' | 'FAILED' | 'EXPIRED';

/** Daftar Ulang domain status, kept distinct from payment status. */
export type DuStatus = 'MENUNGGU_PEMBAYARAN' | 'MENUNGGU_VERIFIKASI' | 'LUNAS' | 'MENGUNDURKAN_DIRI';

export interface DuAgreement {
    agreement_accepted: boolean;
    agreement_version: string | null;
    accepted_at: string | null;
}

export interface DuAgreementDetail extends DuAgreement {
    agreement_sha256: string | null;
}

/** Immutable invoice pricing line item from the stored snapshot. */
export interface DuInvoiceItem {
    id?: string;
    name?: string;
    quantity?: number;
    unit_amount?: number;
    line_total?: number;
    [key: string]: unknown;
}

/** The immutable invoice pricing_snapshot stored on du_invoices. */
export interface DuInvoiceSnapshot {
    source?: string;
    items?: DuInvoiceItem[];
    subtotal?: number;
    discount_nominal?: number;
    total?: number;
    [key: string]: unknown;
}

/** A Daftar Ulang payment as presented to admin; never a storage path. */
export interface DuPayment {
    id: number;
    payment_category: 'DAFTAR_ULANG';
    invoice_number: string | null;
    status: DuPaymentStatus;
    amount: number;
    created_at: string | null;
    verified_at: string | null;
    verifier: { id: number; name: string } | null;
    rejection_reason: string | null;
    has_proof: boolean;
}

export interface DuStudentListItem {
    id: number;
    name: string;
    jenjang: Jenjang | null;
    jalur_pendaftaran: JalurPendaftaran | null;
    status_kelulusan: GraduationStatus;
    daftar_ulang: { status: DuStatus } & DuAgreement;
    invoice: {
        invoice_number: string;
        created_at: string | null;
        subtotal: number;
        discount_nominal: number;
        total: number;
        snapshot_at: string | null;
    };
    payment: DuPayment | null;
    updated_at: string | null;
}

export interface DuStudentDetail {
    id: number;
    name: string;
    jenjang: Jenjang | null;
    jalur_pendaftaran: JalurPendaftaran | null;
    status_kelulusan: GraduationStatus;
    daftar_ulang: { status: DuStatus } & DuAgreementDetail;
    invoice: {
        invoice_number: string;
        created_at: string | null;
        jenjang: Jenjang;
        student_name: string;
        jalur_pendaftaran: JalurPendaftaran | null;
        pricing_revision_id: number | null;
        pricing_source: string | null;
        pricing_snapshot: DuInvoiceSnapshot;
        items: DuInvoiceItem[];
        subtotal: number;
        discount_nominal: number;
        total: number;
        snapshot_at: string | null;
    };
    payment: DuPayment | null;
    updated_at: string | null;
}

export interface DuStudentsResponse {
    data: DuStudentListItem[];
    meta: { current_page: number; last_page: number; total: number; per_page: number };
}

/** A pending Daftar Ulang payment from the B.6A payments/pending inbox. */
export interface DuPendingPayment {
    id: number;
    payment_category: 'DAFTAR_ULANG';
    invoice_number: string | null;
    jenjang: Jenjang | null;
    amount: number;
    status: DuPaymentStatus;
    created_at: string | null;
    proof_of_payment: string | null;
    user: { id: number; name: string; email: string } | null;
}

export interface DuStudentFilters {
    status_pembayaran: '' | DuPaymentStatus;
    jenjang: '' | Jenjang;
    jalur_pendaftaran: '' | JalurPendaftaran;
    search: string;
}

// ============================================================================
// B.6C — Parent Daftar Ulang (self-service flow).
// The server is authoritative for identity, pricing, agreement, account and
// payment status; the client only displays and submits the allowed payloads.
// ============================================================================

/** Immutable invoice line item from the server money calculation. */
export interface DuParentInvoiceItem {
    id: string;
    name: string;
    quantity: number;
    unit_amount: number;
    line_total: number;
}

/** Server-resolved Daftar Ulang payment account for the user's jenjang. */
export interface DuParentAccount {
    jenjang: Jenjang;
    rekening: string | null;
    bank_name: string | null;
    bank_owner: string | null;
}

/** GET /api/daftar-ulang/pricing — current resolution (no agreement yet). */
export interface DuParentPricing {
    source: 'DEFAULT' | 'CUSTOM';
    pricing_revision_id: number;
    revision: number;
    jenjang: Jenjang;
    items: DuParentInvoiceItem[];
    subtotal: number;
    discount_nominal: number;
    total: number;
    account: DuParentAccount;
    agreement_required: boolean;
}

/** The DU payment row attached to an invoice. */
export interface DuParentInvoicePayment {
    id: number;
    payment_category: 'DAFTAR_ULANG';
    status: DuPaymentStatus;
    amount: number;
    rejection_reason: string | null;
    verified_at: string | null;
    proof_of_payment?: string | null;
    created_at?: string | null;
}

/** Agreement data embedded in the immutable invoice snapshot. */
export interface DuParentAgreement {
    version: string;
    text: string;
    sha256?: string;
}

/** Snapshot stored on the invoice (immutable once created). */
export interface DuParentInvoiceSnapshot {
    source?: 'DEFAULT' | 'CUSTOM';
    items?: DuParentInvoiceItem[];
    subtotal?: number;
    discount_nominal?: number;
    total?: number;
    agreement?: DuParentAgreement;
    [key: string]: unknown;
}

/** Domain financial status derived server-side from the linked payment. */
export type DuFinancialStatus = 'CREATED' | 'PENDING_PAYMENT' | 'PAID' | 'REJECTED' | 'EXPIRED' | 'FAILED';

/** A Daftar Ulang invoice as returned by create/retrieve. */
export interface DuParentInvoice {
    id: number;
    user_id: number;
    payment_id: number;
    invoice_number: string;
    jenjang: Jenjang;
    student_name: string;
    jalur_pendaftaran: JalurPendaftaran | null;
    subtotal: number;
    discount_nominal: number;
    total: number;
    snapshot_at: string | null;
    created_at: string | null;
    pricing_snapshot: DuParentInvoiceSnapshot;
    payment: DuParentInvoicePayment | null;
    financial_status: DuFinancialStatus;
    /** B.7.1: committed withdrawal timestamp (server-set; NULL until withdrawn). */
    withdrawn_at?: string | null;
}

/** POST /api/daftar-ulang/continue — server-authoritative continuation result. */
export interface DuContinuationResult {
    status_siswa: OfficialStudentStatus;
    continued_at: string | null;
    changed: boolean;
}

/** POST /api/daftar-ulang/withdraw — server-authoritative withdrawal result. */
export interface DuWithdrawalResult {
    withdrawn: boolean;
    changed: boolean;
    withdrawn_at: string | null;
    status: 'MENGUNDURKAN_DIRI';
    withdrawal: { reason: string | null; note: string | null; initiated_by: string | null } | null;
}

/* ==========================================================================
   B.7.4-C — PARENT CONSULTATION ("KONSULTASI KE SEKOLAH")
   Mirrors DaftarUlangConsultationService::present() exactly. Every field is
   server-owned; the parent surface may only create/cancel its own row.
   ========================================================================== */

/** Legal consultation statuses (mirrors DaftarUlangConsultation::STATUSES). */
export type DuConsultationStatus = 'REQUESTED' | 'SCHEDULED' | 'COMPLETED' | 'CANCELLED';

/** The parent's frozen request window; never rewritten after submission. */
export interface DuConsultationRequest {
    /** "YYYY-MM-DD" (Carbon::toDateString()). */
    preferred_date: string | null;
    /** "HH:MM" — backend validates date_format:H:i; clockTime() trims seconds. */
    preferred_time: string | null;
    note: string | null;
}

/** Admin-confirmed schedule; all null until an admin schedules it. */
export interface DuConsultationSchedule {
    scheduled_date: string | null;
    scheduled_time: string | null;
    admin_note: string | null;
}

/** Admin-list joins only. Always null on the parent surface. */
export interface DuConsultationParticipant {
    name: string | null;
    invoice_number: string | null;
    jenjang: string | null;
    jalur_pendaftaran: string | null;
    status_kelulusan: string | null;
}

/** One consultation row as presented by the API. */
export interface DuConsultation {
    id: number;
    invoice_id: number;
    status: DuConsultationStatus;
    /** REQUESTED | SCHEDULED. Server-computed; never derived client-side. */
    is_active: boolean;
    /** COMPLETED | CANCELLED. Terminal rows are immutable history. */
    is_terminal: boolean;
    request: DuConsultationRequest;
    schedule: DuConsultationSchedule;
    participant: DuConsultationParticipant | null;
    resolved_at: string | null;
    created_at: string | null;
}

/** GET /api/daftar-ulang/consultations */
export interface DuConsultationListResult {
    data: DuConsultation[];
    /** Id of the currently ACTIVE consultation, or null when there is none. */
    active: number | null;
}

/**
 * POST /api/daftar-ulang/consultations and POST .../{id}/cancel share this
 * envelope. The create call answers 201 for a new row and 200 for an
 * idempotent replay of an already-ACTIVE one; both carry the same shape.
 */
export interface DuConsultationMutationResult {
    consultation: DuConsultation;
}

/**
 * POST /api/daftar-ulang/consultations body. Deliberately narrow: identity and
 * every privileged column (user_id, invoice_id, status, admin_note,
 * scheduled_*, resolved_*) are resolved server-side and rejected by the
 * controller's strict field allowlist with 422 if sent.
 */
export interface DuConsultationCreatePayload {
    /** "YYYY-MM-DD"; must be today or later (after_or_equal:today). */
    preferred_date: string;
    /** "HH:MM" (date_format:H:i). */
    preferred_time: string;
    /** Optional; max 1000 characters per the backend contract. */
    note?: string | null;
}

/* ==========================================================================
   B.7.4-D — ADMIN CONSULTATION DESK
   The admin side of the same DaftarUlangConsultationService::present() rows.
   Rows are IDENTICAL to the parent surface plus `participant`, which the
   admin-list joins populate; there is no per-id admin detail endpoint, so the
   desk renders details straight from the list row.
   ========================================================================== */

/**
 * Status filter vocabulary of GET /api/admin/daftar-ulang/consultations.
 * The four literal statuses come straight from the state machine; OPEN and
 * ACTIVE are the two server-side ALIASES for the live pair (REQUESTED |
 * SCHEDULED), expanded by DaftarUlangConsultationService::expandStatus().
 * An empty string means "no filter" and is omitted from the query string,
 * because the controller's strict allowlist inspects $request->keys() — which
 * includes query params — and rejects anything unlisted with 422.
 */
export type DuAdminConsultationStatusFilter = DuConsultationStatus | 'OPEN' | 'ACTIVE';

/** All six query params the controller allowlists; nothing else may be sent. */
export interface DuAdminConsultationFilters {
    status: '' | DuAdminConsultationStatusFilter;
    jenjang: '' | Jenjang;
    jalur_pendaftaran: '' | JalurPendaftaran;
    /** max:100; matches users.name, du_invoices.invoice_number, student_name. */
    search: string;
    /** min:1; omitted when 1 (the default page). */
    page?: number;
    /** min:1|max:100. Omitted = server default (25). */
    per_page?: number;
}

/**
 * Per-status counts for the CURRENT filter set, deliberately ignoring `status`
 * so the admin always sees the whole distribution (adminSummary()).
 */
export interface DuConsultationSummary {
    requested: number;
    scheduled: number;
    completed: number;
    cancelled: number;
    /** REQUESTED + SCHEDULED, i.e. everything still actionable. */
    open: number;
}

/** Laravel paginator envelope, mirrored from DuStudentsResponse['meta']. */
export interface DuConsultationPageMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

/** GET /api/admin/daftar-ulang/consultations */
export interface DuAdminConsultationsResponse {
    data: DuConsultation[];
    summary: DuConsultationSummary;
    meta: DuConsultationPageMeta;
}

/**
 * POST /api/admin/daftar-ulang/consultations/{id}/schedule — the ONLY way into
 * SCHEDULED. Calling it on an already-SCHEDULED row is a RESCHEDULE: it
 * overwrites the agreed window, keeps scheduled_by at the first admin who
 * took it, and never touches the parent's requested window.
 *
 * The strict allowlist permits exactly these three keys. admin_note is sticky:
 * the service writes `$data['admin_note'] ?? $consultation->admin_note` and
 * ConvertEmptyStringsToNull turns '' into null, so omitting it (or sending
 * null/'') PRESERVES the stored note rather than clearing it.
 */
export interface DuConsultationSchedulePayload {
    /** "YYYY-MM-DD", after_or_equal:today. */
    scheduled_date: string;
    /** "HH:MM" (date_format:H:i). */
    scheduled_time: string;
    /** Optional; max 1000. Omitted keeps the previously stored note. */
    admin_note?: string;
}

/** POST .../schedule — `rescheduled` is true when the row was already SCHEDULED. */
export interface DuConsultationScheduleResult extends DuConsultationMutationResult {
    rescheduled: boolean;
}

/**
 * POST .../resolve vocabulary. The client names an ACTION, never a status, and
 * the controller translates it (complete -> COMPLETED, cancel -> CANCELLED).
 */
export type DuConsultationResolveAction = 'complete' | 'cancel';

/**
 * POST /api/admin/daftar-ulang/consultations/{id}/resolve. Allowed edges only:
 * complete requires SCHEDULED (REQUESTED -> COMPLETED is refused with 409,
 * because nothing can be completed before it is scheduled); cancel works from
 * any ACTIVE status. admin_note is sticky exactly as in the schedule payload.
 */
export interface DuConsultationResolvePayload {
    action: DuConsultationResolveAction;
    /** Optional; max 1000. Omitted keeps the previously stored note. */
    admin_note?: string;
}

/** POST .../resolve — the same envelope as the parent-side mutations. */
export type DuConsultationResolveResult = DuConsultationMutationResult;

/**
 * Server reason codes on a 409/403 body ({ message, reason_code }). Read for
 * logging/diagnostics only; the UI never branches on them because the status
 * code plus a forced resync is already authoritative.
 */
export type DuConsultationReasonCode =
    | 'CONSULTATION_ACTIVE_EXISTS'
    | 'CONSULTATION_NOT_ACTIVE'
    | 'CONSULTATION_ILLEGAL_MOVE'
    | 'CONSULTATION_FORBIDDEN_ROLE';
