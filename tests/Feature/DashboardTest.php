<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeServiceRequest(int $organisationId, int $customerId, array $overrides = []): ServiceRequest
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

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_summary_counts_are_scoped_to_the_operators_own_organisation(): void
    {
        $organisationA = Organisation::create(['client_id' => 18, 'name' => 'Northstar Telecom']);
        $operatorA = User::factory()->create(['organisation_id' => $organisationA->id]);
        $customerA = Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->makeServiceRequest($organisationA->id, $customerA->id, ['status' => 'completed']);
        $this->makeServiceRequest($organisationA->id, $customerA->id, ['status' => 'queued']);
        $this->makeServiceRequest($organisationA->id, $customerA->id, ['status' => 'processing']);
        $this->makeServiceRequest($organisationA->id, $customerA->id, ['status' => 'retrying']);
        $this->makeServiceRequest($organisationA->id, $customerA->id, ['status' => 'failed']);
        $this->makeServiceRequest($organisationA->id, $customerA->id, ['status' => 'uncertain']);

        $organisationB = Organisation::create(['client_id' => 27, 'name' => 'Bluewave Services']);
        $operatorB = User::factory()->create(['organisation_id' => $organisationB->id]);
        $customerB = Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);

        $this->makeServiceRequest($organisationB->id, $customerB->id, ['status' => 'completed']);

        $this->actingAs($operatorA)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('summary.total', 6)
                ->where('summary.completed', 1)
                ->where('summary.inProgress', 3)
                ->where('summary.needsAttention', 2)
            );

        $this->actingAs($operatorB)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('summary.total', 1)
                ->where('summary.completed', 1)
                ->where('summary.inProgress', 0)
                ->where('summary.needsAttention', 0)
            );
    }
}
