<?php

namespace App\Contracts\WimsConnect;

use App\Support\WimsConnect\DeliveryResult;
use App\Support\WimsConnect\OutboundMessage;

/**
 * The one contract every WIMS Connect channel implements — WhatsApp
 * (self-hosted), Meta, SMS, and whatever's added later. WIMS business logic
 * never depends on a concrete driver, only on this interface, so a gateway
 * can be swapped without touching anything upstream of GatewayManager.
 */
interface GatewayDriver
{
    public function send(string $address, OutboundMessage $message): DeliveryResult;
}
