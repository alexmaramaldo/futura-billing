<?php

namespace Souldigital\Billing\Gateways\Vindi;

use Exception;
use Vindi\Plan as VindiPlan;
use Vindi\Subscription as VindiSubscription;
use Vindi\Customer as VindiCustomer;
use Vindi\PaymentProfile as VindiPaymentProfile;
use Vindi\Discount as VindiDiscount;
use Vindi\Bill as VindiBill;
use Souldigital\Billing\Contracts\BillingGateway;

class Gateway implements BillingGateway
{
    /**
     * @var VindiCustomer
     */
    private $customers;
    /**
     * @var VindiPlan
     */
    private $plans;

    /**
     * @var VindiPaymentProfile
     */
    private $paymentProfiles;

    /**
     * @var VindiSubscription
     */
    private $subscriptions;

    public function __construct($customers,$plans,$profiles,$subscriptions,$bill)
    {
        $this->customers = $customers;
        $this->plans = $plans;
        $this->paymentProfiles = $profiles;
        $this->subscriptions = $subscriptions;
        $this->bill = $bill;
    }

    /**
     * Retorna o id de um plano na vindi com base no nome do plano
     *
     * @param  string $planName
     * @return int
     * @throws Exception
     */
    public function getPlanId($planName)
    {
        if(is_int($planName)){
            return $planName;
        }

        $plan = $this->plans->all(['query'=>'name='.$planName]);

        if (!count($plan)) {
            throw new Exception("Não existe um plano com o nome informado");
        }

        return $plan[0]->id;
    }

    public function getCustomer($id)
    {
        $vindiCustomers = new VindiCustomer;
        return $vindiCustomers->retrieve($id);
    }

    /**
     * Retorna uma assinatura com base no id
     *
     * @param  int       vindiId
     * @return \stdClass
     */
    public function getSubscription($vindiId)
    {
        return $this->subscriptions->retrieve($vindiId);
    }

    /**
     * Retorna todas as assinaturas ativas com base em um id de usuário
     *
     * @param  int $vindiCustomerId
     * @return array
     */
    public function getSubscriptions($vindiCustomerId)
    {
        return $this->subscriptions->all(['query'=>'status=active customer_id='.$vindiCustomerId]);
    }

    /**
     * Remove uma assinatura com base em um id. No caso da Vindi a mesma receberá o status cancelado
     *
     * @param  int $vindiId
     * @return mixed
     */
    public function deleteSubscription($vindiId)
    {
        return $this->subscriptions->delete($vindiId);
    }

    /**
     * Cria uma assinatura na vindi com o payload informado
     *
     * @param  array $payload
     * @return \stdClass
     */
    public function createSubscription($payload)
    {
        $subscription = $this->subscriptions->create($payload);
        return json_decode((string)$this->subscriptions->getLastResponse()->getBody());
    }

    /**
     * Retorna um array de usuários da vindi que correspondam ao e-mail informado;
     *
     * @param  string $email
     * @return array
     */
    public function getCustomerByEmail($email)
    {
        return $this->customers->all(['query'=>'email='.$email]);
    }

    /**
     * Cria um cliente na vindi com o payload informado.
     *
     * @param  array $data
     * @return \stdClass
     */
    public function createCustomer($data)
    {
        return $this->customers->create($data);
    }

    public function updateCustomer($id,$data)
    {
        $this->customers->update($id,$data);
    }

    public function customerIsBankSlip($id,$value)
    {
        return $this->updateCustomer($id, [
            'metadata' =>[
                'boleto' => $value
            ]
        ]);

    }

    /**
     * Retorna um perfil de pagamento da vindi com base no id de cliente informado ou cria um perfil de pagamento caso não exista nenhum.
     *
     * @param  int   $customerId
     * @param  array $paymentData
     * @return \stdClass
     * @throws Exception
     */
    public function getPaymentProfile($customerId, $paymentData)
    {
        $paymentProfiles = $this->paymentProfiles->all(['query'=>'customer_id='.$customerId.' status=active type=PaymentProfile::CreditCard']);
        if (count($paymentProfiles) > 0) {
            $paymentProfile = $paymentProfiles[0];
            $paymentData['credit_card']['id'] = $paymentProfile->id;
        }

        $paymentProfile = $this->paymentProfiles->create(self::buildPaymentProfilePayload($customerId, $paymentData));

        $validPayment = $this->paymentProfiles->verify($paymentProfile->id);
        if ($validPayment->status !== 'success') {
            throw new \Exception($validPayment->gateway_message);
        }

        return $paymentProfile;
    }

    public function createDiscount($discount)
    {
        $vindiDiscounts = new VindiDiscount;

        return $vindiDiscounts->create($discount);
    }

    public function createBill($payload)
    {
        return $this->bill->create($payload);
    }

    /**
     * Prepara o payload para a criação de um perfil de pagamento.
     *
     * @param  int   $customerId
     * @param  array $data
     * @return array
     */
    protected function buildPaymentProfilePayload($customerId, $data)
    {
        $payload = [];
        if ($data['payment_type'] == 'credit_card') {
            $payload = [
                'holder_name' => $data['holder_name'],
                'card_expiration' => $data['card_expiration_month'] . '/' . $data['card_expiration_year'],
                'card_number' => $data['card_number'],
                'card_cvv' => $data['card_cvv'],
                'payment_method_code' => 'credit_card',
                'customer_id' => $customerId
            ];
        }

        return $payload;
    }

    public function checkRateLimit($serviceName)
    {
        if($serviceName == 'customers'){
            $service = $this->customers;
        }

        if(!$service->getLastResponse()){
            return;
        }
        try{
             $rate_limit = $service->getLastResponse()->getHeader('Rate-Limit-Remaining')[0];
            if($rate_limit <= 20) {
            $seconds = $service->getLastresponse()->getHeader('Rate-Limit-Reset')[0] - time() + 1;
            for ($i = $seconds; $i >= 1; $i--) {
                sleep(1);
            }
            }

        } catch(\Exception $ex){
            //
        }
    }
}
