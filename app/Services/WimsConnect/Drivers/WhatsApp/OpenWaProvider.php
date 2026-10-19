<?php

namespace App\Services\WimsConnect\Drivers\WhatsApp;

use App\Support\WimsConnect\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenWA (https://www.open-wa.org, github.com/rmyndharis/OpenWA) — a
 * self-hosted, session-based WhatsApp REST API server (whatsapp-web.js or
 * Baileys underneath). Verified against its own docs rather than assumed:
 * X-API-Key header auth (not the generic gateway's lowercase "apikey"),
 * POST /api/sessions/{sessionId}/messages/send-text with a {chatId, text}
 * body (not {number, text}), and phone numbers addressed as
 * "<digits>@c.us" rather than a bare number — three mismatches with
 * GenericWhatsAppProvider's Evolution/Wuzapi-shaped request, which is
 * exactly why this gets its own class instead of being forced through it.
 *
 * config['gateway_url']  — e.g. https://your-openwa-host:2785
 * config['instance_id']  — the OpenWA session ID (created via its own
 *                           dashboard/API and linked to a real WhatsApp
 *                           number by scanning a QR code there first —
 *                           WIMS only ever sends through an
 *                           already-authenticated session, it never
 *                           performs that linking itself)
 * config['auth_token']   — the session's API key
 */
class OpenWaProvider implements WhatsAppProviderInterface
{
    public function send(string $address, string $body, array $config): DeliveryResult
    {
        $baseUrl = rtrim((string) ($config['gateway_url'] ?? ''), '/');
        $sessionId = trim((string) ($config['instance_id'] ?? ''));
        $apiKey = (string) ($config['auth_token'] ?? '');

        if ($baseUrl === '' || $sessionId === '') {
            return DeliveryResult::fail('WhatsApp Gateway is not fully configured - Gateway URL and Instance/Session ID are required.');
        }

        if ($apiKey === '') {
            return DeliveryResult::fail('WhatsApp Gateway is not fully configured - Authentication Token (API key) is required.');
        }

        // OpenWA addresses individual chats as "<digits>@c.us" — strip
        // everything but digits (leading '+', spaces, dashes) rather than
        // assume the stored number is already in that exact shape.
        $chatId = preg_replace('/\D/', '', $address).'@c.us';

        try {
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->timeout(15)
                // Only retry actual connection failures — a 4xx/5xx response
                // is identical on every retry, and throw:true's default
                // would replace OpenWA's own error message below with a
                // generic "connection" one.
                ->retry(2, 500, when: fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post("{$baseUrl}/api/sessions/{$sessionId}/messages/send-text", [
                    'chatId' => $chatId,
                    'text' => $body,
                ]);

            if ($response->successful()) {
                $id = $response->json('id')
                    ?? $response->json('messageId')
                    ?? $response->json('key.id')
                    ?? $response->json('data.id');

                return DeliveryResult::ok($id);
            }

            $reason = $response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}";

            return DeliveryResult::fail("OpenWA error: {$reason}");
        } catch (\Throwable $e) {
            Log::warning('[WIMS Connect] OpenWA send failed', ['error' => $e->getMessage()]);

            return DeliveryResult::fail('Could not reach OpenWA: '.$e->getMessage());
        }
    }
}
