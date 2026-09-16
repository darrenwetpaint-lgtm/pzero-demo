<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\ServiceRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds representative ServiceRequest rows (with internally-consistent
 * history) for demo purposes only.
 *
 * Deliberately uses ServiceRequest::create()/ServiceRequestEvent::create()
 * directly — never the SubmitServiceRequest action and never
 * ActivateServiceRequestJob::dispatch() — so seeding can never insert a row
 * into the `jobs` table. A queue worker only ever picks up rows that were
 * actually dispatched; since nothing here is dispatched, seeded
 * queued/processing/retrying rows are guaranteed to stay exactly as seeded
 * rather than being picked up and mutated by a running worker.
 *
 * Each lifecycle shape below reuses the exact same actor_type/message text
 * ActivateServiceRequestJob itself produces, so a seeded history is
 * indistinguishable from one produced by a real run.
 */
class ServiceRequestDemoSeeder extends Seeder
{
    public function run(): void
    {
        $northstar = Organisation::where('client_id', 18)->firstOrFail();
        $northstarCustomers = Customer::where('organisation_id', $northstar->id)->orderBy('customer_number')->get();

        $bluewave = Organisation::where('client_id', 27)->firstOrFail();
        $bluewaveCustomers = Customer::where('organisation_id', $bluewave->id)->orderBy('customer_number')->get();

        // Northstar: enough of every status to demonstrate the operator
        // interface (filtering, history, Retry availability) clearly, with
        // multiple Completed rows so the list doesn't look sparse.
        $this->completed($northstar, $northstarCustomers[0], 6221, '799.95', daysAgo: 6);
        $this->completed($northstar, $northstarCustomers[1], 6221, '1250.00', daysAgo: 5);
        $this->completed($northstar, $northstarCustomers[0], 6221, '450.50', daysAgo: 3);
        $this->completed($northstar, $northstarCustomers[0], 6221, '999.99', daysAgo: 1);
        $this->failed($northstar, $northstarCustomers[1], 6222, '899.00', daysAgo: 4);
        $this->failed($northstar, $northstarCustomers[0], 6222, '150.00', daysAgo: 2);
        $this->uncertain($northstar, $northstarCustomers[0], 6223, '675.25', daysAgo: 2);
        $this->queued($northstar, $northstarCustomers[1], 6221, '320.00', hoursAgo: 2);
        $this->processing($northstar, $northstarCustomers[0], 6221, '540.75', hoursAgo: 1);
        $this->retrying($northstar, $northstarCustomers[1], 6222, '210.00', hoursAgo: 3);

        // Bluewave: a smaller but still representative spread, mainly to
        // prove organisation isolation against Northstar's larger dataset.
        $this->completed($bluewave, $bluewaveCustomers[0], 6221, '500.00', daysAgo: 5);
        $this->completed($bluewave, $bluewaveCustomers[1], 6221, '1100.00', daysAgo: 3);
        $this->failed($bluewave, $bluewaveCustomers[0], 6222, '275.00', daysAgo: 3);
        $this->uncertain($bluewave, $bluewaveCustomers[1], 6223, '620.00', daysAgo: 1);
        $this->queued($bluewave, $bluewaveCustomers[0], 6221, '340.00', hoursAgo: 4);
        $this->retrying($bluewave, $bluewaveCustomers[1], 6222, '480.00', hoursAgo: 2);
    }

    private function completed(Organisation $organisation, Customer $customer, int $serviceId, string $amount, int $daysAgo): void
    {
        $created = now()->subDays($daysAgo);
        $completedAt = $created->clone()->addSeconds(2);
        $providerRequestId = (string) Str::uuid();

        $serviceRequest = $this->createServiceRequest($organisation, $customer, $serviceId, $amount, $created, [
            'status' => 'completed',
            'provider_attempts' => 1,
            'provider_request_id' => $providerRequestId,
            'last_attempted_at' => $created,
            'completed_at' => $completedAt,
        ]);

        $this->accepted($serviceRequest, $created);
        $this->event($serviceRequest, $created, 'queued', 'processing', 'system', 'Processing started (attempt 1).', ['provider_attempt' => 1]);
        $this->event($serviceRequest, $completedAt, 'processing', 'completed', 'provider', 'Activation confirmed.', [
            'provider_outcome' => 'success',
            'provider_request_id' => $providerRequestId,
        ]);
    }

    private function failed(Organisation $organisation, Customer $customer, int $serviceId, string $amount, int $daysAgo): void
    {
        $created = now()->subDays($daysAgo);
        $providerRequestId = (string) Str::uuid();
        $attempt1 = $created->clone()->addSeconds(2);
        $attempt2 = $created->clone()->addSeconds(10);
        $attempt3 = $created->clone()->addSeconds(30);

        $serviceRequest = $this->createServiceRequest($organisation, $customer, $serviceId, $amount, $created, [
            'status' => 'failed',
            'provider_attempts' => 3,
            'provider_request_id' => $providerRequestId,
            'last_attempted_at' => $attempt3,
        ]);

        $this->accepted($serviceRequest, $created);
        $this->event($serviceRequest, $created, 'queued', 'processing', 'system', 'Processing started (attempt 1).', ['provider_attempt' => 1]);
        $this->event($serviceRequest, $attempt1, 'processing', 'retrying', 'provider', 'Provider unavailable; retry scheduled.', [
            'provider_outcome' => 'unavailable', 'provider_attempt' => 1, 'retry_delay_seconds' => 5,
        ]);
        $this->event($serviceRequest, $attempt1->clone()->addSecond(), 'retrying', 'processing', 'system', 'Processing started (attempt 2).', ['provider_attempt' => 2]);
        $this->event($serviceRequest, $attempt2, 'processing', 'retrying', 'provider', 'Provider unavailable; retry scheduled.', [
            'provider_outcome' => 'unavailable', 'provider_attempt' => 2, 'retry_delay_seconds' => 15,
        ]);
        $this->event($serviceRequest, $attempt2->clone()->addSecond(), 'retrying', 'processing', 'system', 'Processing started (attempt 3).', ['provider_attempt' => 3]);
        $this->event($serviceRequest, $attempt3, 'processing', 'failed', 'system', 'Activation failed after maximum attempts.', [
            'provider_outcome' => 'unavailable', 'provider_attempt' => 3,
        ]);
    }

    private function uncertain(Organisation $organisation, Customer $customer, int $serviceId, string $amount, int $daysAgo): void
    {
        $created = now()->subDays($daysAgo);
        $providerRequestId = (string) Str::uuid();
        $attempt = $created->clone()->addSeconds(2);

        $serviceRequest = $this->createServiceRequest($organisation, $customer, $serviceId, $amount, $created, [
            'status' => 'uncertain',
            'provider_attempts' => 1,
            'provider_request_id' => $providerRequestId,
            'last_attempted_at' => $attempt,
        ]);

        $this->accepted($serviceRequest, $created);
        $this->event($serviceRequest, $created, 'queued', 'processing', 'system', 'Processing started (attempt 1).', ['provider_attempt' => 1]);
        $this->event($serviceRequest, $attempt, 'processing', 'uncertain', 'provider', 'Provider response timed out; outcome uncertain.', [
            'provider_outcome' => 'timeout',
            'provider_request_id' => $providerRequestId,
        ]);
    }

    private function queued(Organisation $organisation, Customer $customer, int $serviceId, string $amount, int $hoursAgo): void
    {
        $created = now()->subHours($hoursAgo);

        $serviceRequest = $this->createServiceRequest($organisation, $customer, $serviceId, $amount, $created, [
            'status' => 'queued',
        ]);

        $this->accepted($serviceRequest, $created);
    }

    private function processing(Organisation $organisation, Customer $customer, int $serviceId, string $amount, int $hoursAgo): void
    {
        $created = now()->subHours($hoursAgo);
        $attempt = $created->clone()->addSeconds(2);

        $serviceRequest = $this->createServiceRequest($organisation, $customer, $serviceId, $amount, $created, [
            'status' => 'processing',
            'provider_attempts' => 1,
            'provider_request_id' => (string) Str::uuid(),
            'last_attempted_at' => $attempt,
        ]);

        $this->accepted($serviceRequest, $created);
        $this->event($serviceRequest, $attempt, 'queued', 'processing', 'system', 'Processing started (attempt 1).', ['provider_attempt' => 1]);
    }

    private function retrying(Organisation $organisation, Customer $customer, int $serviceId, string $amount, int $hoursAgo): void
    {
        $created = now()->subHours($hoursAgo);
        $attempt = $created->clone()->addSeconds(2);

        $serviceRequest = $this->createServiceRequest($organisation, $customer, $serviceId, $amount, $created, [
            'status' => 'retrying',
            'provider_attempts' => 1,
            'provider_request_id' => (string) Str::uuid(),
            'last_attempted_at' => $attempt,
        ]);

        $this->accepted($serviceRequest, $created);
        $this->event($serviceRequest, $created, 'queued', 'processing', 'system', 'Processing started (attempt 1).', ['provider_attempt' => 1]);
        $this->event($serviceRequest, $attempt, 'processing', 'retrying', 'provider', 'Provider unavailable; retry scheduled.', [
            'provider_outcome' => 'unavailable', 'provider_attempt' => 1, 'retry_delay_seconds' => 5,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createServiceRequest(Organisation $organisation, Customer $customer, int $serviceId, string $amount, CarbonInterface $created, array $overrides): ServiceRequest
    {
        $serviceRequest = ServiceRequest::create(array_merge([
            'organisation_id' => $organisation->id,
            'customer_id' => $customer->id,
            'service_id' => $serviceId,
            'action' => 'activate',
            'requested_by' => 'customer',
            'amount' => $amount,
            'currency' => 'ZAR',
            'status' => 'queued',
            'display_reference' => $customer->customer_number.'-'.$created->timestamp,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ], $overrides));

        // create() stamps created_at/updated_at with "now"; backdate them
        // for a realistic-looking demo timeline without touching any
        // production write path (this seeder is the only caller).
        $serviceRequest->forceFill(['created_at' => $created, 'updated_at' => $created])->save();

        return $serviceRequest;
    }

    private function accepted(ServiceRequest $serviceRequest, CarbonInterface $at): void
    {
        $this->event($serviceRequest, $at, null, 'queued', 'portal', 'Service request accepted.', [
            'idempotency_key' => $serviceRequest->idempotency_key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(ServiceRequest $serviceRequest, CarbonInterface $at, ?string $from, string $to, string $actorType, string $message, array $metadata = []): void
    {
        $event = $serviceRequest->events()->create([
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'message' => $message,
            'metadata' => $metadata,
        ]);

        $event->forceFill(['created_at' => $at])->save();
    }
}
