<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'cbt_session_id',
        'jenis_soal',
        'pertanyaan',
        'gambar',
        'opsi_a',
        'opsi_b',
        'opsi_c',
        'opsi_d',
        'kunci_jawaban',
    ];

    /**
     * P4-B: `kunci_jawaban` is stripped from every DEFAULT serialization of this
     * model. This is defence in depth only — it is NOT the security boundary,
     * because a model-global rule cannot express an audience-specific rule and
     * would also hide the key from the guru endpoint that legitimately needs it.
     *
     * The authoritative boundary is the two explicit API Resources:
     *   - CbtQuestionStudentResource — allowlist, never includes the key.
     *   - CbtQuestionGuruResource    — includes it; reached only via routes
     *     gated by `auth:sanctum` + `CheckRole:guru`, so inclusion is decided by
     *     server-side authorization and never by a client-supplied flag.
     * Resources read attributes directly, so `$hidden` does not block them.
     */
    protected $hidden = ['kunci_jawaban'];

    public function session()
    {
        return $this->belongsTo(CbtSession::class, 'cbt_session_id');
    }
}
