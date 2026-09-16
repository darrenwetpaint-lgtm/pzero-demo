<?php

namespace App\Services\Activation;

/**
 * The result of a single ActivationProvider::activate() call.
 */
enum ActivationOutcome: string
{
    case Success = 'success';
    case Unavailable = 'unavailable';
    case Timeout = 'timeout';
}
