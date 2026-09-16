<?php

namespace App\Exceptions;

use RuntimeException;

class UnretryableServiceRequestException extends RuntimeException
{
    public function __construct(string $status)
    {
        parent::__construct("This service request cannot be retried from its current status ({$status}).");
    }
}
