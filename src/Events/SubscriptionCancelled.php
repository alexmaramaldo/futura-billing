<?php

namespace Souldigital\Billing\Events;

use Illuminate\Database\Eloquent\Model;
use Souldigital\Billing\Subscription;
use Illuminate\Queue\SerializesModels;

class SubscriptionCancelled
{
    use SerializesModels;

    public $user;
    public $subscription;

    public function __construct(Model $user, Subscription $subscription)
    {
        $this->user = $user;
        $this->subscription = $subscription;
    }

}