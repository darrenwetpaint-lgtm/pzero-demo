<?php

namespace App\Actions\Api;

use App\Models\ApiRequestAudit;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a durable audit row for a portal API request that was rejected or
 * failed before it could produce (or affect) a normal ServiceRequest record —
 * an invalid token, a validation failure, an idempotency conflict, and so on.
 * Successful submissions and idempotent replays are never audited here: the
 * ServiceRequest itself and its own history already represent those.
 *
 * This is deliberately best-effort: a failure to write the audit row must
 * never turn the real (already-decided) API response into a 500, so any
 * exception here is caught and logged, not propagated.
 */
class RecordApiRequestAudit
{
    /**
     * @param  array<string, mixed>  $context  already-whitelisted, safe fields only — never raw request data
     */
    public function handle(Request $request, ?Organisation $organisation, int $statusCode, string $reason, array $context = []): void
    {
        try {
            ApiRequestAudit::create([
                'organisation_id' => $organisation?->id,
                'method' => $request->method(),
                'route' => $request->path(),
                'status_code' => $statusCode,
                'reason' => $reason,
                'context' => $context === [] ? null : $context,
                'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to record api_request_audits row.', [
                'reason' => $reason,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
