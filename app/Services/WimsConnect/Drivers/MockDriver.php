<?php

namespace App\Services\WimsConnect\Drivers;

use App\Contracts\WimsConnect\GatewayDriver;
use App\Support\WimsConnect\DeliveryResult;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Never calls a real API — writes to the application log and always
 * "succeeds". Exists so Test Message, and later event wiring, can be
 * exercised end-to-end before any real gateway has credentials configured.
 */
class MockDriver implements GatewayDriver
{
    public function send(string $address, OutboundMessage $message): DeliveryResult
    {
        Log::info('[WIMS Connect] Mock send', ['to' => $address, 'body' => $message->body]);

        return DeliveryResult::ok('mock-'.Str::random(10));
    }
}
