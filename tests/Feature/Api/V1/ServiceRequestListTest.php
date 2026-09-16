<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPortalOrganisations;
use Tests\TestCase;

class ServiceRequestListTest extends TestCase
{
    use InteractsWithPortalOrganisations, RefreshDatabase;

    public function test_only_the_authenticated_organisations_records_are_returned(): void
    {
        [$organisationA, $tokenA] = $this->organisationWithToken(18, 'Northstar Telecom');
        $customerA = Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $this->makeServiceRequest($organisationA->id, $customerA->id);

        [$organisationB] = $this->organisationWithToken(27, 'Bluewave Services');
        $customerB = Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);
        $this->makeServiceRequest($organisationB->id, $customerB->id);

        $response = $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/v1/service-requests');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($customerA->customer_number, $response->json('data.0.customer.customer_id'));
    }

    public function test_response_is_paginated(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $this->makeServiceRequest($organisation->id, $customer->id);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/service-requests');

        $response->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_status_filter_narrows_results(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'queued']);
        $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'completed']);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/service-requests?status=completed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('completed', $response->json('data.0.status'));
    }
}
