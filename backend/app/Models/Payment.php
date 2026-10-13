<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    public const CATEGORY_PPDB = 'PPDB';
    public const CATEGORY_DAFTAR_ULANG = 'DAFTAR_ULANG';
    public const CATEGORIES = [self::CATEGORY_PPDB, self::CATEGORY_DAFTAR_ULANG];

    protected $attributes = ['payment_category' => self::CATEGORY_PPDB];

    protected static function booted(): void
    {
        static::saving(function (self $payment) {
            self::validateCategory($payment->payment_category);
        });
    }

    public static function validateCategory(string $category): void
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Invalid payment category.');
        }
    }

    public function scopeForCategory(\Illuminate\Database\Eloquent\Builder $query, string $category): \Illuminate\Database\Eloquent\Builder
    {
        self::validateCategory($category);
        return $query->where('payment_category', $category);
    }

    public function scopePpdb(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->forCategory(self::CATEGORY_PPDB);
    }

    public function scopeDaftarUlang(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->forCategory(self::CATEGORY_DAFTAR_ULANG);
    }

    public static function hasBlockingPayment(int $userId, string $category): bool
    {
        $latest = static::forCategory($category)->where('user_id', $userId)->latest('id')->first();
        return $latest && in_array($latest->status, ['SUCCESS', 'PENDING_MANUAL'], true);
    }

    protected $fillable = [
        'user_id',
        'payment_category',
        'invoice_number',
        'jenjang',
        'amount',
        'status',
        'verifier_id',
        'verified_at',
        'rejection_reason',
        'payment_method',
        'proof_of_payment',
    ];

    protected $casts = [
        'amount' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
