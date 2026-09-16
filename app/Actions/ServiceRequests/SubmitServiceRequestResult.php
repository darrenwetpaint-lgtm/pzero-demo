<?php

namespace App\Actions\ServiceRequests;

use App\Models\ServiceRequest;

final readonly class SubmitServiceRequestResult
{
    public function __construct(
        public ServiceRequest $serviceRequest,
        public bool $wasCreated,
    ) {}
}
