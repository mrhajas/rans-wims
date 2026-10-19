<?php

namespace App\Support\Simulation;

use RuntimeException;

/** The simulation was asked for but is switched off, not configured, or its database cannot be reached. */
class EnvironmentUnavailable extends RuntimeException
{
}
