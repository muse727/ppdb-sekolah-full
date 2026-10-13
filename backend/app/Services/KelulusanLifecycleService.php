<?php

namespace App\Services;

use App\Models\PpdbCapacity;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KelulusanLifecycleService
{
    public const STATUSES = ['BELUM', 'LULUS', 'TIDAK_LULUS'];

    /** User ID => requested status; validate the entire batch before mutating it. */
    public function transition(array $changes, ?int $expectedAllocationId = null): array
    {
        if (!$changes || array_diff(array_values($changes), self::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Status kelulusan tidak valid.']);
        }
        ksort($changes, SORT_NUMERIC);

        return DB::transaction(function () use ($changes, $expectedAllocationId) {
            // Match registration's capacity -> allocation order. Lock both schools
            // in a stable order because gate reopening observes both capacities.
            PpdbCapacity::whereIn('jenjang', PpdbCapacityService::JENJANGS)
                ->orderBy('jenjang')->lockForUpdate()->get();
            $users = User::whereIn('id', array_keys($changes))->where('role', 'orang_tua')
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($users->count() === count($changes), 404);
            $history = PpdbSeatAllocation::whereIn('user_id', array_keys($changes))
                ->orderBy('user_id')->orderBy('id')->lockForUpdate()->get()->groupBy('user_id');
            $seats = [];
            foreach ($changes as $id => $status) {
                $rows = $history->get($id, collect());
                $active = $rows->where('status', PpdbSeatAllocation::STATUS_ALLOCATED);
                $seat = $rows->last();
                if (!$seat || $active->count() > 1
                    || !in_array($seat->status, ['ALLOCATED', 'RELEASED'], true)
                    || ($active->isNotEmpty() && $active->first()->id !== $seat->id)
                    || ($expectedAllocationId !== null && $seat->id !== $expectedAllocationId)) {
                    throw ValidationException::withMessages(['status' => "Alokasi kursi peserta {$id} tidak valid."]);
                }
                if ($status !== 'TIDAK_LULUS'
                    && ($seat->status !== 'ALLOCATED' || $users[$id]->status_kelulusan === 'TIDAK_LULUS')) {
                    throw ValidationException::withMessages(['status' => "Peserta {$id} tidak dapat kembali ke {$status} setelah kursinya dilepas."]);
                }
                $seats[$id] = $seat;
            }

            $released = false;
            foreach ($changes as $id => $status) {
                $seat = $seats[$id];
                if ($status === 'TIDAK_LULUS' && $seat->status === 'ALLOCATED') {
                    $seat->forceFill(['status' => 'RELEASED', 'released_at' => now(),
                        'release_reason' => PpdbCapacityService::RELEASE_NOT_ACCEPTED])->save();
                    $released = true;
                }
                if ($users[$id]->status_kelulusan !== $status) {
                    $users[$id]->forceFill(['status_kelulusan' => $status])->save();
                }
            }
            // Remaining quota is derived, never incremented as a writable counter.
            if ($released && Setting::where('key', 'ppdb_auto_closed')->value('value') === '1') {
                $capacity = new PpdbCapacityService;
                if ($capacity->hasRemaining('SD') || $capacity->hasRemaining('SMP')) {
                    Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
                    Setting::updateOrCreate(['key' => 'ppdb_auto_closed'], ['value' => '0']);
                }
            }
            return $seats;
        });
    }
}
