<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebInterfaceTest extends TestCase
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

    public function test_guest_cannot_access_service_requests_index(): void
    {
        $this->get(route('service-requests.index'))
            ->assertRedirect(route('login'));
    }

    /**
     * Email verification is intentionally not part of this demo (see
     * DECISIONS.md): operator routes require only `auth`, not `verified`.
     * An authenticated-but-unverified user must still be able to reach them.
     */
    public function test_authenticated_unverified_operator_can_access_service_requests(): void
    {
        $organisation = Organisation::create(['client_id' => 18, 'name' => 'Northstar Telecom']);
        $operator = User::factory()->unverified()->create(['organisation_id' => $organisation->id]);

        $this->actingAs($operator)
            ->get(route('service-requests.index'))
            ->assertOk();

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_operator_a_index_sees_only_organisation_a_requests(): void
    {
        [$organisationA, $operatorA] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customerA = Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $requestA = $this->makeServiceRequest($organisationA->id, $customerA->id);

        [$organisationB] = $this->organisationWithOperator(27, 'Bluewave Services');
        $customerB = Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);
        $this->makeServiceRequest($organisationB->id, $customerB->id);

        $this->actingAs($operatorA)
            ->get(route('service-requests.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->has('serviceRequests.data', 1)
                ->where('serviceRequests.data.0.id', $requestA->id)
            );
    }

    public function test_operator_b_index_sees_only_organisation_b_requests(): void
    {
        [$organisationA] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customerA = Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $this->makeServiceRequest($organisationA->id, $customerA->id);

        [$organisationB, $operatorB] = $this->organisationWithOperator(27, 'Bluewave Services');
        $customerB = Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);
        $requestB = $this->makeServiceRequest($organisationB->id, $customerB->id);

        $this->actingAs($operatorB)
            ->get(route('service-requests.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->has('serviceRequests.data', 1)
                ->where('serviceRequests.data.0.id', $requestB->id)
            );
    }

    public function test_status_filter_only_returns_matching_requests_within_the_operators_organisation(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'queued']);
        $completed = $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'completed']);

        $this->actingAs($operator)
            ->get(route('service-requests.index', ['status' => 'completed']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->has('serviceRequests.data', 1)
                ->where('serviceRequests.data.0.id', $completed->id)
                ->where('serviceRequests.data.0.status', 'completed')
            );
    }

    public function test_operator_can_view_their_organisations_request_detail_with_history(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $serviceRequest = $this->makeServiceRequest($organisation->id, $customer->id);
        $serviceRequest->events()->create([
            'from_status' => null,
            'to_status' => 'queued',
            'actor_type' => 'portal',
            'message' => 'Service request accepted.',
        ]);

        $this->actingAs($operator)
            ->get(route('service-requests.show', $serviceRequest))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Show')
                ->where('serviceRequest.id', $serviceRequest->id)
                ->has('history', 1)
                ->where('history.0.message', 'Service request accepted.')
            );
    }

    public function test_operator_requesting_another_organisations_request_gets_not_found(): void
    {
        [, $operatorA] = $this->organisationWithOperator(18, 'Northstar Telecom');

        [$organisationB] = $this->organisationWithOperator(27, 'Bluewave Services');
        $customerB = Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);
        $requestB = $this->makeServiceRequest($organisationB->id, $customerB->id);

        $this->actingAs($operatorA)
            ->get(route('service-requests.show', $requestB))
            ->assertNotFound();
    }

    public function test_sorting_by_amount_ascending_orders_results_by_amount(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $high = $this->makeServiceRequest($organisation->id, $customer->id, ['amount' => '900.00']);
        $low = $this->makeServiceRequest($organisation->id, $customer->id, ['amount' => '100.00']);
        $mid = $this->makeServiceRequest($organisation->id, $customer->id, ['amount' => '500.00']);

        $this->actingAs($operator)
            ->get(route('service-requests.index', ['sort' => 'amount', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->where('filters.sort', 'amount')
                ->where('filters.direction', 'asc')
                ->where('serviceRequests.data.0.id', $low->id)
                ->where('serviceRequests.data.1.id', $mid->id)
                ->where('serviceRequests.data.2.id', $high->id)
            );
    }

    public function test_sorting_by_amount_descending_orders_results_by_amount(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $high = $this->makeServiceRequest($organisation->id, $customer->id, ['amount' => '900.00']);
        $low = $this->makeServiceRequest($organisation->id, $customer->id, ['amount' => '100.00']);

        $this->actingAs($operator)
            ->get(route('service-requests.index', ['sort' => 'amount', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->where('serviceRequests.data.0.id', $high->id)
                ->where('serviceRequests.data.1.id', $low->id)
            );
    }

    public function test_sorting_by_customer_orders_by_customer_name(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $zed = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10999, 'name' => 'Zed Traders']);
        $amahle = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $requestZed = $this->makeServiceRequest($organisation->id, $zed->id);
        $requestAmahle = $this->makeServiceRequest($organisation->id, $amahle->id);

        $this->actingAs($operator)
            ->get(route('service-requests.index', ['sort' => 'customer', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->where('serviceRequests.data.0.id', $requestAmahle->id)
                ->where('serviceRequests.data.1.id', $requestZed->id)
            );
    }

    /**
     * An unsupported/malicious "sort" value must never reach the SQL query —
     * it should be silently ignored in favour of the default sort rather
     * than erroring or being interpolated anywhere.
     */
    public function test_an_unsupported_sort_field_is_ignored_in_favour_of_the_default(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $this->makeServiceRequest($organisation->id, $customer->id);

        $this->actingAs($operator)
            ->get(route('service-requests.index', ['sort' => 'id; DROP TABLE service_requests; --']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->where('filters.sort', 'created')
            );

        $this->assertDatabaseHas('service_requests', ['organisation_id' => $organisation->id]);
    }

    public function test_status_filter_is_preserved_when_sorting(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customer = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'queued', 'amount' => '50.00']);
        $completedHigh = $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'completed', 'amount' => '900.00']);
        $completedLow = $this->makeServiceRequest($organisation->id, $customer->id, ['status' => 'completed', 'amount' => '100.00']);

        $this->actingAs($operator)
            ->get(route('service-requests.index', ['status' => 'completed', 'sort' => 'amount', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->has('serviceRequests.data', 2)
                ->where('filters.status', 'completed')
                ->where('serviceRequests.data.0.id', $completedLow->id)
                ->where('serviceRequests.data.1.id', $completedHigh->id)
            );
    }

    public function test_customer_filter_only_returns_that_customers_requests_within_the_operators_organisation(): void
    {
        [$organisation, $operator] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $amahle = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $other = Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10483, 'name' => 'General Trading CC']);

        $requestAmahle = $this->makeServiceRequest($organisation->id, $amahle->id);
        $this->makeServiceRequest($organisation->id, $other->id);

        $this->actingAs($operator)
            ->get(route('service-requests.index', ['customer' => 10482]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->has('serviceRequests.data', 1)
                ->where('serviceRequests.data.0.id', $requestAmahle->id)
                ->where('filters.customer', 10482)
            );
    }

    /**
     * A customer_number is only meaningful within its own organisation — an
     * operator filtering by another organisation's customer number (even one
     * that collides numerically) must see no results, never that other
     * organisation's data.
     */
    public function test_customer_filter_cannot_reach_another_organisations_customer(): void
    {
        [$organisationA, $operatorA] = $this->organisationWithOperator(18, 'Northstar Telecom');
        $customerA = Customer::create(['organisation_id' => $organisationA->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $this->makeServiceRequest($organisationA->id, $customerA->id);

        [$organisationB] = $this->organisationWithOperator(27, 'Bluewave Services');
        $customerB = Customer::create(['organisation_id' => $organisationB->id, 'customer_number' => 20482, 'name' => 'Sipho Nkosi']);
        $this->makeServiceRequest($organisationB->id, $customerB->id);

        $this->actingAs($operatorA)
            ->get(route('service-requests.index', ['customer' => 20482]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceRequests/Index')
                ->has('serviceRequests.data', 0)
            );
    }
}
