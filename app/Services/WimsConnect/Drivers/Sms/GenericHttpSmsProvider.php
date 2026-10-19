<?php

namespace App\Services\WimsConnect\Drivers\Sms;

use App\Support\WimsConnect\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Default fallback provider: a plain JSON POST to whatever api_url is
 * configured, bearer-authenticated with api_key. Covers most aggregator
 * APIs without hard-coding any one vendor's SDK — a vendor whose API shape
 * doesn't fit this can get its own SmsProviderInterface implementation
 * later without changing SmsDriver.
 */
class GenericHttpSmsProvider implements SmsProviderInterface
{
    public function send(string $address, string $body, array $config): DeliveryResult
    {
        $apiUrl = (string) ($config['api_url'] ?? '');
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($apiUrl === '' || $apiKey === '') {
            return DeliveryResult::fail('SMS Gateway is not fully configured - API URL and API Key are required.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                // Only retry actual connection failures — a 4xx/5xx response
                // is identical on every retry, and throw:true's default
                // would replace the provider's own status/response below
                // with a generic "connection" error.
                ->retry(2, 500, when: fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($apiUrl, [
                    'to' => $address,
                    'message' => $body,
                    'sender_id' => $config['sender_id'] ?? null,
                    'api_secret' => $config['api_secret'] ?? null,
                ]);

            if ($response->successful()) {
                return DeliveryResult::ok($response->json('id') ?? $response->json('message_id'));
            }

            $reason = $response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}";

            return DeliveryResult::fail("SMS provider error: {$reason}");
        } catch (\Throwable $e) {
            Log::warning('[WIMS Connect] SMS Gateway send failed', ['error' => $e->getMessage()]);

            return DeliveryResult::fail('Could not reach the SMS provider: '.$e->getMessage());
        }
    }
}
