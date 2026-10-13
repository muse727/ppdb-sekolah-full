<?php
$app = require __DIR__.'/p5_bootstrap.php';
use App\Models\User;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

if (in_array('--snapshot', $argv, true)) {
    echo hash('sha256', json_encode(array_map(fn ($t) => DB::table($t)->orderBy('id')->get()->toArray(), ['users', 'payments', 'biodatas', 'ppdb_seat_allocations', 'tokens', 'whitelists', 'smp_email_tokens'])));
    exit;
}
if (in_array('--cleanup', $argv, true)) {
    User::where('email', 'like', '%@p5-http.test')->delete();
    Storage::disk('private')->deleteDirectory('bukti_bayar');
    echo "P5 test fixtures removed\n"; exit;
}
if (User::where('email', 'like', '%@p5-http.test')->exists()) {
    throw new RuntimeException('Existing P5 fixture found; clean up explicitly first.');
}
$admin = User::factory()->create(['name' => 'Admin P5', 'email' => 'admin@p5-http.test', 'password' => 'P5-test-password']);
$admin->forceFill(['role' => 'admin'])->save();
$guru = User::factory()->create(['email' => 'guru@p5-http.test']); $guru->forceFill(['role' => 'guru'])->save();
$users = [];
foreach ([null, 'PENDING_MANUAL', 'SUCCESS', 'REJECTED', 'FAILED', 'EXPIRED', 'PENDING'] as $i => $state) {
    $u = User::factory()->create(['name' => $i === 3 ? '<script>alert("P5")</script>' : 'Peserta P5 '.$i, 'email' => 'peserta'.$i.'@p5-http.test', 'jenjang' => $i % 2 ? 'SMP' : 'SD']);
    $u->forceFill(['role' => 'orang_tua', 'status_kelulusan' => $i === 0 ? 'BELUM' : ($i % 2 ? 'TIDAK_LULUS' : 'LULUS')])->save();
    if ($i !== 0) {
        DB::table('biodatas')->insert([
            'user_id' => $u->id, 'jalur_pendaftaran' => $i % 2 ? 'INTERNAL_TK' : 'UMUM',
            'asal_sekolah' => 'TK TEST P5', 'nama_lengkap' => $u->name, 'jenis_kelamin' => 'L',
            'nik_siswa' => '123', 'no_kk' => '123', 'tempat_lahir' => 'Jakarta', 'tanggal_lahir' => '2020-01-01',
            'anak_ke' => 1, 'status_peserta_didik' => 'Lengkap', 'nama_ibu' => 'Ibu', 'nik_ibu' => '123',
            'pendidikan_ibu' => 'S1', 'pekerjaan_ibu' => 'Guru', 'penghasilan_ibu' => '100',
            'nama_ayah' => 'Ayah', 'nik_ayah' => '123', 'pendidikan_ayah' => 'S1', 'pekerjaan_ayah' => 'Guru',
            'penghasilan_ayah' => '100', 'alamat_lengkap' => 'Jalan', 'kota' => 'Jakarta', 'kecamatan' => 'Cilandak',
            'kelurahan' => 'Lebak Bulus', 'rt' => '1', 'rw' => '1', 'no_hp_ortu' => '08123',
        ]);
    }
    if ($state) {
        $image = imagecreatetruecolor(600, 240); $bg = imagecolorallocate($image, 235, 245, 240); imagefill($image, 0, 0, $bg); imagestring($image, 5, 20, 80, 'BUKTI PEMBAYARAN TEST P5 - '.$state, imagecolorallocate($image, 20, 70, 50));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $path = 'bukti_bayar/p5-'.$i.'.png'; Storage::disk('private')->put($path, $bytes);
        Payment::create(['user_id' => $u->id, 'jenjang' => $u->jenjang, 'status' => 'REJECTED', 'amount' => 100000, 'invoice_number' => 'P5-OLD-'.$i]);
        $p = Payment::create(['user_id' => $u->id, 'jenjang' => $u->jenjang, 'status' => $state, 'amount' => 175000, 'invoice_number' => 'P5-HTTP-'.$i, 'payment_method' => 'MANUAL', 'proof_of_payment' => $path, 'verified_at' => in_array($state, ['SUCCESS', 'REJECTED']) ? now() : null, 'rejection_reason' => $state === 'REJECTED' ? '<img src=x onerror=alert(1)>' : null]);
    }
    $users[] = ['id' => $u->id, 'payment_id' => isset($p) && $state ? $p->id : null, 'state' => $state];
}
echo json_encode(['admin_token' => $admin->createToken('p5-http')->plainTextToken, 'guru_token' => $guru->createToken('p5-http')->plainTextToken, 'users' => $users]);
