<?php

// Supply reviewed formal terms server-side before issuing Daftar Ulang invoices.
//
// These values are environment-backed so reviewed legal terms can be provided
// per-deployment WITHOUT being committed to source control. The defaults are
// deliberately null: an empty or missing configuration MUST keep invoice
// creation fail-closed. DaftarUlangService re-validates both fields at invoice
// time (rejecting null, blank, and whitespace-only values), so a misconfigured
// deployment can never silently issue an invoice with placeholder terms.
//
// Do NOT put fake or placeholder agreement text here — provide the reviewed
// document via the environment.
return [
    'agreement' => [
        'version' => env('DAFTAR_ULANG_AGREEMENT_VERSION'),
        'text' => env('DAFTAR_ULANG_AGREEMENT_TEXT'),
    ],
];
