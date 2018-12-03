<?php

namespace Souldigital\Billing\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Souldigital\Billing\Billable;
use Symfony\Component\HttpFoundation\Response;
use Souldigital\Billing\Events\BillPaid;
use Souldigital\Billing\Events\SubscriptionCancelled;

class WebhookController extends Controller
{
    /**
     * Handle a Stripe webhook call.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent(), true);

        if (!count($payload)) {
            return response()->json(['status'=>false,'msg'=>'Nothing here.'],500);
        }

        if (! $this->isInTestingEnvironment() && ! $this->isAllowedEvent($payload['event']['type'])) {
            return;
        }

        $method = 'handle'.studly_case($payload['event']['type']);

        if (method_exists($this, $method)) {
            return $this->{$method}($payload);
        } else {
            return $this->missingMethod();
        }
    }

    /**
     * Handle a cancelled customer from a Stripe subscription.
     *
     * @param  array  $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleSubscriptionCanceled(array $payload)
    {
        if (empty($payload['event']['data']['subscription']['id']) || empty($payload['event']['data']['subscription']['customer']['id'])) {
            return response()->json(['status'=>false],500);
        }

        $user = $this->getUserByBillerId($payload['event']['data']['subscription']['customer']['id']);

        if ($user) {
            $user->subscriptions->filter(function ($subscription) use ($payload) {
                return $subscription->biller_id == $payload['event']['data']['subscription']['id'];
            })->each(function ($subscription) use ($user) {
                $subscription->markAsCancelled();
                event(new SubscriptionCancelled($user, $subscription));
            });
        }

        return new Response('Webhook Handled', 200);
    }

    /**
     * @param array $payload
     * @return mixed
     */
    protected function handleBillPaid(array $payload){
        if (empty($payload['event']['data']['bill']['period']) || empty($payload['event']['data']['bill']['customer'])) {
            return response()->json(['status'=>false],500);
        }

        $user = $this->getUserByBillerId($payload['event']['data']['bill']['customer']['id']);
        if(!$user){
            $user = $this->getUserByMail($payload['event']['data']['bill']['customer']['email']);
            if(!$user){
                return new Response('User not found', 500);
            }
        }
        event(new BillPaid($user, $payload['event']['data']['bill']));

        return new Response('Webhook Handled', 200);
    }
    /**
     * Get the billable entity instance by Biller Mail
     *
     * @param  string  $billerId
     * @return \Souldigital\Billing\Billable
     */
    protected function getUserByMail($mail)
    {
        $model = getenv('BILLING_MODEL') ?: config('billing.model');

       return (new $model)->where('email', $mail)->first();
    }

    /**
     * Get the billable entity instance by Biller ID.
     *
     * @param  string  $billerId
     * @return \Souldigital\Billing\Billable
     */
    protected function getUserByBillerId($billerId)
    {
        $model = getenv('BILLING_MODEL') ?: config('billing.model');

        $user = (new $model)->where('biller_id', $billerId)->first();
        if($user && !$user->hasSubscription()){
            $user->isActive(); // Força recuperação do plano
            return $user->fresh();
        }
        if(!$user){
            $user = (new $model)->where('customer_id', $billerId)->first();
            if($user){
            $user->biller_id = $billerId;
            $user->save();
            }

        }
        return $user;
    }

    /**
     * Verifica se o evento recebido encontra-se na lista de eventos permitidos;
     * @param $id
     * @return bool
     */
    protected function isAllowedEvent($event)
    {
        $allowedEvents = config('billing.allowed_events');
        if (!in_array($event, $allowedEvents) ){
            return false;
        }

        return true;
    }

    /**
     * Verify if billing is in the testing environment.
     *
     * @return bool
     */
    protected function isInTestingEnvironment()
    {
        $billing_env = getenv('BILLING_ENV') ?: config('billing.env');
        return $billing_env === 'testing';
    }

    /**
     * Handle calls to missing methods on the controller
     *
     * @param  array   $parameters
     * @return mixed
     */
    public function missingMethod($parameters = [])
    {
        return new Response('Method Missing', 404);
    }
}
