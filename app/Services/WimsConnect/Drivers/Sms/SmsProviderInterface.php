<?php

namespace App\Services\WimsConnect\Drivers\Sms;

use App\Support\WimsConnect\DeliveryResult;

/**
 * The seam that keeps SmsDriver itself provider-agnostic: adding a new SMS
 * provider (Twilio, Africa's Talking, a local aggregator, ...) means adding
 * one class here, never touching SmsDriver or anything that calls it.
 */
interface SmsProviderInterface
{
    public function send(string $address, string $body, array $config): DeliveryResult;
}
