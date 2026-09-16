<?php

namespace App\Services\Activation;

use App\Models\ServiceRequest;

/**
 * Abstraction over the external activation provider. A real provider would
 * make an HTTP call; the queue job never depends on which one it's talking to.
 */
interface ActivationProvider
{
    /**
     * Attempt to activate the service request. $serviceRequest->provider_request_id
     * is already persisted before this is called, and must be used as the
     * correlation identifier for the provider call.
     */
    public function activate(ServiceRequest $serviceRequest): ActivationOutcome;

    /**
     * Reconcile a request left "uncertain" after a timeout, using the same
     * provider_request_id that was passed to activate().
     */
    public function lookup(ServiceRequest $serviceRequest): LookupOutcome;
}
