<?php

namespace App\Services\WimsConnect\Drivers;

use App\Contracts\WimsConnect\GatewayDriver;
use App\Services\WimsConnect\Drivers\WhatsApp\GenericWhatsAppProvider;
use App\Services\WimsConnect\Drivers\WhatsApp\OpenWaProvider;
use App\Support\WimsConnect\DeliveryResult;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Str;

/**
 * Targets a self-hosted/open-source WhatsApp gateway over its REST API —
 * same provider-dispatch shape as SmsDriver: config['provider'] is a
 * free-text label the admin types on the settings page, resolved here to a
 * concrete WhatsAppProviderInterface implementation. Unrecognized labels
 * fall back to the generic Evolution/Wuzapi-shaped provider rather than
 * failing outright.
 */
class WhatsAppSelfHostedDriver implements GatewayDriver
{
    public function __construct(protected array $config)
    {
    }

    public function send(string $address, OutboundMessage $message): DeliveryResult
    {
        $provider = $this->resolveProvider();

        return $provider->send($address, $message->body, $this->config);
    }

    protected function resolveProvider()
    {
        $label = Str::lower(trim((string) ($this->config['provider'] ?? '')));

        if (Str::contains($label, ['open-wa', 'openwa', 'open wa'])) {
            return new OpenWaProvider();
        }

        return new GenericWhatsAppProvider();
    }
}
