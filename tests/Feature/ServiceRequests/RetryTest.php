<?php

namespace Tests\Feature\ServiceRequests;

use App\Jobs\ActivateServiceRequestJob;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organisation, 1: User}
     */
    private function organisationWithOperator(int $clientId, string $name): array
    {
        $organisation = Organisation::create(['client_id' => $clientId, 'name' => $name]);
        $operator = User::factory()->create(['organisation_id' => $organisation->id]);

        return [$organisation, $operator];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeServiceRequest(int $organisationId, int $customerId, array $overrides = []): ServiceRequest
    {
        return ServiceRequest::create(array_merge([
            'organisation_id' => $organisationId,
            'customer_id' => $customerId,
            'service_id' => 6222,
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

    private function retryUrl(ServiceRequest $serviceRequest): string
    {
        return route('service-requests.retry', $serviceRequest);
    }

    public function test_guest_cannot_retry(): void
    {
        [$organisation] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'failed', 'provider_attempts' => 3]);

        $this->post($this->retryUrl($serviceRequest))
            ->assertRedirect(route('login'));
    }

    public function test_operator_cannot_retry_another_organisations_request(): void
    {
        [$organisationA, $operatorA] = $this->organisationWithOperator(18, 'Northstar Telecom');

        [$organisationB] = $this->organisationWithOperator(27, 'Bluewave Services');
        $customerB = Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);
        $requestB = $this->makeServiceRequest($organisationB->id, $customerB->id, ['status' => 'failed', 'provider_attempts' => 3]);

        $this->actingAs($operatorA)
            ->post($this->retryUrl($requestB))
            ->assertNotFound();

        $this->assertSame('failed', $requestB->fresh()->status);
    }

    public function test_retrying_a_failed_request_requeues_it_and_resets_attempts(): void
    {
        Queue::fake();

        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id, [
            'status' => 'failed',
            'provider_attempts' => 3,
            'provider_request_id' => 'fixed-correlation-id',
        ]);

        $this->actingAs($operator)
            ->post($this->retryUrl($serviceRequest))
            ->assertRedirect();

        $serviceRequest->refresh();
        $this->assertSame('queued', $serviceRequest->status);
        $this->assertSame(0, $serviceRequest->provider_attempts);
        $this->assertSame('fixed-correlation-id', $serviceRequest->provider_request_id, 'provider_request_id must never be regenerated');

        $event = $serviceRequest->events()->latest('created_at')->first();
        $this->assertSame('operator', $event->actor_type);
        $this->assertSame($operator->id, $event->actor_id);
        $this->assertSame('failed', $event->from_status);
        $this->assertSame('queued', $event->to_status);

        Queue::assertPushed(ActivateServiceRequestJob::class, fn (ActivateServiceRequestJob $job) => $job->serviceRequestId === $serviceRequest->id);
    }

    public function test_retrying_an_uncertain_request_only_triggers_reconciliation(): void
    {
        Queue::fake();

        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id, [
            'service_id' => 6223,
            'status' => 'uncertain',
            'provider_attempts' => 1,
            'provider_request_id' => 'fixed-correlation-id',
        ]);

        $this->actingAs($operator)
            ->post($this->retryUrl($serviceRequest))
            ->assertRedirect();

        $serviceRequest->refresh();
        $this->assertSame('uncertain', $serviceRequest->status, 'status must not change purely from requesting reconciliation');
        $this->assertSame(1, $serviceRequest->provider_attempts, 'reconciliation must never count as another activation attempt');
        $this->assertSame('fixed-correlation-id', $serviceRequest->provider_request_id);

        $event = $serviceRequest->events()->latest('created_at')->first();
        $this->assertSame('operator', $event->actor_type);
        $this->assertSame('uncertain', $event->from_status);
        $this->assertSame('uncertain', $event->to_status);

        Queue::assertPushed(ActivateServiceRequestJob::class, 1);
    }

    public function test_completed_requests_cannot_be_retried(): void
    {
        Queue::fake();

        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id, [
            'status' => 'completed',
            'provider_attempts' => 1,
            'completed_at' => now(),
        ]);

        $this->actingAs($operator)
            ->post($this->retryUrl($serviceRequest))
            ->assertRedirect();

        $serviceRequest->refresh();
        $this->assertSame('completed', $serviceRequest->status);
        $this->assertSame(0, $serviceRequest->events()->count(), 'a rejected retry must not create a history event');

        Queue::assertNotPushed(ActivateServiceRequestJob::class);
    }

    public function test_processing_requests_cannot_be_retried(): void
    {
        Queue::fake();

        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'processing', 'provider_attempts' => 1]);

        $this->actingAs($operator)
            ->post($this->retryUrl($serviceRequest))
            ->assertRedirect();

        $this->assertSame('processing', $serviceRequest->fresh()->status);
        Queue::assertNotPushed(ActivateServiceRequestJob::class);
    }

    public function test_queued_requests_cannot_be_retried(): void
    {
        Queue::fake();

        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'queued']);

        $this->actingAs($operator)
            ->post($this->retryUrl($serviceRequest))
            ->assertRedirect();

        $this->assertSame('queued', $serviceRequest->fresh()->status);
        Queue::assertNotPushed(ActivateServiceRequestJob::class);
    }

    public function test_a_retry_already_in_progress_blocks_a_second_concurrent_request(): void
    {
        Queue::fake();

        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'failed', 'provider_attempts' => 3]);

        // Simulate a retry already in flight by holding the same lock the
        // controller uses, so this test can deterministically exercise the
        // "reject a concurrent duplicate" branch without real concurrency.
        $lock = Cache::lock("service-request-retry:{$serviceRequest->id}", 10);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($operator)
                ->post($this->retryUrl($serviceRequest))
                ->assertRedirect();
        } finally {
            $lock->release();
        }

        $serviceRequest->refresh();
        $this->assertSame('failed', $serviceRequest->status, 'a blocked retry must not mutate the request');
        $this->assertSame(0, $serviceRequest->events()->count());

        Queue::assertNotPushed(ActivateServiceRequestJob::class);
    }
}
