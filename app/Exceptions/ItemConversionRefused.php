<?php

namespace App\Exceptions;

use RuntimeException;

/** An item that cannot become an asset (and so stays exactly as it is); the message says why, in words a person can act on. */
class ItemConversionRefused extends RuntimeException
{
}
