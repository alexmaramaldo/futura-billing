<?php

namespace Souldigital\Billing\Events;

use Illuminate\Queue\SerializesModels;

class BillPaid
{
    use SerializesModels;

    public $user;
    public $bill;

    public function __construct(\Illuminate\Database\Eloquent\Model $user, $bill)
    {
        $this->user = $user;
        $this->bill = $bill;
    }

}