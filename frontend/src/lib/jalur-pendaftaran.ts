// Administrative biodata choices, deliberately separate from account registration routes.
export const JALUR_PENDAFTARAN_OPTIONS = [
    { value: 'PUTRA_PUTRI_GURU', label: 'Putra/Putri Guru' },
    { value: 'YAYASAN', label: 'Jalur Yayasan' },
    { value: 'INTERNAL_TK', label: 'Jalur Internal TK Al-Mubarak' },
    { value: 'UMUM', label: 'Jalur Umum' },
] as const;

export function jalurPendaftaranLabel(value: unknown): string {
    return JALUR_PENDAFTARAN_OPTIONS.find(option => option.value === value)?.label ?? '-';
}
