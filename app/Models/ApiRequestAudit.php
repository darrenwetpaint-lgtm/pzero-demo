<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $organisation_id
 * @property string $method
 * @property string $route
 * @property int $status_code
 * @property string $reason
 * @property array<string, mixed>|null $context
 * @property string|null $ip_hash
 * @property Carbon|null $created_at
 */
#[Fillable([
    'organisation_id',
    'method',
    'route',
    'status_code',
    'reason',
    'context',
    'ip_hash',
])]
class ApiRequestAudit extends Model
{
    /**
     * Audit rows are immutable — there is no updated_at column.
     */
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
