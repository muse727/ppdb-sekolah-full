<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Biodata extends Model
{
    use HasFactory;
    protected $guarded = []; // Biar ga capek nulis fillable satu-satu, karena semua kolom boleh diisi
    
    public function user() {
        return $this->belongsTo(User::class);
    }
}