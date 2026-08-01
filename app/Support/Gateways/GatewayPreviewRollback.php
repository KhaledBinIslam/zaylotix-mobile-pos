<?php

namespace App\Support\Gateways;

/** Thrown purely to force GatewayPricePreview's transaction to roll back — never a real error, always caught in the same file. */
class GatewayPreviewRollback extends \RuntimeException
{
}
