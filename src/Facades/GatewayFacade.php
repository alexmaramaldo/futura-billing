<?php
namespace Souldigital\Billing\Facades;

use Illuminate\Support\Facades\Facade;

class GatewayFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'billing.gateway';
    }
}
