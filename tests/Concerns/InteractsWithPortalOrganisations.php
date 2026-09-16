<?php

namespace Tests\Concerns;

use App\Models\Organisation;
use App\Models\PortalCredential;
use App\Models\ServiceRequest;

trait InteractsWithPortalOrganisations
{
    /**
     * Create an organisation with a portal credential, returning the
     * organisation and the raw (unhashed) test token for it.
     *
     * @return array{0: Organisation, 1: string}
     */
    protected function organisationWithToken(int $clientId, string $name): array
    {
        $organisation = Organisation::create([
            'client_id' => $clientId,
            'name' => $name,
        ]);

        $rawToken = 'test-token-'.$clientId.'-'.str()->random(8);

        PortalCredential::create([
            'organisation_id' => $organisation->id,
            'name' => "Test token for {$name}",
            'token_hash' => hash('sha256', $rawToken),
        ]);

        return [$organisation, $rawToken];
    }

    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeServiceRequest(int $organisationId, int $customerId, array $overrides = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'organisation_id' => $organisationId,
            'customer_id' => $customerId,
            'service_id' => 6221,
            'action' => 'activate',
            'requested_by' => 'customer',
            'amount' => '100.00',
            'currency' => 'ZAR',
            'status' => 'queued',
            'display_reference' => '10482-'.now()->timestamp,
            'idempotency_key' => (string) str()->uuid(),
            'request_fingerprint' => hash('sha256', (string) str()->uuid()),
        ], $overrides));
    }
}
