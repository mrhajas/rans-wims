<?php

namespace App\Services\WimsConnect\Drivers\WhatsApp;

use App\Support\WimsConnect\DeliveryResult;

/**
 * The seam that keeps WhatsAppSelfHostedDriver itself provider-agnostic —
 * same role SmsProviderInterface plays for the SMS module. Adding a new
 * self-hosted WhatsApp gateway product means adding one class here, never
 * touching WhatsAppSelfHostedDriver or anything that calls it.
 */
interface WhatsAppProviderInterface
{
    public function send(string $address, string $body, array $config): DeliveryResult;
}
