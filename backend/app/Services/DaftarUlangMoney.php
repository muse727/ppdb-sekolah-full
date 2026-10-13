<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DaftarUlangMoney
{
    public const MAX = 2000000000;

    public function calculate(array $items, mixed $discount): array
    {
        Validator::make(['items' => $items, 'discount_nominal' => $discount], [
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|array:id,name,quantity,unit_amount',
            'items.*.id' => 'required|string|max:60|distinct',
            'items.*.name' => 'required|string|max:150',
            'items.*.quantity' => 'required|integer|min:1|max:1000',
            'items.*.unit_amount' => 'required|integer|min:0|max:'.self::MAX,
            'discount_nominal' => 'required|integer|min:0|max:'.self::MAX,
        ])->validate();
        // JSON numbers must actually be integers: reject floats, booleans and
        // numeric strings rather than silently coercing authoritative money.
        if (!is_int($discount)) {
            throw ValidationException::withMessages(['discount_nominal' => 'Gunakan integer Rupiah.']);
        }
        $subtotal = 0;
        $lines = [];
        foreach ($items as $item) {
            if (!is_int($item['quantity']) || !is_int($item['unit_amount'])) {
                throw ValidationException::withMessages(['items' => 'Jumlah dan Rupiah harus integer.']);
            }
            $line = $item['quantity'] * $item['unit_amount'];
            $subtotal += $line;
            if ($subtotal > self::MAX) {
                throw ValidationException::withMessages(['items' => 'Subtotal melampaui batas.']);
            }
            $lines[] = [...$item, 'line_total' => $line];
        }
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount_nominal' => 'Diskon tidak boleh melebihi subtotal.']);
        }
        return ['items' => $lines, 'subtotal' => $subtotal, 'discount_nominal' => $discount, 'total' => $subtotal - $discount];
    }
}
