<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPortalOrganisations;
use Tests\TestCase;

class CustomerShowTest extends TestCase
{
    use InteractsWithPortalOrganisations, RefreshDatabase;

    public function test_organisation_can_retrieve_its_own_customer(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/customers/10482')
            ->assertOk()
            ->assertJsonPath('data.customer_id', 10482)
            ->assertJsonPath('data.name', 'Amahle Dlamini');
    }

    public function test_other_organisation_cannot_retrieve_the_customer(): void
    {
        [$organisationA] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        [, $tokenB] = $this->organisationWithToken(27, 'Bluewave Services');

        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/v1/customers/10482')
            ->assertStatus(404);
    }
}
