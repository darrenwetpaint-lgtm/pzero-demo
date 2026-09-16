<?php

namespace Tests\Feature\Seeders;

use App\Models\Organisation;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The demo seeder exists purely to give a fresh reviewer a realistic,
 * internally-consistent Service Requests list to look at. These tests guard
 * the properties that actually matter for that purpose: both organisations
 * are represented, every row's organisation/customer relationship is valid,
 * a representative spread of statuses exists, and — critically — seeding
 * never dispatches real queue work that could mutate a seeded row after the
 * fact.
 */
class ServiceRequestDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_requests_for_both_organisations(): void
    {
        $this->seed();

        $northstar = Organisation::where('client_id', 18)->firstOrFail();
        $bluewave = Organisation::where('client_id', 27)->firstOrFail();

        $this->assertGreaterThanOrEqual(2, ServiceRequest::where('organisation_id', $bluewave->id)->count());
        $this->assertGreaterThan(
            ServiceRequest::where('organisation_id', $bluewave->id)->count(),
            ServiceRequest::where('organisation_id', $northstar->id)->count(),
            'Northstar is documented to receive a heavier spread of demo data than Bluewave',
        );
    }

    public function test_seeded_total_is_within_the_documented_range(): void
    {
        $this->seed();

        $total = ServiceRequest::count();

        $this->assertGreaterThanOrEqual(12, $total);
        $this->assertLessThanOrEqual(20, $total);
    }

    public function test_every_seeded_request_belongs_to_a_customer_in_the_same_organisation(): void
    {
        $this->seed();

        ServiceRequest::with('customer')->get()->each(function (ServiceRequest $serviceRequest) {
            $this->assertNotNull($serviceRequest->customer, "Service request {$serviceRequest->id} has no customer");
            $this->assertSame(
                $serviceRequest->organisation_id,
                $serviceRequest->customer->organisation_id,
                "Service request {$serviceRequest->id} and its customer belong to different organisations",
            );
        });
    }

    public function test_all_six_statuses_are_represented_in_the_seeded_data(): void
    {
        $this->seed();

        $statuses = ServiceRequest::query()->distinct()->pluck('status')->sort()->values()->all();

        $this->assertSame(
            ['completed', 'failed', 'processing', 'queued', 'retrying', 'uncertain'],
            $statuses,
        );
    }

    public function test_northstar_has_multiple_completed_requests(): void
    {
        $this->seed();

        $northstar = Organisation::where('client_id', 18)->firstOrFail();

        $this->assertGreaterThanOrEqual(
            2,
            ServiceRequest::where('organisation_id', $northstar->id)->where('status', 'completed')->count(),
        );
    }

    /**
     * Alice (Northstar) needs at least one request in each of these three
     * statuses to exercise every Retry rule from the operator UI: Retry
     * restarts cleanly from failed, Retry only reconciles (never
     * reactivates) from uncertain, and Retry is absent once completed.
     */
    public function test_northstar_has_at_least_one_failed_uncertain_and_completed_request(): void
    {
        $this->seed();

        $northstar = Organisation::where('client_id', 18)->firstOrFail();

        foreach (['failed', 'uncertain', 'completed'] as $status) {
            $this->assertGreaterThanOrEqual(
                1,
                ServiceRequest::where('organisation_id', $northstar->id)->where('status', $status)->count(),
                "Northstar has no {$status} demo request",
            );
        }
    }

    /**
     * Non-terminal statuses (queued/processing/retrying) also need to be
     * visible for Northstar, to confirm Retry is correctly absent there too.
     */
    public function test_northstar_has_queued_processing_and_retrying_requests(): void
    {
        $this->seed();

        $northstar = Organisation::where('client_id', 18)->firstOrFail();

        foreach (['queued', 'processing', 'retrying'] as $status) {
            $this->assertGreaterThanOrEqual(
                1,
                ServiceRequest::where('organisation_id', $northstar->id)->where('status', $status)->count(),
                "Northstar has no {$status} demo request",
            );
        }
    }

    public function test_completed_requests_have_consistent_provider_state(): void
    {
        $this->seed();

        ServiceRequest::where('status', 'completed')->get()->each(function (ServiceRequest $serviceRequest) {
            $this->assertNotNull($serviceRequest->completed_at);
            $this->assertNotNull($serviceRequest->provider_request_id);
            $this->assertGreaterThanOrEqual(1, $serviceRequest->provider_attempts);
        });
    }

    public function test_failed_requests_have_exhausted_all_three_attempts(): void
    {
        $this->seed();

        ServiceRequest::where('status', 'failed')->get()->each(function (ServiceRequest $serviceRequest) {
            $this->assertSame(3, $serviceRequest->provider_attempts);
            $this->assertNull($serviceRequest->completed_at);
        });
    }

    /**
     * Seeding must never insert a row into the jobs table: it uses direct
     * Eloquent writes only, never SubmitServiceRequest or
     * ActivateServiceRequestJob::dispatch(). This is what guarantees a
     * running queue worker has nothing to pick up for seeded
     * queued/processing/retrying rows, so they stay exactly as seeded.
     */
    public function test_seeding_never_dispatches_any_queued_jobs(): void
    {
        $this->seed();

        $this->assertSame(0, DB::table('jobs')->count());
    }
}
