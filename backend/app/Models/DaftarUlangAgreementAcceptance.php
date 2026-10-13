<?php

namespace App\Models;

class DaftarUlangAgreementAcceptance extends ImmutableDaftarUlangRecord
{
    protected $table = 'du_agreement_acceptances';
    protected $casts = ['agreed_at' => 'datetime'];
}
