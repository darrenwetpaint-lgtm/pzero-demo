<?php

namespace App\Http\Controllers;

use App\Actions\ServiceRequests\RetryServiceRequest;
use App\Exceptions\UnretryableServiceRequestException;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestEvent;
use Illuminate\Cache\Lock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ServiceRequestController extends Controller
{
    /**
     * The only statuses the application ever assigns to a service request.
     * An unrecognised filter value is treated the same as "no filter".
     *
     * @var array<int, string>
     */
    private const STATUSES = ['queued', 'processing', 'retrying', 'uncertain', 'completed', 'failed'];

    /**
     * Statuses a manual operator retry is actually safe from — kept in sync
     * with RetryServiceRequest's own guard so the UI only ever offers the
     * action when the backend can honour it. See RetryServiceRequest for why
     * these two, and only these two.
     *
     * @var array<int, string>
     */
    private const RETRYABLE_STATUSES = ['failed', 'uncertain'];

    /**
     * Strict allow-list of sortable fields: request-supplied "sort" is only
     * ever used as a lookup key into this array, never interpolated into a
     * query directly, so arbitrary column names can never reach SQL.
     * "customer" is handled separately (it sorts by a joined column, not a
     * column on service_requests itself).
     *
     * @var array<string, string>
     */
    private const SORTABLE_COLUMNS = [
        'reference' => 'display_reference',
        'service_id' => 'service_id',
        'amount' => 'amount',
        'status' => 'status',
        'created' => 'created_at',
    ];

    /**
     * List the authenticated operator's organisation's service requests.
     */
    public function index(Request $request): Response
    {
        $organisationId = $request->user()->organisation_id;
        $status = $request->string('status')->toString();
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        $sort = $request->string('sort')->toString();
        $sort = $sort === 'customer' || array_key_exists($sort, self::SORTABLE_COLUMNS) ? $sort : 'created';

        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        // The public-facing customer identifier (not the internal id), used
        // for the "view this customer's requests" link on the detail page.
        // Scoped to the same organisation as the request itself, so a
        // customer number from another organisation can never leak in.
        $customerNumber = $request->integer('customer') ?: null;

        $query = ServiceRequest::query()
            // Qualified with the table name: once "customer" sorting joins
            // in the customers table below, an unqualified organisation_id
            // is ambiguous (both tables have that column).
            ->where('service_requests.organisation_id', $organisationId)
            ->with('customer:id,customer_number,name')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when(
                $customerNumber,
                fn ($q) => $q->whereHas(
                    'customer',
                    fn ($q) => $q->where('organisation_id', $organisationId)
                        ->where('customer_number', $customerNumber),
                ),
            );

        if ($sort === 'customer') {
            // Sorting by customer means sorting by the customer's name, which
            // lives on a joined table, not a column on service_requests.
            $query->join('customers', 'customers.id', '=', 'service_requests.customer_id')
                ->orderBy('customers.name', $direction)
                ->orderBy('service_requests.id', $direction)
                ->select('service_requests.*');
        } else {
            $query->orderBy(self::SORTABLE_COLUMNS[$sort], $direction)
                ->orderBy('service_requests.id', $direction);
        }

        $serviceRequests = $query
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ServiceRequest $serviceRequest) => [
                'id' => $serviceRequest->id,
                'reference' => $serviceRequest->display_reference,
                'customer' => [
                    'customer_id' => $serviceRequest->customer->customer_number,
                    'name' => $serviceRequest->customer->name,
                ],
                'service_id' => $serviceRequest->service_id,
                'amount' => $serviceRequest->amount,
                'currency' => $serviceRequest->currency,
                'status' => $serviceRequest->status,
                'created_at' => $serviceRequest->created_at,
            ]);

        return Inertia::render('ServiceRequests/Index', [
            'serviceRequests' => $serviceRequests,
            'filters' => [
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
                'customer' => $customerNumber,
            ],
            'statusOptions' => self::STATUSES,
        ]);
    }

    /**
     * Show a single service request, explicitly scoped to the authenticated
     * operator's organisation — a request belonging to another organisation
     * must be indistinguishable from one that doesn't exist.
     */
    public function show(Request $request, int $serviceRequest): Response
    {
        $model = ServiceRequest::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->where('id', $serviceRequest)
            ->with([
                'customer',
                'events' => fn ($query) => $query->orderBy('created_at'),
            ])
            ->first();

        abort_if(! $model, 404);

        return Inertia::render('ServiceRequests/Show', [
            'serviceRequest' => [
                'id' => $model->id,
                'reference' => $model->display_reference,
                'status' => $model->status,
                'customer' => [
                    'customer_id' => $model->customer->customer_number,
                    'name' => $model->customer->name,
                ],
                'service_id' => $model->service_id,
                'action' => $model->action,
                'requested_by' => $model->requested_by,
                'amount' => $model->amount,
                'currency' => $model->currency,
                'provider_attempts' => $model->provider_attempts,
                'created_at' => $model->created_at,
                'completed_at' => $model->completed_at,
                'can_retry' => in_array($model->status, self::RETRYABLE_STATUSES, true),
            ],
            'history' => $model->events->map(fn (ServiceRequestEvent $event) => [
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'actor_type' => $event->actor_type,
                'message' => $event->message,
                'provider_attempt' => $event->metadata['provider_attempt'] ?? null,
                // Whitelisted, human-safe metadata fields only — never the
                // raw metadata JSON blob itself.
                'provider_outcome' => $event->metadata['provider_outcome'] ?? null,
                'lookup_outcome' => $event->metadata['lookup_outcome'] ?? null,
                'retry_delay_seconds' => $event->metadata['retry_delay_seconds'] ?? null,
                'created_at' => $event->created_at,
            ])->values(),
        ]);
    }

    /**
     * Manually retry a service request the operator's organisation owns.
     * Only "failed" and "uncertain" are ever retryable — see
     * RetryServiceRequest for exactly why, and why no other status is.
     *
     * A short-lived cache lock guards against a rapid double-click
     * dispatching two retries before the first has committed; the action's
     * own row lock and status check are the deeper guarantee.
     */
    public function retry(Request $request, RetryServiceRequest $action, int $serviceRequest): RedirectResponse
    {
        $model = ServiceRequest::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->where('id', $serviceRequest)
            ->first();

        abort_if(! $model, 404);

        /** @var Lock $lock */
        $lock = Cache::lock("service-request-retry:{$model->id}", 10);

        if (! $lock->get()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'A retry is already being processed for this request.',
            ]);

            return back();
        }

        try {
            $updated = $action->handle($model, $request->user());

            Inertia::flash('toast', [
                'type' => $updated->status === 'queued' ? 'success' : 'info',
                'message' => $updated->status === 'queued'
                    ? 'Retry requested — activation will be attempted again.'
                    : 'Reconciliation requested — checking the provider for a confirmed outcome.',
            ]);
        } catch (UnretryableServiceRequestException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        return back();
    }
}
