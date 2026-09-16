<?php

namespace App\Http\Resources;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceRequest */
class ServiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->display_reference,
            'client_id' => $this->organisation->client_id,
            'customer' => [
                'customer_id' => $this->customer->customer_number,
                'name' => $this->customer->name,
            ],
            'service_id' => $this->service_id,
            'action' => $this->action,
            'requested_by' => $this->requested_by,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'history' => $this->whenLoaded(
                'events',
                fn () => ServiceRequestEventResource::collection($this->events),
            ),
        ];
    }
}
