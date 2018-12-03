<?php

namespace Souldigital\Billing;

use Carbon\Carbon;
use Souldigital\Billing\Facades\GatewayFacade as BillingGateway;

class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $owner;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $planName;

    /**
     * The id of the plan being subscribed to.
     *
     * @var int
     */
    protected $planId;

    /**
     * The quantity of the subscription.
     *
     * @var int
     */
    protected $quantity = 1;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indica a data de cobrança da fatura. 0 Significa que a data da fatura será a data de vencimento da assinatura.
     * Qualquer valor negativo terá como referencial a data de vencimento da assinatura.
     *
     * @var int
     */
    protected $billingTriggerDay;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * The metadata to apply to the subscription.
     *
     * @var array|null
     */
    protected $metadata;

    /**
     * @var
     */
    protected $discount;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner
     * @param  string $name
     * @param  string $plan
     * @return void
     */
    public function __construct($owner, $name, $planId, $planName)
    {
        $this->name = $name;
        $this->planId = $planId;
        $this->planName = $planName;
        $this->owner = $owner;
    }

    /**
     * Specify the quantity of the subscription.
     *
     * @param  int $quantity
     * @return $this
     */
    public function quantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Especifica a data de cobrança da fatura.
     *
     * @param  int $billingTriggerDay
     * @return $this
     */
    public function billingTriggerDay($billingTriggerDay)
    {
        $this->billingTriggerDay = $billingTriggerDay;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param  string $coupon
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;

        return $this;
    }

    /**
     * The metadata to apply to a new subscription.
     *
     * @param  array $metadata
     * @return $this
     */
    public function withMetadata($metadata)
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function withDiscount($type, $value, $cycles)
    {
        $this->discount = [
            'discount_type'=>$type,
            'amount'=>$value,
            'cycles'=>$cycles
        ];

        return $this;
    }

    /**
     * Add a new Vindi subscription to the Vindi model.
     *
     * @param  array $options
     * @return \Souldigital\Billing\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Vindi subscription.
     *
     * @param  string|null $token
     * @param  array       $options
     * @return \Souldigital\Billing\Subscription
     */
    public function create($productItems, $paymentData = null, array $options = [])
    {

        $customer = $this->getBillerCustomer($paymentData, $options);

        $boleto = $paymentData['payment_type'] == 'bank_slip' ? 1 : 0;

        BillingGateway::customerIsBankSlip($customer->id,$boleto);

        if ($paymentData['payment_type'] === getenv('CREDIT_CARD_LABEL')) {

            if(!$this->owner->hasSubscription()){
                $this->trialDays(7);
            } else{
                // Usuário já tem plano
                $this->skipTrial();
            }

            $this->billingTriggerDay('0');
            $this->owner->createPaymentProfile($paymentData);
        } else {
            //Assinaturas via boleto não tem trial
            $this->skipTrial();

            $cpf = Billing::validateCpf($paymentData['cpf']);
            $this->owner->cpf = $cpf;
            $this->owner->cartao = "BOLETO";
            $this->owner->save();
            $this->billingTriggerDay('-10');
        }

        $this->clearUsersSubscriptions();

        $endsAt = Carbon::now();

        $subscription = BillingGateway::createSubscription($this->buildPayload($customer, $paymentData, $productItems));
        $bill = $subscription->bill;
        $subscription = $subscription->subscription;
        $status = 'pending';

        if ($paymentData['payment_type'] === getenv('CREDIT_CARD_LABEL') && $subscription->status === 'active') {
            $status = 'active';
            if (isset($subscription->current_period)) {
                $endsAt = date('Y-m-d H:i:s', strtotime($subscription->current_period->end_at));
            }
        }

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }

        if ($paymentData['payment_type'] === 'bank_slip') {
            $bankSlipUrl = $bill->charges[0]->print_url;
        } else{
            $bankSlipUrl = null;
        }

        $ownerSubscription =  $this->owner->subscriptions()->create([
            'name' => $this->name,
            'biller_id' => $subscription->id,
            'customer_id' => $customer->id,
            'biller_plan' => $this->planName,
            'biller_plan_id' => $this->planId,
            'ends_at' => $endsAt,
            'trial_ends_at' => $trialEndsAt,
            'payment_method' => $paymentData['payment_type'],
            'status' => $status,
            'bank_slip_url'=>$bankSlipUrl,
            'billing_at' => $subscription->start_at
        ]);

        if($this->trialDays){
            $this->owner->trial_ends_at = $trialEndsAt;
            $this->owner->save();
        }

        return $ownerSubscription;
    }

    /**
     * Cancela assinaturas anteriores na Vindi e no banco local
     */
    protected function clearUsersSubscriptions()
    {
        $subscriptions = $this->owner->billerSubscriptions();
        // Busca as subscriptions anteriores do usuário
        foreach ($subscriptions as $sub) {
            if ($sub->status != 'canceled') {
                BillingGateway::deleteSubscription($sub->id);
            }
        }
        $this->owner->subscriptions()->where('user_id', $this->owner->id)->update(['status' => 'canceled']);
    }

    /**
     * Get the Vindi customer instance for the current user and token.
     *
     * @param  string|null $token
     * @param  array       $options
     * @return \Vindi\Customer
     */
    protected function getBillerCustomer($paymentData = null, array $options = [])
    {

        if (! $this->owner->biller_id) {
            $customer = $this->owner->createCustomer($paymentData, $options);
        } else {
            $customer = $this->owner->asBillerCustomer();
        }

        return $customer;
    }

    /**
     * Build the payload for subscription creation.
     *
     * @return array
     */
    protected function buildPayload($customer, $paymentData, $product)
    {
        $productItems = [
            'product_id' => $product,
            'discounts' => []
        ];

        if (count($this->discount)) {
            $productItems['discounts'] = [(Object)$this->discount];
        }


        $payload = [
            'plan_id' => $this->planId,
            'customer_id' => $customer->id,
            'payment_method_code' => $paymentData['payment_type'],
            'billing_trigger_day' => $this->billingTriggerDay,
            'product_items' => [(Object)$productItems],
        ];

        if ($this->trialDays>0) {
            $start =  Carbon::now()->addDays($this->trialDays)->toDateTimeString();
            $payload['start_at'] = $start;

            $payload['period'] = [(Object)[
                'start_at' => $start
            ]];
        }
        return $payload;
    }



    /**
     * Get the tax percentage for the Vindi payload.
     *
     * @return int|null
     */
    protected function getTaxPercentageForPayload()
    {
        if ($taxPercentage = $this->owner->taxPercentage()) {
            return $taxPercentage;
        }
    }

    protected function getPaymentProfile($customer, $paymentData)
    {
        $paymentProfiles = BillingGateway::getPaymentProfile($customer->id);
        if (count($paymentProfiles)) {
            $paymentProfile = $paymentProfiles[0];
            $this->owner->cartao = $paymentProfile->payment_company->name;
            $this->owner->save();
        } else {
            $paymentProfile = BillingGateway::createPaymentProfile($customer, $paymentData);
        }

        return true;
    }
}
