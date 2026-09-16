<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $customer_id
 * @property int $service_id
 * @property string $action
 * @property string $requested_by
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property string $display_reference
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property string|null $provider_request_id
 * @property int $provider_attempts
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organisation_id',
    'customer_id',
    'service_id',
    'action',
    'requested_by',
    'amount',
    'currency',
    'status',
    'display_reference',
    'idempotency_key',
    'request_fingerprint',
    'provider_request_id',
    'provider_attempts',
    'last_attempted_at',
    'completed_at',
])]
class ServiceRequest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<ServiceRequestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ServiceRequestEvent::class);
    }
}
