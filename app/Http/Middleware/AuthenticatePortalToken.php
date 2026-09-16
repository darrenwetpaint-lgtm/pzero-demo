<?php

namespace App\Http\Middleware;

use App\Actions\Api\RecordApiRequestAudit;
use App\Models\PortalCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthenticatePortalToken
{
    public function __construct(private readonly RecordApiRequestAudit $auditor) {}

    /**
     * Authenticate a portal API request via a hashed Bearer token and
     * resolve the owning Organisation onto the request, without ever
     * trusting a client-supplied organisation identifier.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            // No organisation can be safely identified for an unauthenticated
            // attempt — audited with organisation_id = null (the column is
            // nullable specifically for this case).
            $this->auditor->handle($request, null, 401, 'missing_token');

            throw new HttpException(401, 'A valid portal Bearer token is required.');
        }

        $credential = PortalCredential::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $credential || $credential->revoked_at !== null) {
            // A revoked token still legitimately identifies its organisation
            // (derived server-side from the matched credential, never a
            // client-supplied claim), so that association is safe to audit;
            // an unmatched hash identifies nothing.
            $this->auditor->handle(
                $request,
                $credential?->organisation,
                401,
                $credential ? 'revoked_token' : 'invalid_token',
            );

            throw new HttpException(401, 'The provided portal token is invalid or has been revoked.');
        }

        $credential->update(['last_used_at' => now()]);

        $request->attributes->set('organisation', $credential->organisation);

        return $next($request);
    }
}
