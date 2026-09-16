<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ActivateServiceRequestJob;
use App\Models\Customer;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithPortalOrganisations;
use Tests\TestCase;

class ServiceRequestSubmissionTest extends TestCase
{
    use InteractsWithPortalOrganisations, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => 18,
            'customer_id' => 10482,
            'service_id' => 6221,
            'action' => 'activate',
            'requested_by' => 'customer',
            'amount' => 799.95,
            'currency' => 'ZAR',
            // A portal-supplied status is part of the sample contract but
            // must never determine the locally stored status.
            'status' => 'completed',
        ], $overrides);
    }

    public function test_missing_token_returns_unauthorized(): void
    {
        $this->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(401);
    }

    public function test_invalid_token_returns_unauthorized(): void
    {
        $this->withHeaders($this->bearer('not-a-real-token') + ['Idempotency-Key' => 'key-1'])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(401);
    }

    public function test_valid_submission_is_queued(): void
    {
        // The activation job is dispatched on acceptance; fake the queue so
        // this test observes the request in its as-accepted "queued" state
        // rather than whatever the (synchronously-run, in the test env)
        // background job transitions it to.
        Queue::fake();

        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        $customer = Customer::create([
            'organisation_id' => $organisation->id,
            'customer_number' => 10482,
            'name' => 'Amahle Dlamini',
        ]);

        $response = $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-1'])
            ->postJson('/api/v1/service-requests', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.customer.customer_id', 10482)
            ->assertJsonPath('data.client_id', 18);

        $serviceRequest = ServiceRequest::sole();

        $this->assertSame($organisation->id, $serviceRequest->organisation_id);
        $this->assertSame($customer->id, $serviceRequest->customer_id);
        $this->assertSame('queued', $serviceRequest->status);
        $this->assertStringStartsWith('10482-', $serviceRequest->display_reference);
        $this->assertSame(1, ServiceRequestEvent::where('service_request_id', $serviceRequest->id)->count());
    }

    public function test_repeated_idempotency_key_with_same_payload_replays(): void
    {
        // Fake the queue so the dispatched activation job doesn't run inline
        // (test env uses the sync connection) and change the event count
        // this test is actually about: acceptance vs. replay, not processing.
        Queue::fake();

        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $payload = $this->payload();

        $first = $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-replay'])
            ->postJson('/api/v1/service-requests', $payload);
        $first->assertStatus(201);

        $second = $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-replay'])
            ->postJson('/api/v1/service-requests', $payload);
        $second->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ServiceRequest::count());
        $this->assertSame(1, ServiceRequestEvent::count());
    }

    public function test_same_payload_with_different_key_creates_distinct_request(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $payload = $this->payload();

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-a'])
            ->postJson('/api/v1/service-requests', $payload)
            ->assertStatus(201);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-b'])
            ->postJson('/api/v1/service-requests', $payload)
            ->assertStatus(201);

        $this->assertSame(2, ServiceRequest::count());
    }

    public function test_reused_key_with_changed_payload_returns_conflict(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-conflict'])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(201);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-conflict'])
            ->postJson('/api/v1/service-requests', $this->payload(['amount' => 1.00]))
            ->assertStatus(409);

        $serviceRequest = ServiceRequest::sole();
        $this->assertSame('799.95', $serviceRequest->amount);
    }

    public function test_customer_from_another_organisation_returns_not_found(): void
    {
        [$organisationA, $tokenA] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        [$organisationB] = $this->organisationWithToken(27, 'Bluewave Services');
        Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);

        $this->withHeaders($this->bearer($tokenA) + ['Idempotency-Key' => 'key-cross-org'])
            ->postJson('/api/v1/service-requests', $this->payload(['customer_id' => 20482]))
            ->assertStatus(404);

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_mismatched_client_id_returns_forbidden(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-bad-client'])
            ->postJson('/api/v1/service-requests', $this->payload(['client_id' => 999]))
            ->assertStatus(403);

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(422);
    }

    public function test_oversized_idempotency_key_is_rejected_by_validation_not_the_database(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        // idempotency_key is varchar(255); one character over that must be
        // caught by validation (422) rather than reaching the database.
        $oversizedKey = str_repeat('a', 256);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => $oversizedKey])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_new_submission_dispatches_one_activation_job(): void
    {
        Queue::fake();

        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-dispatch'])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(201);

        $serviceRequest = ServiceRequest::sole();

        Queue::assertPushed(ActivateServiceRequestJob::class, 1);
        Queue::assertPushed(fn (ActivateServiceRequestJob $job) => $job->serviceRequestId === $serviceRequest->id);
    }

    public function test_idempotent_replay_does_not_dispatch_a_second_job(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $payload = $this->payload();

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-replay-dispatch'])
            ->postJson('/api/v1/service-requests', $payload)
            ->assertStatus(201);

        Queue::fake();

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-replay-dispatch'])
            ->postJson('/api/v1/service-requests', $payload)
            ->assertStatus(200);

        Queue::assertNotPushed(ActivateServiceRequestJob::class);
    }

    public function test_idempotency_conflict_does_not_dispatch_a_job(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-conflict-dispatch'])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(201);

        Queue::fake();

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'key-conflict-dispatch'])
            ->postJson('/api/v1/service-requests', $this->payload(['amount' => 1.00]))
            ->assertStatus(409);

        Queue::assertNotPushed(ActivateServiceRequestJob::class);
    }

    public function test_idempotency_key_at_the_column_limit_is_accepted(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $maxLengthKey = str_repeat('a', 255);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => $maxLengthKey])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(201);

        $this->assertSame(1, ServiceRequest::count());
    }
}
