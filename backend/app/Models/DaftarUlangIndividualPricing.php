<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DaftarUlangIndividualPricing extends Model
{
    protected $table = 'du_individual_pricings';
    protected $guarded = ['id'];
    protected $casts = ['active' => 'boolean'];

    public function revision()
    {
        return $this->belongsTo(DaftarUlangPricingRevision::class, 'pricing_revision_id');
    }
}
