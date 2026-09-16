<?php

namespace App\Services\Activation;

/**
 * The result of an ActivationProvider::lookup() reconciliation call.
 *
 * Inconclusive exists because a real provider may simply be unable to
 * confirm an outcome — that must leave the request "uncertain" for manual
 * reconciliation rather than being treated as a definite failure.
 */
enum LookupOutcome: string
{
    case Active = 'active';
    case Inconclusive = 'inconclusive';
}
