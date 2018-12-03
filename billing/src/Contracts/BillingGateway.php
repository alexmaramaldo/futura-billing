<?php

namespace Souldigital\Billing\Contracts;

interface BillingGateway
{
    public function getPlanId($planName);

    public function getSubscription($serviceSubscriptionId);

    public function getSubscriptions($customerId);

    public function deleteSubscription($serviceSubscriptionId);

    public function createSubscription($payload);

    public function getCustomer($id);

    public function getCustomerByEmail($email);

    public function createCustomer($payload);
}
