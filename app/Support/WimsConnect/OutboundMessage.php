<?php

namespace App\Support\WimsConnect;

/**
 * Every gateway driver sends this same shape regardless of channel — kept
 * deliberately minimal for Phase 1 (test messages only); event-triggered
 * sends in later phases add context here rather than changing the
 * GatewayDriver contract itself.
 */
class OutboundMessage
{
    public function __construct(public readonly string $body)
    {
    }
}
