<?php

namespace App\Services\Activation;

use App\Models\ServiceRequest;

/**
 * Deterministic stand-in for a real activation provider, keyed by service_id
 * so assessment scenarios are reproducible without any real network call or
 * artificial delay:
 *
 *  6221 -> activate() succeeds immediately
 *  6222 -> activate() reports the provider unavailable (safe to retry)
 *  6223 -> activate() times out, but lookup() later confirms it did activate
 */
class FakeActivationProvider implements ActivationProvider
{
    public function activate(ServiceRequest $serviceRequest): ActivationOutcome
    {
        return match ($serviceRequest->service_id) {
            6221 => ActivationOutcome::Success,
            6222 => ActivationOutcome::Unavailable,
            6223 => ActivationOutcome::Timeout,
            default => ActivationOutcome::Unavailable,
        };
    }

    public function lookup(ServiceRequest $serviceRequest): LookupOutcome
    {
        return match ($serviceRequest->service_id) {
            6223 => LookupOutcome::Active,
            default => LookupOutcome::Inconclusive,
        };
    }
}
