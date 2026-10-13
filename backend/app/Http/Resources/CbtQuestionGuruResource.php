<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P4-B — GURU/ADMIN representation of a CBT question.
 *
 * This is the ONLY serialization path that may carry `kunci_jawaban`. It is
 * reached exclusively from routes gated server-side by
 * `auth:sanctum` + `CheckRole:guru`, so inclusion of the answer key is decided
 * by authorization, never by anything the client sends.
 *
 * The audience boundary is expressed as two distinct classes rather than as a
 * flag or a query parameter: there is deliberately no `include_answer`,
 * `show_key` or `role` input that can select this resource, so a student cannot
 * upgrade themselves into it by manipulating a request.
 *
 * Field list mirrors what the model returned before `kunci_jawaban` was added to
 * `$hidden`, so the guru UI contract is unchanged apart from the key arriving.
 */
class CbtQuestionGuruResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'cbt_session_id' => $this->cbt_session_id,
            'jenis_soal'     => $this->jenis_soal,
            'pertanyaan'     => $this->pertanyaan,
            'gambar'         => $this->gambar,
            'opsi_a'         => $this->opsi_a,
            'opsi_b'         => $this->opsi_b,
            'opsi_c'         => $this->opsi_c,
            'opsi_d'         => $this->opsi_d,
            // Direct attribute access bypasses `$hidden`, which only filters
            // serialization — this is intentional and authorized above.
            'kunci_jawaban'  => $this->kunci_jawaban,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
