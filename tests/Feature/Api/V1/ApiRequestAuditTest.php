<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ActivateServiceRequestJob;
use App\Models\ApiRequestAudit;
use App\Models\Customer;
use App\Models\PortalCredential;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithPortalOrganisations;
use Tests\TestCase;

class ApiRequestAuditTest extends TestCase
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
        ], $overrides);
    }

    public function test_valid_submission_creates_no_audit_row(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-1'])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(201);

        $this->assertSame(0, ApiRequestAudit::count());
    }

    public function test_idempotent_replay_creates_no_audit_row(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);
        $payload = $this->payload();

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-replay'])
            ->postJson('/api/v1/service-requests', $payload)
            ->assertStatus(201);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-replay'])
            ->postJson('/api/v1/service-requests', $payload)
            ->assertStatus(200);

        $this->assertSame(0, ApiRequestAudit::count());
    }

    public function test_missing_token_is_audited_without_an_organisation(): void
    {
        $this->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(401);

        $audit = ApiRequestAudit::sole();
        $this->assertNull($audit->organisation_id);
        $this->assertSame(401, $audit->status_code);
        $this->assertSame('missing_token', $audit->reason);
        $this->assertSame('POST', $audit->method);
        $this->assertNotNull($audit->ip_hash);
    }

    public function test_invalid_token_is_audited_without_an_organisation(): void
    {
        $this->withHeaders($this->bearer('not-a-real-token'))
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(401);

        $audit = ApiRequestAudit::sole();
        $this->assertNull($audit->organisation_id);
        $this->assertSame('invalid_token', $audit->reason);
    }

    public function test_revoked_token_is_audited_with_its_organisation(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        PortalCredential::where('organisation_id', $organisation->id)->update(['revoked_at' => now()]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(401);

        $audit = ApiRequestAudit::sole();
        $this->assertSame($organisation->id, $audit->organisation_id);
        $this->assertSame('revoked_token', $audit->reason);
    }

    public function test_validation_failure_is_audited_with_organisation_and_safe_field_names_only(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        // No Idempotency-Key header, and an unsupported action -> two failing fields.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/service-requests', $this->payload(['action' => 'deactivate']))
            ->assertStatus(422);

        $audit = ApiRequestAudit::sole();
        $this->assertSame($organisation->id, $audit->organisation_id);
        $this->assertSame(422, $audit->status_code);
        $this->assertSame('validation_failed', $audit->reason);
        $this->assertIsArray($audit->context['failed_fields']);
        $this->assertContains('idempotency_key', $audit->context['failed_fields']);
        $this->assertContains('action', $audit->context['failed_fields']);

        // Only field names are recorded — never the submitted values.
        $this->assertStringNotContainsString('deactivate', json_encode($audit->context));
    }

    public function test_client_id_mismatch_is_audited(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-mismatch'])
            ->postJson('/api/v1/service-requests', $this->payload(['client_id' => 999]))
            ->assertStatus(403);

        $audit = ApiRequestAudit::sole();
        $this->assertSame($organisation->id, $audit->organisation_id);
        $this->assertSame(403, $audit->status_code);
        $this->assertSame('client_id_mismatch', $audit->reason);
    }

    public function test_customer_not_found_is_audited(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-no-customer'])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(404);

        $audit = ApiRequestAudit::sole();
        $this->assertSame($organisation->id, $audit->organisation_id);
        $this->assertSame(404, $audit->status_code);
        $this->assertSame('customer_not_found', $audit->reason);
    }

    public function test_idempotency_conflict_is_audited_exactly_once_and_creates_no_second_service_request(): void
    {
        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-conflict'])
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(201);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-conflict'])
            ->postJson('/api/v1/service-requests', $this->payload(['amount' => 1.00]))
            ->assertStatus(409);

        $this->assertSame(1, ServiceRequest::count());
        $audit = ApiRequestAudit::sole();
        $this->assertSame($organisation->id, $audit->organisation_id);
        $this->assertSame(409, $audit->status_code);
        $this->assertSame('idempotency_conflict', $audit->reason);
    }

    public function test_rejected_requests_never_dispatch_an_activation_job(): void
    {
        Queue::fake();

        [$organisation, $token] = $this->organisationWithToken(18, 'Northstar Telecom');
        Customer::create(['organisation_id' => $organisation->id, 'customer_number' => 10482, 'name' => 'Amahle Dlamini']);

        $this->withHeaders($this->bearer($token) + ['Idempotency-Key' => 'audit-key-no-dispatch'])
            ->postJson('/api/v1/service-requests', $this->payload(['client_id' => 999]))
            ->assertStatus(403);

        Queue::assertNotPushed(ActivateServiceRequestJob::class);
    }

    public function test_audit_context_never_contains_authorization_header_or_token(): void
    {
        [, $token] = $this->organisationWithToken(18, 'Northstar Telecom');

        $this->withHeaders($this->bearer('not-a-real-token'))
            ->postJson('/api/v1/service-requests', $this->payload())
            ->assertStatus(401);

        $audit = ApiRequestAudit::sole();
        $serialised = json_encode($audit->getAttributes());

        $this->assertStringNotContainsString($token, $serialised);
        $this->assertStringNotContainsString('not-a-real-token', $serialised);
        $this->assertStringNotContainsString('Bearer', $serialised);
        $this->assertStringNotContainsString('Authorization', $serialised);
    }
}
