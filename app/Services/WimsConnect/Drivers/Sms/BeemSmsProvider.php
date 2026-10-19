<?php

namespace App\Services\WimsConnect\Drivers\Sms;

use App\Support\WimsConnect\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Beem Africa (https://beem.africa) — a Tanzania-headquartered SMS
 * aggregator with direct local telco relationships. Verified against
 * Beem's own API reference (docs.beem.africa/api-reference/send-sms) rather
 * than assumed: HTTP Basic Auth (API key as username, secret as password),
 * not the bearer-token shape GenericHttpSmsProvider uses — that mismatch is
 * exactly why this gets its own class instead of being forced through the
 * generic provider.
 */
class BeemSmsProvider implements SmsProviderInterface
{
    protected const ENDPOINT = 'https://apisms.beem.africa/v1/send';

    protected const BALANCE_ENDPOINT = 'https://apisms.beem.africa/public/v1/vendors/balance';

    public function send(string $address, string $body, array $config): DeliveryResult
    {
        $apiKey = (string) ($config['api_key'] ?? '');
        $apiSecret = (string) ($config['api_secret'] ?? '');
        $senderId = (string) ($config['sender_id'] ?? '');

        if ($apiKey === '' || $apiSecret === '') {
            return DeliveryResult::fail('SMS Gateway is not fully configured - API Key and API Secret are required.');
        }

        if ($senderId === '') {
            return DeliveryResult::fail('SMS Gateway is not fully configured - Sender ID is required (must be pre-registered/approved with Beem).');
        }

        // Beem wants the destination in international format without a
        // leading '+' — recipient_id just needs to be unique within this
        // single-recipient request, so a constant is fine.
        $destAddr = ltrim($address, '+');

        try {
            $response = Http::withBasicAuth($apiKey, $apiSecret)
                ->timeout(15)
                // A single dropped connection to an external API shouldn't
                // fail the whole send — two quick retries absorb a transient
                // network blip without meaningfully delaying a real failure.
                // Restricted to actual connection failures (not throw:true's
                // default of also retrying+throwing on a 4xx/5xx response) —
                // a validation error like a bad Sender ID is identical on
                // every retry, and throwing would replace Beem's own error
                // message below with a generic "connection" one.
                ->retry(2, 500, when: fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post(self::ENDPOINT, [
                    'source_addr' => $senderId,
                    'encoding' => 0,
                    // Beem's encoding=0 (GSM-7) rejects anything outside the
                    // GSM 03.38 alphabet — WIMS's notification templates use
                    // emoji and em dashes freely for richer channels, so SMS
                    // needs its own plain-ASCII pass rather than changing
                    // those shared templates.
                    'message' => $this->sanitizeForSms($body),
                    'recipients' => [
                        ['recipient_id' => 1, 'dest_addr' => $destAddr],
                    ],
                ]);

            $payload = $response->json();

            if ($response->successful() && ($payload['successful'] ?? false) === true) {
                return DeliveryResult::ok($payload['request_id'] ?? null);
            }

            $reason = $payload['message'] ?? ("HTTP {$response->status()}");
            $code = $payload['code'] ?? null;

            return DeliveryResult::fail("Beem SMS error{$this->codeSuffix($code)}: {$reason}");
        } catch (\Throwable $e) {
            Log::warning('[WIMS Connect] Beem SMS send failed', ['error' => $e->getMessage()]);

            return DeliveryResult::fail('Could not reach Beem: '.$e->getMessage());
        }
    }

    /**
     * The credit left on the Beem account (Beem API: GET /public/v1/vendors/balance, same API key and secret).
     * One credit is one SMS segment. Read-only: nothing is sent or charged.
     *
     * @return array{ok:bool, balance:?float, error:?string}
     */
    public function balance(array $config): array
    {
        $apiKey = (string) ($config['api_key'] ?? '');
        $apiSecret = (string) ($config['api_secret'] ?? '');
        if ($apiKey === '' || $apiSecret === '') {
            return ['ok' => false, 'balance' => null, 'error' => 'The API Key and API Secret are not saved yet.'];
        }

        try {
            $response = Http::withBasicAuth($apiKey, $apiSecret)->timeout(12)->acceptJson()
                ->retry(2, 400, when: fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get(self::BALANCE_ENDPOINT);
            $payload = $response->json();
            $credit = $payload['data']['credit_balance'] ?? ($payload['credit_balance'] ?? null);

            if ($response->successful() && is_numeric($credit)) {
                return ['ok' => true, 'balance' => (float) $credit, 'error' => null];
            }
            if (in_array($response->status(), [401, 403], true)) {
                return ['ok' => false, 'balance' => null, 'error' => 'Beem did not accept the API Key and Secret. Check them in the connection settings.'];
            }

            return ['ok' => false, 'balance' => null, 'error' => 'Beem answered with an error: '.($payload['message'] ?? 'HTTP '.$response->status())];
        } catch (\Throwable $e) {
            Log::warning('[WIMS Connect] Beem balance lookup failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'balance' => null, 'error' => 'Could not reach Beem: '.Str::limit($e->getMessage(), 120)];
        }
    }

    protected function codeSuffix($code): string
    {
        return $code !== null ? " (code {$code})" : '';
    }

    /**
     * Beem's plain-text (encoding=0) endpoint only accepts the GSM 03.38
     * alphabet. Common Unicode punctuation is transliterated to its ASCII
     * equivalent so the message stays readable; anything else outside
     * printable ASCII (emoji, box-drawing, etc.) is dropped.
     */
    protected function sanitizeForSms(string $text): string
    {
        $text = strtr($text, [
            "\u{2014}" => '-',  // em dash —
            "\u{2013}" => '-',  // en dash –
            "\u{2018}" => "'",  // left single quote '
            "\u{2019}" => "'",  // right single quote '
            "\u{201C}" => '"',  // left double quote "
            "\u{201D}" => '"',  // right double quote "
            "\u{2026}" => '...', // ellipsis …
            "\u{2022}" => '-',  // bullet •
        ]);

        $text = preg_replace('/[^\x0A\x0D\x20-\x7E]/u', '', $text);

        return trim($text);
    }
}
