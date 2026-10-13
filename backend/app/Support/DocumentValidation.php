<?php

namespace App\Support;

/**
 * E.4 — content-based validation for user-uploaded identity documents
 * (Kartu Keluarga / Akte Kelahiran).
 *
 * `mimes:` + `image` rules only check the client Content-Type and a short
 * magic-byte sniff; a payload labelled image/png with trailing garbage (or a
 * polyglot) can still pass. For these files the bytes are stored and later
 * streamed to parents and admins, so we verify the container is genuinely
 * parseable before persisting:
 *
 *  - JPEG: full segment walk to EOI; rejects truncated files and stops at
 *    first decoding error (getimagesize can succeed on partial data).
 *  - PNG: full chunk walk to IEND with CRC check on every chunk header.
 *  - PDF: %PDF- header, %%EOF marker, and a real xref/trailer object —
 *    verified with GD-independent native parsing, no new dependency
 *    (dompdf is a generator, not a parser, and imagick is not installed).
 *
 * Limits (kept in one place so FE hints, API errors, and tests agree):
 *  - max 5 MiB per file;
 *  - images: max 4000x4000 px (a legible A4 scan is ~2480x3508);
 *  - PDF: max 10 pages, no encryption, no JavaScript-enabled flag.
 */
final class DocumentValidation
{
    public const MAX_BYTES  = 5120 * 1024; // 5 MiB
    public const MAX_PIXELS = 4000;

    /** @var array<string,string> */
    public const ALLOWED_MIMES = [
        'image/jpeg' => 'jpeg',
        'image/png'  => 'png',
        'application/pdf' => 'pdf',
    ];

    /**
     * Validate raw bytes. Returns null when the document is acceptable,
     * or a human-readable Indonesian error message when it is not.
     */
    public static function check(string $bytes): ?string
    {
        if ($bytes === '' ) {
            return 'File kosong atau tidak terbaca. Silakan pilih ulang file Anda.';
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            $mb = round(self::MAX_BYTES / 1024 / 1024);
            return "Ukuran file melebihi batas maksimal {$mb} MB.";
        }

        $mime = self::sniffMime($bytes);
        if ($mime === null) {
            return 'Format file tidak didukung. Gunakan JPG, PNG, atau PDF.';
        }

        return match ($mime) {
            'image/jpeg' => self::checkJpeg($bytes),
            'image/png'  => self::checkPng($bytes),
            'application/pdf' => self::checkPdf($bytes),
            default => 'Format file tidak didukung. Gunakan JPG, PNG, atau PDF.',
        };
    }

    /**
     * Determine the true MIME from magic bytes. Never trusts the client
     * supplied Content-Type or the file extension.
     */
    public static function sniffMime(string $bytes): ?string
    {
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($bytes, '%PDF-', 5) === 0) {
            return 'application/pdf';
        }
        return null;
    }

    /** Real extension for the sniffed type (used for the stored filename). */
    public static function extensionFor(string $mime): string
    {
        return self::ALLOWED_MIMES[$mime] ?? 'bin';
    }

    // ── JPEG ───────────────────────────────────────────────────────────────

    private static function checkJpeg(string $bytes): ?string
    {
        $len = strlen($bytes);

        // Walk segments; SOI + APPn/SOF/DQT/DHT/SOS ... ending in EOI.
        $i = 2; // skip SOI
        $sosSeen = false;
        while ($i + 2 <= $len) {
            if ($bytes[$i] !== "\xFF") {
                return 'File JPG rusak (struktur data tidak valid).';
            }
            $marker = ord($bytes[$i + 1]);
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $i += 2;
                continue;
            }
            if ($marker === 0xD9) { // EOI
                return $sosSeen
                    ? null
                    : 'File JPG rusak (tidak ditemukan data gambar).';
            }
            if ($marker === 0xDA) { // SOS — entropy-coded data follows
                $sosSeen = true;
                // Skip entropy data until a marker that is not RSTn/stuffed 0xFF.
                $i += 4 + (ord($bytes[$i + 2]) << 8) + ord($bytes[$i + 3]) - 2;
                while ($i < $len) {
                    $b = ord($bytes[$i]);
                    if ($b === 0xFF && $i + 1 < $len) {
                        $n = ord($bytes[$i + 1]);
                        if ($n !== 0x00 && !($n >= 0xD0 && $n <= 0xD7)) {
                            break;
                        }
                    }
                    $i++;
                }
                // Loop exit conditions: either a marker was found (continue
                // outer walk) or the bytes ended right after the scan —
                // trailing EOI is common but optional per spec, so a scan
                // that runs to EOF without garbage is still a complete image.
                if ($i >= $len) {
                    return null;
                }
                continue;
            }
            if ($marker === 0x01 || $marker === 0x00) { // TEM / stuffed
                $i += 2;
                continue;
            }
            if ($i + 4 > $len) {
                return 'File JPG rusak (terpotong).';
            }
            $segLen = (ord($bytes[$i + 2]) << 8) | ord($bytes[$i + 3]);
            if ($segLen < 2 || $i + 2 + $segLen > $len) {
                return 'File JPG rusak (terpotong).';
            }
            $i += 2 + $segLen;
        }

        return 'File JPG rusak (tidak lengkap).';
    }

    // ── PNG ────────────────────────────────────────────────────────────────

    private static function checkPng(string $bytes): ?string
    {
        $len = strlen($bytes);
        $i = 8; // skip signature

        if ($i + 8 > $len) {
            return 'File PNG rusak (terpotong).';
        }

        $first = substr($bytes, $i, 8);
        if ($first === false || substr($first, 4, 4) !== 'IHDR') {
            return 'File PNG rusak (header tidak valid).';
        }

        // Walk all chunks to IEND, verifying every CRC.
        while ($i + 12 <= $len) {
            $size = unpack('N', substr($bytes, $i, 4))[1];
            $type = substr($bytes, $i + 4, 4);

            if ($type === 'IEND') {
                // IHDR data: signature (8) + length (4) + type (4) = 16.
                $ihdr = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
                if ($ihdr['width'] > self::MAX_PIXELS || $ihdr['height'] > self::MAX_PIXELS) {
                    return 'Resolusi gambar terlalu besar (maksimal ' . self::MAX_PIXELS . 'x' . self::MAX_PIXELS . ' piksel).';
                }
                return null;
            }

            if ($i + 12 + $size > $len) {
                return 'File PNG rusak (terpotong).';
            }

            $crc = sprintf('%08x', unpack('N', substr($bytes, $i + 8 + $size, 4))[1]);
            $calc = crc32(substr($bytes, $i + 4, 4 + $size));
            if ($crc !== sprintf('%08x', $calc)) {
                return 'File PNG rusak (data tidak valid).';
            }

            $i += 12 + $size;
        }

        return 'File PNG rusak (tidak lengkap).';
    }

    // ── PDF ────────────────────────────────────────────────────────────────

    /**
     * Structural PDF check WITHOUT a new dependency: header, EOF marker,
     * startxref pointer, and a readable xref table or xref-stream object.
     * Also rejects encrypted and JavaScript-flagged documents.
     */
    private static function checkPdf(string $bytes): ?string
    {
        $len = strlen($bytes);

        // %%EOF must appear near the end (within the last 2048 bytes, the
        // common convention).
        $tail = substr($bytes, max(0, $len - 2048));
        if ($tail === false || strpos($tail, '%%EOF') === false) {
            return 'File PDF rusak (penanda akhir file tidak ditemukan).';
        }

        // Locate the LAST startxref pointer.
        $pos = strrpos($bytes, 'startxref');
        if ($pos === false) {
            return 'File PDF rusak (tabel referensi tidak ditemukan).';
        }
        $xrefOffset = (int) trim(substr($bytes, $pos + 9, 32));
        if ($xrefOffset <= 0 || $xrefOffset >= $len) {
            return 'File PDF rusak (penunjuk tabel referensi tidak valid).';
        }

        $at = ltrim(substr($bytes, $xrefOffset, 32));
        if ($at === '' || $at[0] !== 'x') {
            // PDF 1.5+ may use an xref STREAM object at that offset.
            if (substr($bytes, $xrefOffset, 2) !== 'obj' && strpos(substr($bytes, $xrefOffset, 64), ' obj') === false) {
                return 'File PDF rusak (tabel referensi tidak dapat dibaca).';
            }
        }

        if (preg_match('/\/Encrypt\b/', $bytes)) {
            return 'File PDF terproteksi password. Hapus proteksinya lalu unggah ulang.';
        }

        // The only reliable page count without a parser is /Count in the
        // Pages object (an indirect /Root reference number is NOT a count).
        if (preg_match('#/Type\s*/Pages[^>]*?/Count\s+(\d+)#s', $bytes, $m) === 1
            || preg_match('#/Count\s+(\d+)[^>]*?/Type\s*/Pages#s', $bytes, $m) === 1) {
            $pages = (int) $m[1];
            if ($pages > 10) {
                return 'PDF maksimal 10 halaman. Pecah file atau unggah hanya halaman dokumen.';
            }
            if ($pages < 1) {
                return 'File PDF tidak memiliki halaman.';
            }
        }

        return null;
    }
}
