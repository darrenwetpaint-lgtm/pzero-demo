<?php

namespace App\Http\Resources;

use App\Models\ServiceRequestEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceRequestEvent */
class ServiceRequestEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
