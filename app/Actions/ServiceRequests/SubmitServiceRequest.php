<?php

namespace App\Actions\ServiceRequests;

use App\Exceptions\IdempotencyConflictException;
use App\Jobs\ActivateServiceRequestJob;
use App\Models\Customer;
use App\Models\Organisation;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SubmitServiceRequest
{
    /**
     * Accept a validated portal service-request submission, applying the
     * organisation-scoped Idempotency-Key contract:
     *
     *  - no existing row for (organisation, key)   -> create it
     *  - existing row, same fingerprint            -> replay it (no new row/event)
     *  - existing row, different fingerprint        -> conflict
     *
     * A plain "check then insert" has a race window between two concurrent
     * deliveries of the same key, so the initial check is only the common
     * case fast-path: the database's unique constraint on
     * (organisation_id, idempotency_key) is the actual guarantee. If the
     * insert loses that race, we re-fetch the row the winner created and
     * apply the exact same replay/conflict decision to it.
     *
     * @param  array<string, mixed>  $data  validated request payload, including 'idempotency_key'
     */
    public function handle(Organisation $organisation, Customer $customer, array $data): SubmitServiceRequestResult
    {
        $fingerprint = $this->fingerprint($data);

        $existing = $this->findExisting($organisation, $data['idempotency_key']);

        if ($existing) {
            return $this->resolveAgainstExisting($existing, $fingerprint);
        }

        try {
            $result = DB::transaction(function () use ($organisation, $customer, $data, $fingerprint) {
                $serviceRequest = ServiceRequest::create([
                    'organisation_id' => $organisation->id,
                    'customer_id' => $customer->id,
                    'service_id' => $data['service_id'],
                    'action' => $data['action'],
                    'requested_by' => $data['requested_by'],
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'currency' => $data['currency'],
                    'status' => 'queued',
                    'display_reference' => $customer->customer_number.'-'.now()->timestamp,
                    'idempotency_key' => $data['idempotency_key'],
                    'request_fingerprint' => $fingerprint,
                ]);

                ServiceRequestEvent::create([
                    'service_request_id' => $serviceRequest->id,
                    'from_status' => null,
                    'to_status' => 'queued',
                    'actor_type' => 'portal',
                    'actor_id' => null,
                    'message' => 'Service request accepted.',
                    'metadata' => ['idempotency_key' => $data['idempotency_key']],
                ]);

                return new SubmitServiceRequestResult($serviceRequest, wasCreated: true);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            // Another request won the race and inserted this (organisation, key)
            // between our pre-check and our insert attempt.
            $existing = $this->findExisting($organisation, $data['idempotency_key']);

            if (! $existing) {
                throw $exception;
            }

            return $this->resolveAgainstExisting($existing, $fingerprint);
        }

        // Dispatched only for a genuinely new request. ->afterCommit() is
        // used rather than relying on "this line only runs after the inner
        // DB::transaction() above returns": that's true today, but afterCommit()
        // also protects against a future outer transaction (e.g. a caller
        // wrapping this whole action) ever letting the job run before the
        // ServiceRequest and its initial event are actually committed. A
        // replay or conflict never reaches this line.
        ActivateServiceRequestJob::dispatch($result->serviceRequest->id)->afterCommit();

        return $result;
    }

    private function findExisting(Organisation $organisation, string $idempotencyKey): ?ServiceRequest
    {
        return ServiceRequest::query()
            ->where('organisation_id', $organisation->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function resolveAgainstExisting(ServiceRequest $existing, string $fingerprint): SubmitServiceRequestResult
    {
        if (hash_equals($existing->request_fingerprint, $fingerprint)) {
            return new SubmitServiceRequestResult($existing, wasCreated: false);
        }

        throw new IdempotencyConflictException;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000';
    }

    /**
     * Build a stable SHA-256 fingerprint of the business fields that define
     * "the same request", deliberately excluding the portal-supplied status.
     *
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data): string
    {
        $canonical = [
            'client_id' => (int) $data['client_id'],
            'customer_id' => (int) $data['customer_id'],
            'service_id' => (int) $data['service_id'],
            'action' => $data['action'],
            'requested_by' => $data['requested_by'],
            'amount' => number_format((float) $data['amount'], 2, '.', ''),
            'currency' => $data['currency'],
        ];

        // JSON_THROW_ON_ERROR makes this throw instead of ever returning
        // false, so hash() always receives a real string — $canonical is a
        // plain scalar array, so encoding failure isn't expected, but this
        // fails loudly rather than silently passing an invalid value through.
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
