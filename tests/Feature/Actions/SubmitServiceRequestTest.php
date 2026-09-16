<?php

namespace Tests\Feature\Actions;

use App\Actions\ServiceRequests\SubmitServiceRequest;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\ServiceRequest;
use App\Services\Activation\ActivationOutcome;
use App\Services\Activation\ActivationProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SubmitServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The catch block only converts a QueryException into replay/conflict
     * behaviour when a matching (organisation_id, idempotency_key) row can
     * actually be re-fetched afterwards. This proves an integrity violation
     * unrelated to that unique constraint (here: a broken customer_id
     * foreign key, simulating the customer being removed concurrently) is
     * re-thrown as-is, rather than being misinterpreted as a replay.
     */
    public function test_non_idempotency_integrity_violation_is_rethrown(): void
    {
        $organisation = Organisation::create(['client_id' => 18, 'name' => 'Northstar Telecom']);
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $data = [
            'idempotency_key' => 'race-key',
            'client_id' => 18,
            'customer_id' => 10482,
            'service_id' => 6221,
            'action' => 'activate',
            'requested_by' => 'customer',
            'amount' => 100.00,
            'currency' => 'ZAR',
        ];

        $customer->delete();

        try {
            app(SubmitServiceRequest::class)->handle($organisation, $customer, $data);
            $this->fail('Expected a QueryException to be thrown.');
        } catch (QueryException) {
            // Expected: the foreign key violation propagates unchanged.
        }

        $this->assertSame(0, ServiceRequest::count());
    }

    /**
     * The action itself only wraps the ServiceRequest creation in its own
     * inner transaction, but ->afterCommit() must guarantee the job never
     * runs before everything is durably committed — even if some future
     * caller wraps the whole action in its own outer transaction, as
     * simulated here. Queue::fake() can't prove this (it bypasses the real
     * connection's after-commit hook entirely), so this uses the real
     * "sync" queue connection (the test environment's default) and a
     * provider spy to observe exactly when activate() actually runs.
     */
    public function test_job_dispatch_is_deferred_until_the_outermost_transaction_commits(): void
    {
        $organisation = Organisation::create(['client_id' => 18, 'name' => 'Northstar Telecom']);
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $data = [
            'idempotency_key' => 'outer-tx-key',
            'client_id' => 18,
            'customer_id' => 10482,
            'service_id' => 6221,
            'action' => 'activate',
            'requested_by' => 'customer',
            'amount' => 100.00,
            'currency' => 'ZAR',
        ];

        $activateCallCount = 0;

        $provider = Mockery::mock(ActivationProvider::class);
        $provider->shouldReceive('activate')
            ->once()
            ->andReturnUsing(function () use (&$activateCallCount) {
                $activateCallCount++;

                return ActivationOutcome::Success;
            });
        $this->app->instance(ActivationProvider::class, $provider);

        DB::transaction(function () use ($organisation, $customer, $data, &$activateCallCount) {
            app(SubmitServiceRequest::class)->handle($organisation, $customer, $data);

            // Still inside the outer transaction: the (sync-queue) job must
            // not have run yet, so activate() must not have been called.
            $this->assertSame(0, $activateCallCount);
        });

        // The outer transaction has now committed, so the deferred
        // dispatch — and therefore activate() — should have run exactly once.
        $this->assertSame(1, $activateCallCount);
    }
}
