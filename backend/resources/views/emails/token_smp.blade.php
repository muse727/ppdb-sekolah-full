<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8fafc; padding: 20px; }
        .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .header { background-color: #1a5f51; padding: 30px 20px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 800; }
        .body { padding: 30px; color: #334155; }
        .token-box { background-color: #f1f5f9; border: 2px dashed #cbd5e1; padding: 20px; text-align: center; border-radius: 12px; margin: 25px 0; }
        .token-code { font-size: 32px; font-weight: 900; letter-spacing: 4px; color: #1a5f51; margin: 0; font-family: monospace; }
        .btn { display: inline-block; background-color: #1a5f51; color: #ffffff; text-decoration: none; padding: 14px 30px; border-radius: 8px; font-weight: bold; margin-top: 20px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; background-color: #f8fafc; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <h1>Token Pendaftaran SMPIT</h1>
        </div>
        <div class="body">
            <p>Ahlan Wa Sahlan, <strong>{{ $data['nama'] }}</strong>!</p>
            <p>Terima kasih atas ketertarikan Anda untuk bergabung dengan SMPIT Al-Mubarak. Berikut adalah Kode Token Pendaftaran Anda:</p>
            
            <div class="token-box">
                <p class="token-code">{{ $data['token'] }}</p>
            </div>
            
            <p style="color: #e53e3e; font-size: 13px; font-weight: bold;">*Mohon simpan dan jangan berikan token ini kepada orang lain. Token ini hanya berlaku untuk pendaftaran SMPIT.</p>
            
            <div style="text-align: center;">
                <a href="https://ppdb.al-mubarak.com/login" class="btn">Lanjutkan Pendaftaran</a>
            </div>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Yayasan Pendidikan Islam Al-Mubarak. All rights reserved.</p>
        </div>
    </div>
</body>
</html>