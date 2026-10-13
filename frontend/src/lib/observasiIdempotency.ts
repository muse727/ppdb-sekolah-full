// E.12G Phase 4 — client-side idempotency-key management for observasi
// submit/edit. Persists the in-flight key (and the MINIMUM retry payload) in
// sessionStorage so a page refresh mid-request can replay with the SAME key.
//
// SECURITY: we store ONLY the idempotency key and the canonical payload that
// hashes to the server-side payload_hash (user_id, jawaban_json,
// rekomendasi_akhir, expected_version). No auth tokens, no candidate PII
// beyond the operation itself, no printable snapshot.
//
// Namespacing: keys are scoped per user id + endpoint class, so logout/login
// as another user can never reuse or trip over another account's pending
// operation.

export interface PendingObservasiOp {
  idemKey: string;
  userId: number;
  payload: {
    user_id: number;
    jawaban_json: unknown;
    rekomendasi_akhir?: string;
    expected_version?: number;
  };
  createdAt: number;
}

const STORAGE_PREFIX = 'e12g_idem_';
const MAX_AGE_MS = 24 * 60 * 60 * 1000; // mirror the 24h server retention

function scopeKey(endpointClass: string, userId: number | null): string {
  return `${STORAGE_PREFIX}${endpointClass}_${userId ?? 'anon'}`;
}

/** Create (or reuse) the pending operation record BEFORE the request fires. */
export function beginObservasiIdempotentOp(
  endpointClass: string,
  actorId: number | null,
  payload: PendingObservasiOp['payload'],
): string {
  const storeKey = scopeKey(endpointClass, actorId);

  // If an unresolved operation for the same actor+endpoint is pending and
  // the payload is IDENTICAL, reuse its key — this is the refresh/retry case.
  const existing = readPendingOp(endpointClass, actorId);
  if (existing && JSON.stringify(existing.payload) === JSON.stringify(payload)
    && Date.now() - existing.createdAt < MAX_AGE_MS) {
    return existing.idemKey;
  }

  // New logical operation → new key. (Never reuse one key for two different
  // operations — the server would reject the second with 422.)
  const idemKey = crypto.randomUUID().replace(/-/g, '');
  const op: PendingObservasiOp = { idemKey, userId: actorId ?? 0, payload, createdAt: Date.now() };
  sessionStorage.setItem(storeKey, JSON.stringify(op));

  return idemKey;
}

/** Read the pending op (for retry-after-refresh flows). */
export function readPendingOp(
  endpointClass: string,
  actorId: number | null,
): PendingObservasiOp | null {
  try {
    const raw = sessionStorage.getItem(scopeKey(endpointClass, actorId));
    if (!raw) return null;
    const op = JSON.parse(raw) as PendingObservasiOp;
    if (Date.now() - op.createdAt > MAX_AGE_MS) {
      sessionStorage.removeItem(scopeKey(endpointClass, actorId));
      return null;
    }
    return op;
  } catch {
    return null;
  }
}

/**
 * Clear the pending op — ONLY after a final, valid response arrived
 * (success OR a definitive 403/409/422). On 503/network failure the op stays
 * so the same key is retried.
 */
export function clearObservasiIdempotentOp(endpointClass: string, actorId: number | null): void {
  sessionStorage.removeItem(scopeKey(endpointClass, actorId));
}
