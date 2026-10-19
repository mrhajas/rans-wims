<?php

namespace App\Services\WimsConnect\Drivers\WhatsApp;

use App\Support\WimsConnect\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Targets a self-hosted/open-source WhatsApp gateway (e.g. Evolution API,
 * Wuzapi, or similar) over its REST API — deliberately generic rather than
 * tied to one product's exact payload shape. Every field comes from
 * wims_connect_gateways.config; nothing is hard-coded. This is the fallback
 * used whenever config['provider'] isn't recognized as a specific product
 * (see WhatsAppSelfHostedDriver::resolveProvider()) — OpenWaProvider is the
 * one exception, since its API shape doesn't fit this generic pattern at all.
 */
class GenericWhatsAppProvider implements WhatsAppProviderInterface
{
    public function send(string $address, string $body, array $config): DeliveryResult
    {
        $url = rtrim((string) ($config['gateway_url'] ?? ''), '/');
        $endpoint = trim((string) ($config['api_endpoint'] ?? ''), '/');
        $instance = (string) ($config['instance_id'] ?? '');
        $token = (string) ($config['auth_token'] ?? '');

        if ($url === '' || $endpoint === '') {
            return DeliveryResult::fail('WhatsApp Gateway is not fully configured - Gateway URL and API Endpoint are required.');
        }

        $path = $instance !== '' ? "{$url}/{$endpoint}/{$instance}" : "{$url}/{$endpoint}";

        try {
            $response = Http::withHeaders(['apikey' => $token])
                ->timeout(15)
                // Only retry actual connection failures — a 4xx/5xx response
                // is identical on every retry, and throw:true's default
                // would replace the status/response handling below with a
                // generic "connection" error.
                ->retry(2, 500, when: fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($path, [
                    'number' => $address,
                    'text' => $body,
                ]);

            if ($response->successful()) {
                return DeliveryResult::ok($response->json('key.id') ?? $response->json('id'));
            }

            return DeliveryResult::fail("Gateway responded with HTTP {$response->status()}.");
        } catch (\Throwable $e) {
            Log::warning('[WIMS Connect] WhatsApp Gateway send failed', ['error' => $e->getMessage()]);

            return DeliveryResult::fail('Could not reach the WhatsApp Gateway: '.$e->getMessage());
        }
    }
}
