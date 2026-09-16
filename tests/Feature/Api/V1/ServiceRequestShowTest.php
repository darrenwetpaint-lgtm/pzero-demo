<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPortalOrganisations;
use Tests\TestCase;

class ServiceRequestShowTest extends TestCase
{
    use InteractsWithPortalOrganisations, RefreshDatabase;

    public function test_owner_organisation_can_view_the_request_with_history(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id);

        $serviceRequest->events()->create([
            'from_status' => null,
            'to_status' => 'queued',
            'actor_type' => 'portal',
            'message' => 'Service request accepted.',
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson("/api/v1/service-requests/{$serviceRequest->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $serviceRequest->id)
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.to_status', 'queued');
    }

    public function test_other_organisation_receives_not_found(): void
    {
        [$organisationA] = $this->organisationWithToken(18, 'Northstar Telecom');
        $customerA = Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisationA->id, $customerA->id);

        [, $tokenB] = $this->organisationWithToken(27, 'Bluewave Services');

        $this->withHeaders($this->bearer($tokenB))
            ->getJson("/api/v1/service-requests/{$serviceRequest->id}")
            ->assertStatus(404);
    }
}
