<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon; 

class PaymentController extends Controller
{
    // AMBIL HARGA DARI SETTINGS
   
    public function getPrices()
    {
        return response()->json([
            'SD' => (int) (Setting::where('key', 'biaya_sd')->first()->value ?? 0),
            'SMP' => (int) (Setting::where('key', 'biaya_smp')->first()->value ?? 0)
        ]);
    }

    
    // BUAT INVOICE OTOMATIS KE DOKU
    
    public function createInvoice(Request $request)
    {
        $request->validate([
            'jenjang' => 'required|in:SD,SMP'
        ]);

        $user = Auth::user();
        
        $priceKey = 'biaya_' . strtolower($request->jenjang);
        $amount = (int) Setting::where('key', $priceKey)->first()->value;

        $invoiceNumber = 'SPMB-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $payment = Payment::create([
            'user_id' => $user->id,
            'invoice_number' => $invoiceNumber,
            'jenjang' => $request->jenjang,
            'amount' => $amount,
            'status' => 'PENDING'
        ]);

        $clientId = env('DOKU_CLIENT_ID');
        $secretKey = env('DOKU_SECRET_KEY');
        $targetPath = '/checkout/v1/payment';
        $url = env('DOKU_URL', 'https://api-sandbox.doku.com') . $targetPath;

        $requestId = (string) Str::uuid();
        $timestamp = gmdate("Y-m-d\TH:i:s\Z");

        $payload = [
            "order" => [
                "amount" => $amount,
                "invoice_number" => $invoiceNumber,
                "currency" => "IDR", 
                "callback_url" => "http://localhost:3000/pendaftar",
                "callback_url_cancel" => "http://localhost:3000/pendaftar/pembayaran"
            ],
            "payment" => [
                "payment_due_date" => 60 // Waktu tagihan basi (60 menit)
            ],
            "customer" => [
                "name" => substr($user->name, 0, 50),
                "email" => $user->email,
                "phone" => "081234567890" 
            ]
        ];

        $jsonBody = json_encode($payload);
        $signature = $this->generateDokuSignature($clientId, $requestId, $timestamp, $targetPath, $jsonBody, $secretKey);

        $response = Http::withHeaders([
            'Client-Id' => $clientId,
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => $signature,
        ])
        ->withBody($jsonBody, 'application/json') 
        ->post($url);

        if ($response->successful()) {
            $paymentUrl = $response->json()['response']['payment']['url'];
            $payment->update(['payment_url' => $paymentUrl]);

            return response()->json([
                'success' => true,
                'payment_url' => $paymentUrl,
                'invoice' => $invoiceNumber
            ]);
        }

        return response()->json([
            'success' => false, 
            'message' => 'Gagal connect ke DOKU',
            'doku_error' => $response->json() 
        ], 500);
    }

    private function generateDokuSignature($clientId, $requestId, $timestamp, $targetPath, $jsonBody, $secretKey)
    {
        $digest = base64_encode(hash('sha256', $jsonBody, true));
        $signatureString = "Client-Id:" . $clientId . "\n"
                         . "Request-Id:" . $requestId . "\n"
                         . "Request-Timestamp:" . $timestamp . "\n"
                         . "Request-Target:" . $targetPath . "\n"
                         . "Digest:" . $digest;
        $signature = hash_hmac('sha256', $signatureString, $secretKey, true);
        return "HMACSHA256=" . base64_encode($signature);
    }

   
    // CEK STATUS PEMBAYARAN (DENGAN AUTO-EXPIRE)
    
    public function checkStatus()
    {
        $user = Auth::user();
        $payment = Payment::where('user_id', $user->id)->latest()->first();

        if (!$payment) {
            return response()->json(['has_payment' => false, 'step' => 1]);
        }

        
        if ($payment->status === 'PENDING') {
            $waktuDibuat = Carbon::parse($payment->created_at);
            
            
            if ($waktuDibuat->diffInMinutes(Carbon::now()) >= 60) {
                // Ubah status di database jadi EXPIRED biar basi
                $payment->update(['status' => 'EXPIRED']);
            }
        }

        // Kalau statusnya udah EXPIRED atau gagal (FAILED)
       
        if ($payment->status === 'EXPIRED' || $payment->status === 'FAILED') {
            return response()->json(['has_payment' => false, 'step' => 1]);
        }

        // Kalau status SUCCESS, otomatis step pendaftaran naik ke 2 (Biodata)
        $step = $payment->status === 'SUCCESS' ? 2 : 1;

        return response()->json([
            'has_payment' => true,
            'payment' => $payment,
            'step' => $step
        ]);
    }

   
    // FUNGSI UPLOAD MANUAL TRANSFER
    
    public function manualPayment(Request $request)
    {
        $request->validate([
            'jenjang' => 'required|in:SD,SMP',
            'bukti_bayar' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        $user = Auth::user();
        $priceKey = 'biaya_' . strtolower($request->jenjang);
        $amount = (int) Setting::where('key', $priceKey)->first()->value;
        $invoiceNumber = 'MANUAL-' . date('Ymd') . '-' . strtoupper(Str::random(4));

        $path = $request->file('bukti_bayar')->store('bukti_bayar', 'public');

        Payment::create([
            'user_id' => $user->id,
            'invoice_number' => $invoiceNumber,
            'jenjang' => $request->jenjang,
            'amount' => $amount,
            'payment_method' => 'MANUAL',
            'proof_of_payment' => $path,
            'status' => 'PENDING_MANUAL' 
        ]);

        return response()->json(['success' => true, 'message' => 'Bukti pembayaran berhasil diunggah.']);
    }

    
    // FUNGSI ADMIN: TARIK DATA MANUAL
    
    public function getPendingManual()
    {
        $payments = Payment::with('user:id,name,email')
            ->where('status', 'PENDING_MANUAL')
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($payments);
    }

   
    // FUNGSI ADMIN: VERIFIKASI MANUAL
    
    public function verifyManual($id)
    {
        $payment = Payment::findOrFail($id);
        $payment->update(['status' => 'SUCCESS']);
        
        return response()->json(['success' => true, 'message' => 'Pembayaran berhasil diverifikasi.']);
    }
}