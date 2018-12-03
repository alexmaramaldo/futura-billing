<?php

namespace Souldigital\Billing;

use Exception;
use Carbon\Carbon;
use InvalidArgumentException;
use Souldigital\Billing\Facades\GatewayFacade as BillingGateway;

trait Billable
{

    /**
     * Determines if the customer currently has a card on file.
     *
     * @return bool
     */
    public function hasCardOnFile()
    {
        return (bool) $this->cartao_numero;
    }

    /**
     * Add an invoice item to the customer's upcoming invoice.
     *
     * @param  string $description
     * @param  int    $amount
     * @param  array  $options
     * @return \stdClass
     */
    public function tab($paymentData, array $billItems)
    {
        if (! $this->biller_id) {
            throw new InvalidArgumentException(class_basename($this).' is not a customer. See the createAsStripeCustomer method.');
        }

        $payload = [
            'customer_id' => $this->biller_id,
            'payment_method_code' => $paymentData['payment_type'],
            'bill_items' => [(Object)$billItems],
            'installments' => $paymentData['installments'] ? $paymentData['installments'] : 1
        ];

        if ($paymentData['payment_type'] === 'credit_card') {
            $paymentProfile = BillingGateway::getPaymentProfile($this->biller_id, $paymentData);
            $payload['payment_profile']['id'] = $paymentProfile->id;
        }

        return BillingGateway::createBill($payload);
    }

    /**
     * Invoice the customer for the given amount and generate an invoice immediately.
     *
     * @param  string $description
     * @param  int    $amount
     * @param  array  $options
     * @return bool
     */
    public function invoiceFor($paymentData, $productItems)
    {
        return $this->tab($paymentData, $productItems);
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string $subscription
     * @param  string $plan
     * @return \Souldigital\Billing\SubscriptionBuilder
     */
    public function newSubscription($plan,$subscription = 'default')
    {
        return new SubscriptionBuilder($this, $subscription, BillingGateway::getPlanId($plan), $plan);
    }

    /**
     * Determine if the model is on trial.
     *
     * @param  string      $subscription
     * @param  string|null $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
            $subscription->biller_plan === $plan;
    }

    /**
     * Determine if the model is on a "generic" trial at the model level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && Carbon::now()->lt($this->trial_ends_at);
    }

    /**
     * Determine if the model has a given subscription.
     *
     * @param  string      $subscription
     * @param  string|null $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&

            $subscription->biller_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string $subscription
     * @return \Souldigital\Billing\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(
            function ($value) {
                if($value->name == '' || is_null($value->name)){
                    $value->name = 'default';
                    $value->save();
                }
                return $value->created_at->getTimestamp();
            }
        )
            ->first(
                function ($value) use ($subscription) {
                    return $value->name === $subscription;
                }
            );
    }

    /**
     * Get all of the subscriptions for the model.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'user_id')->orderBy('created_at', 'desc');
    }

    public function billerSubscriptions()
    {
        $billerSubscriptions = [];
        $subscriptions = BillingGateway::getSubscriptions($this->biller_id);

        if (count($subscriptions)) {
            $billerSubscriptions = $subscriptions;
        }

        return $billerSubscriptions;
    }

    /**
     * Create the entity payment profile on subscription gateway and update the payment information of entity
     *
     * @param  array $parameters
     * @return \Illuminate\Support\Collection
     */
    public function createPaymentProfile($paymentData)
    {
        $paymentData = Billing::validatePaymentData($paymentData);
        $paymentProfile = BillingGateway::getPaymentProfile($this->biller_id, $paymentData);
        $this->fill(
            [
                'cartao_numero' => substr($paymentData['card_number'], -4),
                'cartao' => $paymentProfile->payment_company->name
            ]
        )->save();
    }

    /**
     * Determine if the model is actively subscribed to one of the given plans.
     *
     * @param  array|string $plans
     * @param  string       $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }
        foreach ((array) $plans as $plan) {
            if ($subscription->biller_plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return ! is_null(
            $this->subscriptions->first(
                function ($value) use ($plan) {
                    return $value->biller_plan === $plan && $value->valid();
                }
            )
        );
    }

    /**
     * Determine if the entity has a customer ID.
     *
     * @return bool
     */
    public function hasBillerId()
    {
        return ! is_null($this->biller_id);
    }

    /**
     * Create a customer for the given model.
     *
     * @param  string $token
     * @param  array  $options
     * @return stdClass
     *
     */
    public function createCustomer($options = [])
    {
        $options = array_key_exists('email', $options)
            ? $options : array_merge($options, ['email' => $this->email]);
        $options = array_key_exists('name', $options)
            ? $options : array_merge($options, ['name' => $this->name]);


        $billerCustomer = BillingGateway::getCustomerByEmail($options['email']);

        if (!count($billerCustomer)) {
            $customer = BillingGateway::createCustomer($options);
        } else {
            $customer = $billerCustomer[0];
        }

        $this->biller_id = $customer->id;
        $this->customer_id = $customer->id;

        $this->save();

        return $customer;
    }

    /**
     * Get the biller provider customer for the given model.
     *
     * @return \stdClass
     */
    public function asBillerCustomer()
    {
        return BillingGateway::getCustomer($this->biller_id);
    }

    public function cancelled() : bool
    {
        return $this->subscription()->cancelled();
    }

}
