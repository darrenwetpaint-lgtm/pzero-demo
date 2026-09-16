<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Api\RecordApiRequestAudit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequestRequest extends FormRequest
{
    /**
     * Portal authentication is enforced by the "portal.token" middleware
     * before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Pull the required Idempotency-Key header into the validated data set
     * and normalise the currency code ahead of validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
            'currency' => is_string($this->input('currency'))
                ? strtoupper($this->input('currency'))
                : $this->input('currency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Matches the varchar(255) length of service_requests.idempotency_key
            // exactly, so an oversized key is rejected here (422) rather than
            // ever reaching a database "data too long" error.
            'idempotency_key' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'integer'],
            'customer_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'action' => ['required', 'string', Rule::in(['activate'])],
            'requested_by' => ['required', 'string', Rule::in(['customer'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            // "status" may be supplied per the portal contract but is
            // intentionally accepted-and-ignored — see StoreServiceRequest.
            'status' => ['sometimes', 'string'],
        ];
    }

    /**
     * Record a safe audit row (which fields failed, never their values or
     * the raw body) before handing off to Laravel's normal 422 response —
     * the response shape/content is entirely unchanged by this override.
     */
    protected function failedValidation(Validator $validator): void
    {
        app(RecordApiRequestAudit::class)->handle(
            $this,
            $this->organisation(),
            422,
            'validation_failed',
            ['failed_fields' => array_keys($validator->errors()->toArray())],
        );

        parent::failedValidation($validator);
    }
}
