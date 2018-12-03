<?php

namespace Souldigital\Billing;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use LogicException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Souldigital\Billing\Facades\GatewayFacade as BillingGateway;

class Subscription extends Model
{

    public static function boot()
    {
        parent::boot();

        static::created(function(Subscription $plano){
            if($plano->exists() && is_null($plano->name)){
                $plano->name = 'default';
                $plano->save();
            }
        });

        // A cada update de um plano, limpa o cache de svod de um usuário
        static::saved(function(Subscription $plano){
            Cache::forget($plano->user_id.'_svod');
            if($plano->name == '' || is_null($plano->name)){
                $plano->name = 'default';
            }
        });

    }

    public function getPeriodicidadeAttribute()
    {
       if(is_null($this->biller_plan_id)){
         return '';
       }
       switch ($this->biller_plan_id){
           case 14649:
               return 'mensal';
           case 14651:
               return 'semestral';
           case 14652:
               return 'anual';
           default:
               return '';
       }
    }

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'trial_ends_at', 'ends_at',
        'canceled_at',
        'created_at', 'updated_at',
    ];

    /**
     * Indicates if the plan change should be prorated.
     *
     * @var bool
     */
    protected $prorate = true;

    /**
     * The date on which the billing cycle should be anchored.
     *
     * @var string|null
     */
    protected $billingCycleAnchor = null;

    /**
     * Get the user that owns the subscription.
     */
    public function user()
    {
        return $this->owner();
    }

    public function usuario()
    {
        return $this->user();
    }

    /**
     * Get the model related to the subscription.
     */
    public function owner()
    {
        $model = getenv('BILLING_MODEL') ?: config('billing.model', 'App\\User');

        $model = new $model;

        return $this->belongsTo(get_class($model), $model->getForeignKey());
    }

    /**
     * Determine if the subscription is active, on trial, or within its grace period.
     *
     * @return bool
     */
    public function valid()
    {
        return ($this->active()||$this->onTrial() || $this->onGracePeriod());
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active()
    {
        return (Carbon::now()->lessThanOrEqualTo($this->ends_at) && $this->status == 'active' || $this->onGracePeriod());
    }

    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return (! is_null($this->canceled_at) || $this->status == 'canceled');
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (! is_null($this->trial_ends_at)) {
            return Carbon::now()->lt($this->trial_ends_at);
        } else {
            return false;
        }
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        if (! is_null($endsAt = $this->canceled_at) && $this->status == 'canceled') {
            return Carbon::now()->lt(Carbon::instance($endsAt));
        } else {
            return false;
        }
    }

    /**
     * Indicate that the plan change should not be prorated.
     *
     * @return $this
     */
    public function noProrate()
    {
        $this->prorate = false;

        return $this;
    }

    /**
     * Change the billing cycle anchor on a plan change.
     *
     * @param  int|string $date
     * @return $this
     */
    public function anchorBillingCycleOn($date = 'now')
    {
        if ($date instanceof DateTimeInterface) {
            $date = $date->getTimestamp();
        }

        $this->billingCycleAnchor = $date;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * This method must be combined with swap, resume, etc.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->trial_ends_at = null;

        return $this;
    }

    /**
     * Cancel the subscription at the end of the billing period.
     *
     * @return $this
     */
    public function cancel()
    {
        $vindiSubscription = BillingGateway::getSubscription($this->biller_id);

        if($vindiSubscription->status != 'canceled'){
            BillingGateway::deleteSubscription($this->biller_id);
        }

        // If the user was on trial, we will set the grace period to end when the trial
        // would have ended. Otherwise, we'll retrieve the end of the billing period
        // period and make that the end of the grace period for this current user.
        if ($this->onTrial()) {
            $this->canceled_at = $this->trial_ends_at;
        } else {
            if($this->status == 'active'){
                $this->canceled_at =  $this->ends_at;
            } else{
                $this->canceled_at = Carbon::today()->format('Y-m-d H:i:s');
            }
        }

        $this->status = 'canceled';

        $this->save();

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     */
    public function cancelNow()
    {
        $subscription = $this->asBillerSubscription();

        $subscription->cancel();

        $this->markAsCancelled();

        return $this;
    }

    /**
     * Mark the subscription as cancelled.
     *
     * @return void
     */
    public function markAsCancelled()
    {
        $this->fill(['canceled_at' => Carbon::now(), 'status' => 'canceled'])->save();
        return $this;
    }

    /**
     * Resume the cancelled subscription.
     *
     * @return $this
     *
     * @throws \LogicException
     */
    public function resume()
    {
        if (! $this->onGracePeriod()) {
            throw new LogicException('Unable to resume subscription that is not within grace period.');
        }

        $subscription = $this->asProviderSubscription();

        // To resume the subscription we need to set the plan parameter on the Vindi
        // subscription object. This will force Vindi to resume this subscription
        // where we left off. Then, we'll set the proper trial ending timestamp.
        $subscription->plan = $this->biller_plan;

        if ($this->onTrial()) {
            $subscription->trial_end = $this->trial_ends_at->getTimestamp();
        } else {
            $subscription->trial_end = 'now';
        }

        //$subscription->save();

        // Finally, we will remove the ending timestamp from the user's record in the
        // local database to indicate that the subscription is active again and is
        // no longer "cancelled". Then we will save this record in the database.
        $this->fill(['ends_at' => null])->save();

        return $this;
    }

    public function setDiscount($type, $value, $cycles)
    {
        $subscription = $this->asProviderSubscription();
        $discount = [
            'product_item_id' => $subscription->product_items[0]->id,
            'discount_type' => $type,
            'amount' => $value,
            'cycles' => $cycles
        ];

        BillingGateway::createDiscount($discount);

        return $this;
    }

    /**
     * Get the subscription as a Vindi subscription object.
     *
     * @return \Vindi\Subscription
     */
    public function asProviderSubscription()
    {
        return BillingGateway::getSubscription($this->biller_id);
    }
}
