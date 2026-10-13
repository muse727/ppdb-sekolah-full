<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P4-B — STUDENT representation of a CBT question.
 *
 * Deliberately omits `kunci_jawaban`. The field list is an explicit allowlist
 * (not a "hide the key" denylist), so a future column added to `cbt_questions`
 * can never leak to a student by accident. This is the same set of fields the
 * inline map in `loginCbt` already returned; moving it here gives every
 * student-facing path one authoritative shape.
 *
 * `kunci_jawaban` remains in `CbtQuestion::$hidden` as defence in depth, but the
 * security boundary is this allowlist: it does not depend on model-global
 * serialization behaviour.
 */
class CbtQuestionStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'jenis_soal' => $this->jenis_soal,
            'pertanyaan' => $this->pertanyaan,
            'gambar'     => $this->gambar,
            'opsi_a'     => $this->opsi_a,
            'opsi_b'     => $this->opsi_b,
            'opsi_c'     => $this->opsi_c,
            'opsi_d'     => $this->opsi_d,
        ];
    }
}
