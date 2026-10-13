<?php

namespace App\Models;

class DaftarUlangPricingRevision extends ImmutableDaftarUlangRecord
{
    protected $table = 'du_pricing_revisions';
    protected $casts = ['items' => 'array', 'revision' => 'integer', 'subtotal' => 'integer',
        'discount_nominal' => 'integer', 'total' => 'integer'];
}
