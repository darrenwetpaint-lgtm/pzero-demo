<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ActivateServiceRequestJob;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\ServiceRequest;
use App\Services\Activation\ActivationOutcome;
use App\Services\Activation\ActivationProvider;
use App\Services\Activation\LookupOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\InteractsWithPortalOrganisations;
use Tests\TestCase;

class ActivateServiceRequestJobTest extends TestCase
{
    use InteractsWithPortalOrganisations, RefreshDatabase;

    private Organisation $organisation;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organisation] = $this->organisationWithToken(18, 'Northstar Telecom');
        $this->customer = Customer::create([
            'organisation_id' => $this->organisation->id,
            'customer_number' => 10482,
            'name' => 'Amahle Dlamini',
        ]);
    }

    private function queuedRequest(int $serviceId): ServiceRequest
    {
        return $this->makeServiceRequest($this->organisation->id, $this->customer->id, [
            'service_id' => $serviceId,
            'status' => 'queued',
            'provider_attempts' => 0,
            'provider_request_id' => null,
        ]);
    }

    private function process(ServiceRequest $serviceRequest): void
    {
        app()->call([new ActivateServiceRequestJob($serviceRequest->id), 'handle']);
    }

    private function interruptedProcessingRequest(int $serviceId): ServiceRequest
    {
        return $this->makeServiceRequest($this->organisation->id, $this->customer->id, [
            'service_id' => $serviceId,
            'status' => 'processing',
            'provider_attempts' => 1,
            'provider_request_id' => (string) Str::uuid(),
        ]);
    }

    public function test_successful_activation_completes_on_the_first_attempt(): void
    {
        $serviceRequest = $this->queuedRequest(6221);

        $this->process($serviceRequest);
        $serviceRequest->refresh();

        $this->assertSame('completed', $serviceRequest->status);
        $this->assertSame(1, $serviceRequest->provider_attempts);
        $this->assertNotNull($serviceRequest->completed_at);
        $this->assertNotNull($serviceRequest->provider_request_id);

        $messages = $serviceRequest->events()->orderBy('created_at')->pluck('message');
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'Processing started')));
        $this->assertTrue($messages->contains('Activation confirmed.'));
    }

    public function test_unavailable_provider_retries_twice_then_fails(): void
    {
        $serviceRequest = $this->queuedRequest(6222);

        $this->process($serviceRequest);
        $serviceRequest->refresh();
        $this->assertSame('retrying', $serviceRequest->status);
        $this->assertSame(1, $serviceRequest->provider_attempts);
        $providerRequestId = $serviceRequest->provider_request_id;
        $this->assertNotNull($providerRequestId);

        $this->process($serviceRequest);
        $serviceRequest->refresh();
        $this->assertSame('retrying', $serviceRequest->status);
        $this->assertSame(2, $serviceRequest->provider_attempts);
        // The correlation id must never change across retries.
        $this->assertSame($providerRequestId, $serviceRequest->provider_request_id);

        $this->process($serviceRequest);
        $serviceRequest->refresh();
        $this->assertSame('failed', $serviceRequest->status);
        $this->assertSame(3, $serviceRequest->provider_attempts);

        $messages = $serviceRequest->events()->orderBy('created_at')->pluck('message');
        $this->assertSame(2, $messages->filter(fn ($m) => $m === 'Provider unavailable; retry scheduled.')->count());
        $this->assertTrue($messages->contains('Activation failed after maximum attempts.'));
    }

    public function test_timeout_marks_uncertain_and_lookup_reconciles_to_completed_without_a_second_activation_attempt(): void
    {
        $serviceRequest = $this->queuedRequest(6223);

        $provider = Mockery::mock(ActivationProvider::class);
        $provider->shouldReceive('activate')->once()->andReturn(ActivationOutcome::Timeout);
        $provider->shouldReceive('lookup')->once()->andReturn(LookupOutcome::Active);
        $this->app->instance(ActivationProvider::class, $provider);

        // First execution: activate() times out.
        $this->process($serviceRequest);
        $serviceRequest->refresh();
        $this->assertSame('uncertain', $serviceRequest->status);
        $this->assertSame(1, $serviceRequest->provider_attempts);

        // Second execution (the automatic reconciliation): lookup() only.
        $this->process($serviceRequest);
        $serviceRequest->refresh();
        $this->assertSame('completed', $serviceRequest->status);
        $this->assertSame(1, $serviceRequest->provider_attempts, 'lookup() must not count as another activation attempt');
        $this->assertNotNull($serviceRequest->completed_at);
    }

    public function test_concurrent_delivery_cannot_cause_a_second_activate_call(): void
    {
        $serviceRequest = $this->queuedRequest(6221);
        $activateCalls = 0;

        $provider = Mockery::mock(ActivationProvider::class);
        $provider->shouldReceive('activate')
            // The critical assertion: activate() must be called exactly
            // once even though a "second worker" tries mid-flight below.
            ->once()
            ->andReturnUsing(function () use ($serviceRequest, &$activateCalls) {
                $activateCalls++;

                // Simulate a second worker picking up the same job while
                // the first is still inside this very activate() call: by
                // this point claimForActivation() has already committed
                // status=processing, so the "second worker" must see the
                // request already claimed and must not call activate()
                // again — it now goes through the crash-recovery path
                // instead (lookup() only), same as a genuinely interrupted
                // delivery would.
                app()->call([new ActivateServiceRequestJob($serviceRequest->id), 'handle']);

                return ActivationOutcome::Success;
            });
        $provider->shouldReceive('lookup')->once()->andReturn(LookupOutcome::Inconclusive);
        $this->app->instance(ActivationProvider::class, $provider);

        $this->process($serviceRequest);

        $this->assertSame(1, $activateCalls);
        $serviceRequest->refresh();
        $this->assertSame('completed', $serviceRequest->status);
        $this->assertSame(1, $serviceRequest->provider_attempts);
    }

    public function test_redelivered_processing_request_reconciles_via_lookup_to_completed(): void
    {
        $serviceRequest = $this->interruptedProcessingRequest(6223);
        $providerRequestId = $serviceRequest->provider_request_id;

        $provider = Mockery::mock(ActivationProvider::class);
        // The whole point: a redelivered "processing" job must never call
        // activate() again, since we don't know if the crashed worker's
        // call already reached the provider.
        $provider->shouldNotReceive('activate');
        $provider->shouldReceive('lookup')->once()->andReturn(LookupOutcome::Active);
        $this->app->instance(ActivationProvider::class, $provider);

        $this->process($serviceRequest);
        $serviceRequest->refresh();

        $this->assertSame('completed', $serviceRequest->status);
        $this->assertNotNull($serviceRequest->completed_at);
        $this->assertSame(1, $serviceRequest->provider_attempts, 'recovery lookup must not count as another activation attempt');
        $this->assertSame($providerRequestId, $serviceRequest->provider_request_id, 'recovery must reuse the already-committed correlation id');

        $messages = $serviceRequest->events()->orderBy('created_at')->pluck('message');
        $this->assertTrue($messages->contains('Previous processing outcome requires reconciliation.'));
        $this->assertTrue($messages->contains('Provider lookup confirmed activation.'));
    }

    public function test_redelivered_processing_request_with_inconclusive_lookup_becomes_uncertain(): void
    {
        $serviceRequest = $this->interruptedProcessingRequest(6221);

        $provider = Mockery::mock(ActivationProvider::class);
        $provider->shouldNotReceive('activate');
        $provider->shouldReceive('lookup')->once()->andReturn(LookupOutcome::Inconclusive);
        $this->app->instance(ActivationProvider::class, $provider);

        $this->process($serviceRequest);
        $serviceRequest->refresh();

        $this->assertSame('uncertain', $serviceRequest->status);
        $this->assertNull($serviceRequest->completed_at);
        $this->assertSame(1, $serviceRequest->provider_attempts);
    }

    public function test_terminal_completed_request_ignores_repeated_delivery(): void
    {
        $serviceRequest = $this->queuedRequest(6221);
        $serviceRequest->update(['status' => 'completed', 'completed_at' => now()]);

        $provider = Mockery::mock(ActivationProvider::class);
        $provider->shouldNotReceive('activate');
        $provider->shouldNotReceive('lookup');
        $this->app->instance(ActivationProvider::class, $provider);

        $this->process($serviceRequest);

        $this->assertSame('completed', $serviceRequest->fresh()->status);
    }

    public function test_terminal_failed_request_ignores_repeated_delivery(): void
    {
        $serviceRequest = $this->queuedRequest(6222);
        $serviceRequest->update(['status' => 'failed']);

        $provider = Mockery::mock(ActivationProvider::class);
        $provider->shouldNotReceive('activate');
        $provider->shouldNotReceive('lookup');
        $this->app->instance(ActivationProvider::class, $provider);

        $this->process($serviceRequest);

        $this->assertSame('failed', $serviceRequest->fresh()->status);
    }

    public function test_inconclusive_lookup_leaves_the_request_uncertain_without_repeating_activation(): void
    {
        $serviceRequest = $this->queuedRequest(6223);
        $serviceRequest->update([
            'status' => 'uncertain',
            'provider_attempts' => 1,
            'provider_request_id' => (string) Str::uuid(),
        ]);

        $provider = Mockery::mock(ActivationProvider::class);
        $provider->shouldNotReceive('activate');
        $provider->shouldReceive('lookup')->once()->andReturn(LookupOutcome::Inconclusive);
        $this->app->instance(ActivationProvider::class, $provider);

        $this->process($serviceRequest);
        $serviceRequest->refresh();

        $this->assertSame('uncertain', $serviceRequest->status);
        $this->assertSame(1, $serviceRequest->provider_attempts);

        $lastEvent = $serviceRequest->events()->latest('created_at')->first();
        $this->assertStringContainsString('could not confirm', $lastEvent->message);
    }
}
