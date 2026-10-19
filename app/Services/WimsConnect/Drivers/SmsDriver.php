<?php

namespace App\Services\WimsConnect\Drivers;

use App\Contracts\WimsConnect\GatewayDriver;
use App\Services\WimsConnect\Drivers\Sms\BeemSmsProvider;
use App\Services\WimsConnect\Drivers\Sms\GenericHttpSmsProvider;
use App\Support\WimsConnect\DeliveryResult;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Str;

class SmsDriver implements GatewayDriver
{
    public function __construct(protected array $config)
    {
    }

    public function send(string $address, OutboundMessage $message): DeliveryResult
    {
        $provider = $this->resolveProvider();

        return $provider->send($address, $message->body, $this->config);
    }

    /**
     * config['provider'] is a free-text label the admin types on the
     * settings page (e.g. "Beem", "Africa's Talking") — this is the one
     * place that maps a recognized label to a real SmsProviderInterface
     * implementation. Anything unrecognized falls back to the generic
     * HTTP/bearer-token provider rather than failing outright.
     */
    protected function resolveProvider()
    {
        $label = Str::lower(trim((string) ($this->config['provider'] ?? '')));

        if (Str::contains($label, 'beem')) {
            return new BeemSmsProvider();
        }

        return new GenericHttpSmsProvider();
    }
}
