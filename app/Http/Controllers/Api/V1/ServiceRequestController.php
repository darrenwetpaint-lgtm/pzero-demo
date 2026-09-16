<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\RecordApiRequestAudit;
use App\Actions\ServiceRequests\SubmitServiceRequest;
use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\Customer;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceRequestController extends Controller
{
    public function store(StoreServiceRequestRequest $request, SubmitServiceRequest $action, RecordApiRequestAudit $auditor): JsonResponse
    {
        $organisation = $request->organisation();
        $data = $request->validated();

        if ((int) $data['client_id'] !== $organisation->client_id) {
            $auditor->handle($request, $organisation, 403, 'client_id_mismatch');
            abort(403, 'The client_id does not match the authenticated organisation.');
        }

        // customer_id resolves against customers.customer_number, not the
        // internal primary key, and must belong to this organisation. A
        // customer belonging to another organisation is treated identically
        // to a non-existent one, so we never leak cross-organisation existence.
        $customer = Customer::query()
            ->where('organisation_id', $organisation->id)
            ->where('customer_number', $data['customer_id'])
            ->first();

        if (! $customer) {
            $auditor->handle($request, $organisation, 404, 'customer_not_found');
            abort(404, 'Customer not found.');
        }

        try {
            $result = $action->handle($organisation, $customer, $data);
        } catch (IdempotencyConflictException $exception) {
            // The action itself is untouched: we only observe the exception
            // it already throws, audit it, and let it propagate unchanged so
            // its own render() still produces the exact same 409 response.
            $auditor->handle($request, $organisation, 409, 'idempotency_conflict');

            throw $exception;
        }

        $result->serviceRequest->setRelation('organisation', $organisation);
        $result->serviceRequest->setRelation('customer', $customer);

        return (new ServiceRequestResource($result->serviceRequest))
            ->response()
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $organisation = $request->organisation();

        $serviceRequests = ServiceRequest::query()
            ->where('organisation_id', $organisation->id)
            ->with(['organisation', 'customer'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->input('status')),
            )
            ->when(
                $request->filled('customer_id'),
                function ($query) use ($organisation, $request) {
                    $customerId = Customer::query()
                        ->where('organisation_id', $organisation->id)
                        ->where('customer_number', $request->integer('customer_id'))
                        ->value('id');

                    // No matching customer for this organisation: return an
                    // empty page rather than leaking whether the number exists.
                    $query->where('customer_id', $customerId ?? 0);
                },
            )
            ->latest()
            ->paginate(perPage: min((int) $request->integer('per_page', 15), 100))
            ->withQueryString();

        return ServiceRequestResource::collection($serviceRequests);
    }

    public function show(Request $request, int $serviceRequest): ServiceRequestResource
    {
        $model = ServiceRequest::query()
            ->where('organisation_id', $request->organisation()->id)
            ->where('id', $serviceRequest)
            ->with([
                'organisation',
                'customer',
                'events' => fn ($query) => $query->orderBy('created_at'),
            ])
            ->first();

        abort_if(! $model, 404);

        return new ServiceRequestResource($model);
    }
}
