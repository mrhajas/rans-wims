<?php

namespace App\Support;

/** Thrown by IdempotencyGuard::claim() when this form already created its record: the caller sends the person to that record and creates nothing. */
class IdempotentReplay extends \RuntimeException
{
    public function __construct(public string $recordType, public ?int $recordId)
    {
        parent::__construct('This form was already saved.');
    }
}
