<?php

namespace App\Jobs;

use App\Models\ServiceRequest;
use App\Services\Activation\ActivationOutcome;
use App\Services\Activation\ActivationProvider;
use App\Services\Activation\LookupOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Drives one ServiceRequest through activation, retry-on-unavailable, and
 * timeout/uncertain reconciliation, using the ActivationProvider bound in
 * the container (FakeActivationProvider for this assessment).
 *
 * The ServiceRequest's own `status` and `provider_attempts` columns are the
 * business source of truth for how many times activation has actually been
 * attempted; the job's own $tries/backoff are a declared safety net around
 * that, not the mechanism deciding when to stop retrying.
 *
 * Three safeguards keep activate() at-most-once even across duplicate
 * delivery and worker crashes:
 *  - WithoutOverlapping (below) stops a second worker from even entering
 *    handle() for the same service-request id while one is already running.
 *  - claimForActivation() is the correctness guarantee for concurrent
 *    delivery: it locks the row, re-checks the status hasn't already moved
 *    on, and persists the "processing" claim — all before activate() is
 *    ever called, and all committed before that external call, never during it.
 *  - A worker can still crash *after* that claim commits but *before* it
 *    records activate()'s outcome, leaving the row stuck in "processing".
 *    Redelivery finding "processing" never calls activate() again — since
 *    we don't know whether the previous call reached the provider — and
 *    instead reconciles via lookup() using the already-committed
 *    provider_request_id, exactly like a genuine timeout ("uncertain").
 */
class ActivateServiceRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const MAX_ATTEMPTS = 3;

    /** Backoff (seconds), keyed by the attempt number that just failed. */
    private const RETRY_DELAYS = [1 => 5, 2 => 15];

    /** Delay before the single automatic reconciliation lookup after a timeout. */
    private const RECONCILIATION_DELAY = 10;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(public readonly int $serviceRequestId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_values(self::RETRY_DELAYS);
    }

    /**
     * Keyed by the service-request id so only one execution of this job for
     * a given request runs at a time. expireAfter() bounds the lock so a
     * worker that crashes mid-activation can't block the request forever;
     * a blocked duplicate is released (not discarded) after a short delay,
     * since by the time it runs again the winning worker will have moved
     * the status on, so it safely falls through to a no-op/retry/reconcile
     * rather than losing a legitimate delivery.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->serviceRequestId))
                ->expireAfter(120)
                ->releaseAfter(5),
        ];
    }

    public function handle(ActivationProvider $provider): void
    {
        $serviceRequest = ServiceRequest::find($this->serviceRequestId);

        if (! $serviceRequest) {
            return;
        }

        if (in_array($serviceRequest->status, ['queued', 'retrying'], true)) {
            $this->attemptActivation($serviceRequest, $provider);

            return;
        }

        if ($serviceRequest->status === 'uncertain') {
            $this->reconcile($serviceRequest, $provider);

            return;
        }

        if ($serviceRequest->status === 'processing') {
            // Redelivery finding "processing" means a previous attempt
            // committed its claim (status, provider_attempts,
            // provider_request_id) but the worker never got to persist an
            // outcome — most likely it crashed between calling activate()
            // and recording the result. We do not know whether that
            // activate() call reached the provider, so we must not call it
            // again; only lookup() can safely tell us what actually
            // happened, using the provider_request_id already committed.
            $this->recoverInterruptedProcessing($serviceRequest, $provider);

            return;
        }

        // completed or failed: nothing to do.
    }

    private function attemptActivation(ServiceRequest $serviceRequest, ActivationProvider $provider): void
    {
        $claim = $this->claimForActivation($serviceRequest->id);

        if (! $claim) {
            // Another delivery already claimed (or moved this request past)
            // queued/retrying between handle()'s initial read and here.
            // WithoutOverlapping should already prevent two workers from
            // reaching this point together, but this re-check under a row
            // lock is the actual guarantee, independent of that middleware.
            return;
        }

        [$serviceRequest, $attemptNumber] = $claim;

        // Local processing state is already committed by claimForActivation,
        // and the outcome is persisted separately below — no transaction
        // spans this external call.
        $outcome = $provider->activate($serviceRequest);

        match ($outcome) {
            ActivationOutcome::Success => $this->markCompleted($serviceRequest),
            ActivationOutcome::Unavailable => $this->handleUnavailable($serviceRequest, $attemptNumber),
            ActivationOutcome::Timeout => $this->markUncertain($serviceRequest),
        };
    }

    /**
     * Atomically claim a queued/retrying request for activation: lock the
     * row, re-check its status hasn't already changed since it was last
     * read, and only if it's still claimable, persist the "processing"
     * transition (provider_request_id, provider_attempts, last_attempted_at)
     * before releasing the lock. This is what actually prevents two
     * concurrent deliveries from both calling activate() — the lock and the
     * commit happen entirely before the provider is ever contacted.
     *
     * @return array{0: ServiceRequest, 1: int}|null null if another delivery already claimed it
     */
    private function claimForActivation(int $serviceRequestId): ?array
    {
        return DB::transaction(function () use ($serviceRequestId) {
            $serviceRequest = ServiceRequest::query()
                ->whereKey($serviceRequestId)
                ->lockForUpdate()
                ->first();

            if (! $serviceRequest || ! in_array($serviceRequest->status, ['queued', 'retrying'], true)) {
                return null;
            }

            $fromStatus = $serviceRequest->status;
            $attemptNumber = $serviceRequest->provider_attempts + 1;

            $serviceRequest->forceFill([
                'provider_request_id' => $serviceRequest->provider_request_id ?? (string) Str::uuid(),
                'status' => 'processing',
                'provider_attempts' => $attemptNumber,
                'last_attempted_at' => now(),
            ])->save();

            $serviceRequest->events()->create([
                'from_status' => $fromStatus,
                'to_status' => 'processing',
                'actor_type' => 'system',
                'message' => "Processing started (attempt {$attemptNumber}).",
                'metadata' => ['provider_attempt' => $attemptNumber],
            ]);

            return [$serviceRequest, $attemptNumber];
        });
    }

    private function markCompleted(ServiceRequest $serviceRequest): void
    {
        $this->transition($serviceRequest, [
            'status' => 'completed',
            'completed_at' => now(),
        ], [
            'from_status' => 'processing',
            'to_status' => 'completed',
            'actor_type' => 'provider',
            'message' => 'Activation confirmed.',
            'metadata' => [
                'provider_outcome' => ActivationOutcome::Success->value,
                'provider_request_id' => $serviceRequest->provider_request_id,
            ],
        ]);
    }

    private function handleUnavailable(ServiceRequest $serviceRequest, int $attemptNumber): void
    {
        if ($attemptNumber >= self::MAX_ATTEMPTS) {
            $this->transition($serviceRequest, [
                'status' => 'failed',
            ], [
                'from_status' => 'processing',
                'to_status' => 'failed',
                'actor_type' => 'system',
                'message' => 'Activation failed after maximum attempts.',
                'metadata' => [
                    'provider_outcome' => ActivationOutcome::Unavailable->value,
                    'provider_attempt' => $attemptNumber,
                ],
            ]);

            return;
        }

        $delay = self::RETRY_DELAYS[$attemptNumber] ?? max(self::RETRY_DELAYS);

        $this->transition($serviceRequest, [
            'status' => 'retrying',
        ], [
            'from_status' => 'processing',
            'to_status' => 'retrying',
            'actor_type' => 'provider',
            'message' => 'Provider unavailable; retry scheduled.',
            'metadata' => [
                'provider_outcome' => ActivationOutcome::Unavailable->value,
                'provider_attempt' => $attemptNumber,
                'retry_delay_seconds' => $delay,
            ],
        ]);

        $this->release($delay);
    }

    private function markUncertain(ServiceRequest $serviceRequest): void
    {
        $this->transition($serviceRequest, [
            'status' => 'uncertain',
        ], [
            'from_status' => 'processing',
            'to_status' => 'uncertain',
            'actor_type' => 'provider',
            'message' => 'Provider response timed out; outcome uncertain.',
            'metadata' => [
                'provider_outcome' => ActivationOutcome::Timeout->value,
                'provider_request_id' => $serviceRequest->provider_request_id,
            ],
        ]);

        // Schedule exactly one automatic reconciliation attempt. If it's
        // inconclusive we deliberately stop (see reconcile()) rather than
        // looping — this single follow-up is not another activation attempt,
        // so provider_attempts is untouched.
        $this->release(self::RECONCILIATION_DELAY);
    }

    /**
     * A previously-timed-out request found its provider outcome still
     * unresolved. This never calls activate() again and never touches
     * provider_attempts or provider_request_id — lookup() is the only
     * safe way to resolve it. Shared by both entry points that can reach
     * an unresolved outcome: a genuine timeout ("uncertain") and a
     * recovered interrupted "processing" delivery.
     */
    private function reconcile(ServiceRequest $serviceRequest, ActivationProvider $provider): void
    {
        $fromStatus = $serviceRequest->status;
        $outcome = $provider->lookup($serviceRequest);

        if ($outcome === LookupOutcome::Active) {
            $this->transition($serviceRequest, [
                'status' => 'completed',
                'completed_at' => now(),
            ], [
                'from_status' => $fromStatus,
                'to_status' => 'completed',
                'actor_type' => 'provider',
                'message' => 'Provider lookup confirmed activation.',
                'metadata' => [
                    'lookup_outcome' => LookupOutcome::Active->value,
                    'provider_request_id' => $serviceRequest->provider_request_id,
                ],
            ]);

            return;
        }

        // Inconclusive: a real provider offering neither a reliable lookup
        // nor a callback/webhook would leave us no safer option than this —
        // move to (or stay in) "uncertain" for manual/operator
        // reconciliation, and do not requeue again automatically (no
        // infinite reconciliation loop).
        $this->transition($serviceRequest, [
            'status' => 'uncertain',
        ], [
            'from_status' => $fromStatus,
            'to_status' => 'uncertain',
            'actor_type' => 'provider',
            'message' => 'Provider lookup could not confirm activation status; remains uncertain.',
            'metadata' => [
                'lookup_outcome' => LookupOutcome::Inconclusive->value,
                'provider_request_id' => $serviceRequest->provider_request_id,
            ],
        ]);
    }

    /**
     * Record that an interrupted "processing" delivery was found, then
     * reconcile it exactly like an "uncertain" request — via lookup() only.
     */
    private function recoverInterruptedProcessing(ServiceRequest $serviceRequest, ActivationProvider $provider): void
    {
        $serviceRequest->events()->create([
            'from_status' => 'processing',
            'to_status' => 'processing',
            'actor_type' => 'system',
            'message' => 'Previous processing outcome requires reconciliation.',
            'metadata' => ['provider_request_id' => $serviceRequest->provider_request_id],
        ]);

        $this->reconcile($serviceRequest, $provider);
    }

    /**
     * Persist a status/attribute change and its history event together,
     * without ever holding a transaction open across an external provider call.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $event
     */
    private function transition(ServiceRequest $serviceRequest, array $attributes, array $event): void
    {
        DB::transaction(function () use ($serviceRequest, $attributes, $event) {
            $serviceRequest->forceFill($attributes)->save();
            $serviceRequest->events()->create($event);
        });
    }
}
