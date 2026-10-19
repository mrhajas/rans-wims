<?php

namespace App\Services\WimsConnect\Drivers;

use App\Contracts\WimsConnect\GatewayDriver;
use App\Support\WimsConnect\DeliveryResult;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The official Meta WhatsApp Business Cloud API — a deliberately separate
 * driver from WhatsAppSelfHostedDriver even though both deliver to
 * WhatsApp, because they're different backends with different auth,
 * payload shapes, and failure modes (see the analysis doc, section I).
 */
class MetaCloudApiDriver implements GatewayDriver
{
    public function __construct(protected array $config)
    {
    }

    public function send(string $address, OutboundMessage $message): DeliveryResult
    {
        $phoneNumberId = (string) ($this->config['phone_number_id'] ?? '');
        $token = (string) ($this->config['access_token'] ?? '');
        $version = (string) ($this->config['api_version'] ?? 'v20.0');

        if ($phoneNumberId === '' || $token === '') {
            return DeliveryResult::fail('Meta Gateway is not fully configured - Phone Number ID and Access Token are required.');
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $address,
                    'type' => 'text',
                    'text' => ['body' => $message->body],
                ]);

            if ($response->successful()) {
                return DeliveryResult::ok($response->json('messages.0.id'));
            }

            $error = $response->json('error.message') ?? "HTTP {$response->status()}";

            return DeliveryResult::fail("Meta API error: {$error}");
        } catch (\Throwable $e) {
            Log::warning('[WIMS Connect] Meta Gateway send failed', ['error' => $e->getMessage()]);

            return DeliveryResult::fail('Could not reach the Meta API: '.$e->getMessage());
        }
    }
}
