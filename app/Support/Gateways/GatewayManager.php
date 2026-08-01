<?php

namespace App\Support\Gateways;

/**
 * The single place that knows which provider key maps to which driver
 * class — adding a future gateway is: write one class implementing
 * GatewayDriver, add one line here. Nothing else in checkout, the webhook
 * route, or the settings UI needs to know a new provider exists beyond
 * this list (and the `provider` enum on the two gateway tables).
 */
class GatewayManager
{
    private const DRIVERS = [
        'sslcommerz' => SslcommerzDriver::class,
        'bkash' => BkashDriver::class,
        'nagad' => NagadDriver::class,
    ];

    public static function driver(string $provider): GatewayDriver
    {
        if (! isset(self::DRIVERS[$provider])) {
            throw new \InvalidArgumentException("Unknown payment gateway: {$provider}");
        }

        return app(self::DRIVERS[$provider]);
    }

    public static function providers(): array
    {
        return array_keys(self::DRIVERS);
    }
}
