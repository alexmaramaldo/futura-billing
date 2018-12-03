<?php

namespace Souldigital\Billing;

use Illuminate\Support\ServiceProvider;
use Vindi\Plan as VindiPlan;
use Vindi\Subscription as VindiSubscription;
use Vindi\Customer as VindiCustomer;
use Vindi\PaymentProfile as VindiPaymentProfile;
use Vindi\Discount as VindiDiscount;
use Vindi\Bill as VindiBill;
use Souldigital\Billing\Contracts\BillingGateway;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes(
            [
                __DIR__ . '/config/billing.php' => config_path('billing.php')
            ]
        );
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        include __DIR__.'/routes/webhooks.php';
        putenv('CREDIT_CARD_LABEL=credit_card');
        $this->app->bind(
            'billing.gateway',
            function ($app) {
                putenv("VINDI_API_KEY=".app('v2vindiApiKey'));
                return new \Souldigital\Billing\Gateways\Vindi\Gateway(new VindiCustomer(),new VindiPlan(),new VindiPaymentProfile(),new VindiSubscription(), new VindiBill());
            }
        );

        $this->mergeConfigFrom(
            __DIR__ . '/config/billing.php',
            'billing'
        );
    }
}
