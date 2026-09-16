<?php

namespace App\Actions\ServiceRequests;

use App\Exceptions\UnretryableServiceRequestException;
use App\Jobs\ActivateServiceRequestJob;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A manual operator "retry" is only meaningful — and only safe — from two
 * statuses, and it means two different things depending on which:
 *
 *  - "failed": every path to this status is 3 consecutive, explicit
 *    "provider did not accept this" outcomes (see FakeActivationProvider /
 *    ActivateServiceRequestJob::handleUnavailable()) — the provider never
 *    activated anything, so restarting activation from scratch cannot
 *    double-activate. provider_attempts is reset to 0 so the new attempt
 *    cycle gets the same 3-attempt budget the original submission had;
 *    provider_request_id is deliberately left untouched (it was never
 *    associated with any accepted activation).
 *
 *  - "uncertain": the provider may already have activated the service —
 *    that ambiguity is exactly what "uncertain" means. Calling activate()
 *    again here would risk double-activating a real service, so a retry
 *    from "uncertain" never changes status or calls activate(); it only
 *    re-dispatches the job, whose existing routing (see
 *    ActivateServiceRequestJob::handle()) will call lookup() again, never
 *    activate(), for a request that is still "uncertain".
 *
 * No other status is retryable: queued/processing/retrying are already
 * progressing on their own, and completed must never be reopened.
 */
class RetryServiceRequest
{
    private const RETRYABLE_STATUSES = ['failed', 'uncertain'];

    public function handle(ServiceRequest $serviceRequest, User $operator): ServiceRequest
    {
        $locked = DB::transaction(function () use ($serviceRequest, $operator) {
            $locked = ServiceRequest::query()
                ->whereKey($serviceRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, self::RETRYABLE_STATUSES, true)) {
                throw new UnretryableServiceRequestException($locked->status);
            }

            $fromStatus = $locked->status;
            $toStatus = $fromStatus;

            if ($fromStatus === 'failed') {
                $toStatus = 'queued';

                $locked->forceFill([
                    'status' => $toStatus,
                    'provider_attempts' => 0,
                ])->save();
            }

            $locked->events()->create([
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_type' => 'operator',
                'actor_id' => $operator->id,
                'message' => $fromStatus === 'failed'
                    ? 'Operator requested retry; activation will be attempted again.'
                    : 'Operator requested reconciliation; checking provider status.',
            ]);

            return $locked;
        });

        // Dispatched only now that the status change (if any) and its event
        // have committed — mirrors SubmitServiceRequest's dispatch discipline.
        ActivateServiceRequestJob::dispatch($locked->id)->afterCommit();

        return $locked->fresh();
    }
}
