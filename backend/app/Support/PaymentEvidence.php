<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared private-storage primitives for payment evidence.
 *
 * Both the PPDB and DAFTAR_ULANG evidence paths must behave identically at the
 * storage layer: server-derived category is decided by the CONTROLLERS, never
 * by this class and never by the client. This class only handles the bytes.
 *
 * Guarantees kept in one place so the two categories cannot drift:
 *  - files always land on the non-servable `private` disk (never a public URL)
 *  - filenames are Laravel's random hashed names (never client-controlled)
 *  - camera metadata (EXIF/XMP) is stripped losslessly on upload
 *  - downloads advertise the real stored MIME type, never a client or
 *    extension guess
 */
final class PaymentEvidence
{
    private const DISK = 'private';

    /**
     * Store an uploaded proof on the private disk with camera metadata removed.
     * The returned path is server-generated and safe to persist verbatim.
     */
    public static function store(UploadedFile $file, string $directory): string
    {
        return ImageMetadata::storeSanitized($file, $directory, self::DISK);
    }

    /** True when the private disk actually holds the given stored path. */
    public static function exists(?string $storedPath): bool
    {
        return ! empty($storedPath) && Storage::disk(self::DISK)->exists($storedPath);
    }

    /**
     * Stream the stored evidence privately. The Content-Type is derived from
     * the stored bytes (finfo), so a mismatched extension cannot relabel it.
     */
    public static function stream(string $storedPath): StreamedResponse
    {
        $contentType = Storage::disk(self::DISK)->mimeType($storedPath) ?: 'application/octet-stream';

        return Storage::disk(self::DISK)->response($storedPath, null, ['Content-Type' => $contentType]);
    }
}
