<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Show a single customer, scoped to the authenticated organisation.
     *
     * $customerNumber is the portal-facing customers.customer_number, not
     * the internal primary key. Resolved explicitly (no implicit route
     * model binding) so an unscoped lookup can never leak another
     * organisation's customer.
     */
    public function show(Request $request, int $customerNumber): CustomerResource
    {
        $customer = Customer::query()
            ->where('organisation_id', $request->organisation()->id)
            ->where('customer_number', $customerNumber)
            ->first();

        abort_if(! $customer, 404);

        return new CustomerResource($customer);
    }
}
