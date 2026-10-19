<?php

namespace App\Support\WimsConnect;

class GatewayFieldRegistry
{
    /**
     * Every config field per driver, in display order. `secret` fields are
     * masked when read back to the settings page and left untouched on
     * save if submitted blank (the standard "don't retype your API key
     * every time" convention) — see WimsConnectGatewayController.
     */
    public static function fields(): array
    {
        return [
            'whatsapp' => [
                'provider' => ['label' => __('WhatsApp Gateway Provider'), 'secret' => false, 'placeholder' => __('e.g. OpenWA, Evolution API, Wuzapi')],
                'gateway_url' => ['label' => __('Gateway URL'), 'secret' => false, 'placeholder' => 'https://your-gateway.example.com'],
                'api_endpoint' => ['label' => __('API Endpoint'), 'secret' => false, 'placeholder' => __('message/sendText, ignored for OpenWA, its send path is fixed')],
                'instance_id' => ['label' => __('Instance / Session ID'), 'secret' => false, 'placeholder' => ''],
                'auth_token' => ['label' => __('Authentication Token'), 'secret' => true, 'placeholder' => ''],
                'connected_number' => ['label' => __('Connected WhatsApp Number'), 'secret' => false, 'placeholder' => '+255XXXXXXXXX'],
            ],
            'meta' => [
                'business_account_id' => ['label' => __('Meta Business Account ID'), 'secret' => false, 'placeholder' => ''],
                'whatsapp_business_account_id' => ['label' => __('WhatsApp Business Account ID'), 'secret' => false, 'placeholder' => ''],
                'phone_number_id' => ['label' => __('Phone Number ID'), 'secret' => false, 'placeholder' => ''],
                'access_token' => ['label' => __('Access Token'), 'secret' => true, 'placeholder' => ''],
                'api_version' => ['label' => __('API Version'), 'secret' => false, 'placeholder' => 'v20.0'],
                'webhook_verify_token' => ['label' => __('Webhook Verify Token'), 'secret' => true, 'placeholder' => ''],
            ],
            'sms' => [
                'provider' => ['label' => __('SMS Provider'), 'secret' => false, 'placeholder' => __('e.g. Africa\'s Talking, a local aggregator')],
                'api_url' => ['label' => __('API URL'), 'secret' => false, 'placeholder' => 'https://api.your-sms-provider.com/send'],
                'api_key' => ['label' => __('API Key'), 'secret' => true, 'placeholder' => ''],
                'api_secret' => ['label' => __('API Secret'), 'secret' => true, 'placeholder' => ''],
                'sender_id' => ['label' => __('Sender ID'), 'secret' => false, 'placeholder' => 'RANS WIMS'],
            ],
        ];
    }

    public static function fieldsFor(string $driver): array
    {
        return self::fields()[$driver] ?? [];
    }

    public static function isValidDriver(string $driver): bool
    {
        return array_key_exists($driver, self::fields());
    }
}
