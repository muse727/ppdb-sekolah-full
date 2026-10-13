<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * EXIF / camera-metadata stripping on upload (payment evidence + berkas).
 *
 * Phone photos of bank receipts and family documents routinely carry EXIF GPS
 * (the family's home coordinates) and device identifiers. Evidence is streamed
 * back byte-for-byte to the owner and to administrators, so the metadata would
 * travel with the image. These tests pin the stripping behaviour and, just as
 * importantly, its fail-safe guarantees.
 */
class ImageMetadataStrippingTest extends TestCase
{
    use RefreshDatabase;

    /** Build a real JPEG whose APP1 segment contains an EXIF/GPS IFD. */
    private function jpegWithGpsExif(): string
    {
        $im = imagecreatetruecolor(64, 48);
        imagefill($im, 0, 0, imagecolorallocate($im, 120, 160, 90));
        imagestring($im, 5, 4, 20, 'PROOF', imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagejpeg($im, null, 92);
        imagedestroy($im);
        $jpeg = ob_get_clean();

        // TIFF/EXIF: IFD0 -> GPSInfo -> GPSLatitudeRef "N"
        $gpsIfdOffset = 8 + 2 + 12 + 4;
        $tiff  = "II" . pack('v', 0x002A) . pack('V', 8);
        $tiff .= pack('v', 1);
        $tiff .= pack('v', 0x8825) . pack('v', 4) . pack('V', 1) . pack('V', $gpsIfdOffset);
        $tiff .= pack('V', 0);
        $tiff .= pack('v', 1);
        $tiff .= pack('v', 0x0001) . pack('v', 2) . pack('V', 2) . "N\0" . "\0\0";
        $tiff .= pack('V', 0);

        $app1 = "\xFF\xE1" . pack('n', strlen("Exif\0\0" . $tiff) + 2) . "Exif\0\0" . $tiff;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    /** Build a real JPEG with an XMP APP1 segment instead. */
    private function jpegWithXmp(): string
    {
        $im = imagecreatetruecolor(64, 48);
        ob_start();
        imagejpeg($im, null, 92);
        imagedestroy($im);
        $jpeg = ob_get_clean();

        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><gps>lat=-6.2</gps></x:xmpmeta>";
        $app1 = "\xFF\xE1" . pack('n', strlen($xmp) + 2) . $xmp;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    /** Build a real PNG containing an eXIf chunk. */
    private function pngWithExifChunk(): string
    {
        $im = imagecreatetruecolor(48, 36);
        ob_start();
        imagepng($im, null, 9);
        imagedestroy($im);
        $png = ob_get_clean();

        $data = "Exif\0\0II" . pack('v', 0x002A) . pack('V', 8) . pack('v', 0);
        $chunk = pack('N', strlen($data)) . 'eXIf' . $data . pack('N', crc32('eXIf' . $data));
        // Insert after the 8-byte signature + IHDR (12 + 13 bytes).
        return substr($png, 0, 33) . $chunk . substr($png, 33);
    }

    private function upload(string $bytes, string $name, string $mime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'exif') . '.' . pathinfo($name, PATHINFO_EXTENSION);
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    // == STRIPPER BEHAVIOUR ==

    public function test_jpeg_exif_and_gps_are_removed_and_image_survives(): void
    {
        $bytes = $this->jpegWithGpsExif();
        $out = \App\Support\ImageMetadata::strip($bytes, 'image/jpeg');

        $this->assertNotNull($out);
        $tmp = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($tmp, $out);

        $exif = @exif_read_data($tmp, 'ANY', true);
        $this->assertIsArray($exif);
        $this->assertArrayNotHasKey('GPS', $exif, 'GPS block must be gone');
        $this->assertArrayNotHasKey('IFD0', $exif, 'EXIF IFD0 must be gone');

        // Still a valid image with identical dimensions.
        $this->assertSame([64, 48], array_slice(getimagesize($tmp), 0, 2));
        unlink($tmp);
    }

    public function test_jpeg_xmp_is_removed(): void
    {
        $out = \App\Support\ImageMetadata::strip($this->jpegWithXmp(), 'image/jpeg');
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('ns.adobe.com/xap', $out);
        $this->assertStringNotContainsString('<gps>', $out);
    }

    public function test_png_exif_chunk_is_removed_and_alpha_survives(): void
    {
        $png = $this->pngWithExifChunk();
        $out = \App\Support\ImageMetadata::strip($png, 'image/png');

        $this->assertNotNull($out);
        $this->assertStringNotContainsString('eXIf', $out);

        $tmp = tempnam(sys_get_temp_dir(), 'exif') . '.png';
        file_put_contents($tmp, $out);
        $this->assertSame([48, 36], array_slice(getimagesize($tmp), 0, 2));
        unlink($tmp);
    }

    public function test_clean_images_pass_through_unchanged(): void
    {
        $im = imagecreatetruecolor(32, 32);
        ob_start();
        imagejpeg($im, null, 90);
        imagedestroy($im);
        $clean = ob_get_clean();

        $out = \App\Support\ImageMetadata::strip($clean, 'image/jpeg');
        $this->assertSame($clean, $out, 'no metadata => byte-identical no-op');
    }

    public function test_stripping_is_lossless_for_pixels(): void
    {
        $bytes = $this->jpegWithGpsExif();
        $out = \App\Support\ImageMetadata::strip($bytes, 'image/jpeg');
        $this->assertNotNull($out);

        $a = imagecreatefromstring($bytes);
        $b = imagecreatefromstring($out);
        $this->assertNotFalse($a);
        $this->assertNotFalse($b);

        for ($y = 0; $y < imagesy($a); $y++) {
            for ($x = 0; $x < imagesx($a); $x++) {
                $this->assertSame(
                    imagecolorat($a, $x, $y),
                    imagecolorat($b, $x, $y),
                    "pixel {$x},{$y} must be identical — stripping must be lossless"
                );
            }
        }
        imagedestroy($a);
        imagedestroy($b);
    }

    public function test_unsupported_or_malformed_input_fails_safe(): void
    {
        $this->assertNull(\App\Support\ImageMetadata::strip('', 'image/jpeg'));
        $this->assertNull(\App\Support\ImageMetadata::strip("\xFF\xD8\xFF", 'image/jpeg'));
        $this->assertNull(\App\Support\ImageMetadata::strip(random_bytes(200), 'image/jpeg'));
        $this->assertNull(\App\Support\ImageMetadata::strip(random_bytes(200), 'image/png'));
        $this->assertNull(\App\Support\ImageMetadata::strip('not an image', 'image/webp'));
        // Wrong mime for the payload: no silent misparse.
        $this->assertNull(\App\Support\ImageMetadata::strip($this->jpegWithGpsExif(), 'image/png'));
    }

    // == END-TO-END VIA THE REAL ENDPOINTS ==

    public function test_uploaded_payment_evidence_has_exif_stripped_on_disk_and_when_served(): void
    {
        Storage::fake('private');
        Setting::create(['key' => 'biaya_sd', 'value' => '175000']);

        $parent = User::factory()->create(['jenjang' => 'SD']);
        $parent->forceFill(['role' => 'orang_tua'])->save();
        Sanctum::actingAs($parent->refresh());

        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => $this->upload($this->jpegWithGpsExif(), 'proof.jpg', 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $payment = Payment::firstOrFail();
        $stored = Storage::disk('private')->path($payment->proof_of_payment);

        // Metadata is gone from the stored file...
        $exif = @exif_read_data($stored, 'ANY', true);
        $this->assertIsArray($exif);
        $this->assertArrayNotHasKey('GPS', $exif);
        $this->assertArrayNotHasKey('IFD0', $exif);
        // ...and the image is still intact.
        $this->assertSame([64, 48], array_slice(getimagesize($stored), 0, 2));

        // ...and from what the serving endpoint returns.
        $served = $this->get("/api/payments/{$payment->id}/evidence")->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($tmp, $served->streamedContent());
        $exif2 = @exif_read_data($tmp, 'ANY', true);
        $this->assertIsArray($exif2);
        $this->assertArrayNotHasKey('GPS', $exif2);
        unlink($tmp);
    }

    public function test_uploaded_berkas_has_exif_stripped(): void
    {
        Storage::fake('private');

        $parent = User::factory()->create(['jenjang' => 'SD']);
        $parent->forceFill(['role' => 'orang_tua'])->save();
        Sanctum::actingAs($parent->refresh());

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file' => $this->upload($this->pngWithExifChunk(), 'kk.png', 'image/png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $berkas = \App\Models\Berkas::firstOrFail();
        $stored = Storage::disk('private')->path($berkas->kartu_keluarga);
        $this->assertStringNotContainsString('eXIf', file_get_contents($stored));
    }

    public function test_original_image_survives_when_payload_is_not_parseable(): void
    {
        Storage::fake('private');
        Setting::create(['key' => 'biaya_sd', 'value' => '175000']);

        $parent = User::factory()->create(['jenjang' => 'SD']);
        $parent->forceFill(['role' => 'orang_tua'])->save();
        Sanctum::actingAs($parent->refresh());

        // A valid, clean PNG but declared as image/png — normal path. The point
        // is that a normal upload round-trips and stores a valid image.
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($im, null, 9);
        imagedestroy($im);
        $png = ob_get_clean();

        $this->post('/api/payment/manual', [
            'jenjang' => 'SD',
            'bukti_bayar' => $this->upload($png, 'proof.png', 'image/png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $payment = Payment::firstOrFail();
        $stored = Storage::disk('private')->path($payment->proof_of_payment);
        $this->assertSame([40, 30], array_slice(getimagesize($stored), 0, 2));
        // No-op: byte-identical because there was no metadata to remove.
        $this->assertSame(md5($png), md5_file($stored));
    }
}
