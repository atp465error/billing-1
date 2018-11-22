<?php

namespace Ptuchik\Billing\Gateways;

use App\User;
use Braintree\CreditCard;
use Braintree\PayPalAccount;
use Currency;
use Exception;
use Omnipay\Common\Message\ResponseInterface;
use Omnipay\Omnipay;
use Ptuchik\Billing\Contracts\PaymentGateway;
use Ptuchik\Billing\Factory;
use Ptuchik\Billing\Models\Order;
use Ptuchik\Billing\Models\PaymentMethod;
use Request;
use Omnipay\Common\Message\RequestInterface;

/**
 * Class Braintree
 * @package Ptuchik\Billing\Gateways
 */
class Braintree implements PaymentGateway
{
    /**
     * @var \Omnipay\Common\GatewayInterface
     */
    protected $gateway;

    /**
     * @var array
     */
    protected $config;

    /**
     * App\User
     * @var
     */
    protected $user;

    /**
     * Braintree constructor.
     *
     * @param \App\User $user
     * @param array     $config
     */
    public function __construct(User $user, array $config = [])
    {
        $this->config = $config;
        $this->user = $user;
        $this->gateway = Omnipay::create(array_get($this->config, 'driver'));
        $this->setCredentials($user->isTester() ?: !empty(array_get($this->config, 'testMode')));
    }

    /**
     * Set credentials
     *
     * @param       $testMode
     */
    protected function setCredentials($testMode)
    {
        $this->gateway->setMerchantId(array_get($this->config, $testMode ? 'sandboxMerchantId' : 'merchantId'));
        $this->gateway->setPublicKey(array_get($this->config, $testMode ? 'sandboxPublicKey' : 'publicKey'));
        $this->gateway->setPrivateKey(array_get($this->config, $testMode ? 'sandboxPrivateKey' : 'privateKey'));
        $this->gateway->setTestMode($testMode);
    }

    /**
     * Create payment profile
     * @return mixed
     */
    public function createPaymentProfile()
    {
        $profile = $this->gateway->createCustomer()->setCustomerData($this->getCustomerData(false))->send()->getData();

        return $profile->customer->id;
    }

    /**
     * Update payment profile
     * @return mixed
     */
    public function updatePaymentProfile()
    {
        return $this->gateway->updateCustomer()->setCustomerId($this->user->paymentProfile)
            ->setCustomerData($this->getCustomerData())->send()->getData();
    }

    /**
     * Find customer by profile
     * @return mixed
     */
    public function findCustomer()
    {
        return $this->gateway->findCustomer($this->user->paymentProfile)->send()->getData();
    }

    /**
     * Create payment method
     *
     * @param string $token
     *
     * @return mixed
     * @throws \Exception
     */
    public function createPaymentMethod(string $token)
    {
        // Create a payment method on remote gateway
        $paymentMethod = $this->gateway->createPaymentMethod()
            ->setToken($token)
            ->setMakeDefault(true)
            ->setCustomerId($this->user->paymentProfile)
            ->send();

        if (!$paymentMethod->isSuccessful()) {
            throw new Exception($paymentMethod->getMessage());
        }

        // Parse the result and return the payment method instance
        return $this->parsePaymentMethod($paymentMethod->getData()->paymentMethod);
    }

    /**
     * Get payment methods
     * @return array
     */
    public function getPaymentMethods() : array
    {
        $paymentMethods = [];

        // Get user's all payment methods from gateway and parse the needed data to return
        foreach ($this->findCustomer()->paymentMethods as $gatewayPaymentMethod) {
            $paymentMethods[] = $this->parsePaymentMethod($gatewayPaymentMethod);
        }

        return $paymentMethods;
    }

    /**
     * Set default payment method
     *
     * @param string $token
     *
     * @return mixed
     * @throws \Exception
     */
    public function setDefaultPaymentMethod(string $token)
    {
        $setDefault = $this->gateway->updatePaymentMethod()->setToken($token)->setMakeDefault(true)->send();

        if (!$setDefault->isSuccessful()) {
            throw new Exception($setDefault->getMessage());
        }

        // Parse the result and return the payment method instance
        return $this->parsePaymentMethod($setDefault->getData()->paymentMethod);
    }

    /**
     * Delete payment method
     *
     * @param string $token
     *
     * @return mixed
     */
    public function deletePaymentMethod(string $token)
    {
        // Delete payment method from remote gateway
        return $this->gateway->deletePaymentMethod()->setToken($token)->send()->getData()->success;
    }

    /**
     * Get payment token
     * @return mixed
     */
    public function getPaymentToken()
    {
        // Get and return payment token for user's payment profile
        return $this->gateway->clientToken()->setCustomerId($this->user->paymentProfile)->send()->getToken();
    }

    /**
     * Purchase
     *
     * @param                                    $amount
     * @param string|null                        $description
     * @param \Ptuchik\Billing\Models\Order|null $order
     *
     * @return \Omnipay\Common\Message\ResponseInterface
     */
    public function purchase($amount, string $description = null, Order $order = null) : ResponseInterface
    {
        // If nonce is provided, create payment method and unset nonce
        if (Request::filled('nonce')) {
            $this->createPaymentMethod(Request::input('nonce'));
            Request::offsetUnset('nonce');
        }

        // Update customer profile
        $this->updatePaymentProfile();

        // Get payment gateway and set up purchase request with customer ID
        $purchaseData = $this->gateway->purchase()->setCustomerId($this->user->paymentProfile);

        // If existing payment method's token is provided, add paymentMethodToken attribute
        // to request
        if (Request::filled('token')) {
            $purchaseData->setPaymentMethodToken(Request::input('token'));
        }

        // Set purchase descriptor
        if ($description) {
            $purchaseData->setDescriptor($this->generateDescriptor($description));
        }

        // Set currency account if any
        if ($merchantId = array_get($this->config, 'currencies.'.Currency::getUserCurrency())) {
            $purchaseData->setMerchantAccountId($merchantId);
        }

        // Set transaction ID from $order if provided
        if ($order) {
            $purchaseData->setTransactionId($order->id);
        }

        // Set amount
        $purchaseData->setAmount($amount);

        // Finally charge user and return the gateway purchase response
        return $purchaseData->send();
    }

    /**
     * Void transaction
     *
     * @param string $reference
     *
     * @return mixed|string
     * @throws \Exception
     */
    public function void(string $reference)
    {
        $void = $this->gateway->void()->setTransactionReference($reference)->send();

        if (!$void->isSuccessful()) {
            throw new Exception($void->getMessage());
        }

        return $reference;
    }

    /**
     * Refund transaction
     *
     * @param string $reference
     *
     * @return mixed|string
     * @throws \Exception
     */
    public function refund(string $reference)
    {
        $refund = $this->gateway->refund()->setTransactionReference($reference)->send();

        if (!$refund->isSuccessful()) {
            throw new Exception($refund->getMessage());
        }

        return $reference;
    }

    /**
     * Generate transaction descriptor for payment gateway
     *
     * @param $descriptor
     *
     * @return array
     */
    protected function generateDescriptor($descriptor)
    {
        return [
            'name'  => env('TRANSACTION_DESCRIPTOR_PREFIX').'*'.strtoupper(substr($descriptor, 0,
                    21 - strlen(env('TRANSACTION_DESCRIPTOR_PREFIX')))),
            'phone' => env('TRANSACTION_DESCRIPTOR_PHONE'),
            'url'   => env('TRANSACTION_DESCRIPTOR_URL')
        ];
    }

    /**
     * Parse payment method from gateways
     *
     * @param $paymentMethod
     *
     * @return mixed
     */
    protected function parsePaymentMethod($paymentMethod)
    {
        // Define payment method parser
        switch (get_class($paymentMethod)) {
            case CreditCard::class:
                $parser = 'parseBraintreeCreditCard';
                break;
            case PayPalAccount::class:
                $parser = 'parseBraintreePayPalAccount';
                break;
            default:
                break;
        }

        // Return parsed result
        return $this->{$parser}($paymentMethod);
    }

    /**
     * Parse Credit Card from Braintree response
     *
     * @param $creditCard
     *
     * @return object
     */
    protected function parseBraintreeCreditCard($creditCard)
    {
        $paymentMethod = Factory::get(PaymentMethod::class, true);
        $paymentMethod->token = $creditCard->token;
        $paymentMethod->type = 'credit_card';
        $paymentMethod->default = $creditCard->default;
        $paymentMethod->gateway = 'braintree';
        $paymentMethod->description = $creditCard->cardType.' '.trans(config('ptuchik-billing.translation_prefixes.general').'.ending_in').' '.$creditCard->last4;
        $paymentMethod->imageUrl = $creditCard->imageUrl;
        $paymentMethod->holder = $creditCard->cardholderName;

        return $paymentMethod;
    }

    /**
     * Parse PayPal Account from Braintree response
     *
     * @param $payPalAccount
     *
     * @return object
     */
    protected function parseBraintreePayPalAccount($payPalAccount)
    {
        $paymentMethod = Factory::get(PaymentMethod::class, true);
        $paymentMethod->token = $payPalAccount->token;
        $paymentMethod->type = 'paypal_account';
        $paymentMethod->default = $payPalAccount->default;
        $paymentMethod->gateway = 'braintree';
        $paymentMethod->description = $payPalAccount->email;
        $paymentMethod->imageUrl = $payPalAccount->imageUrl;
        $paymentMethod->holder = $payPalAccount->email;

        return $paymentMethod;
    }

    /**
     * Create address
     *
     * @param array $billingDetails
     */
    protected function createAddress(array $billingDetails)
    {
        return $address = $this->gateway->createAddress()->setCustomerId($this->user->paymentProfile)
            ->setCustomerData($this->getBillingData($billingDetails))->send();
    }

    /**
     * Update address
     *
     * @param array $billingDetails
     *
     * @return mixed
     */
    protected function updateAddress($id, array $billingDetails)
    {
        return $address = $this->gateway->updateAddress()->setCustomerId($this->user->paymentProfile)
            ->setBillingAddressId($id)->setCustomerData($this->getBillingData($billingDetails))->send();
    }

    /**
     * Get customer data
     * @return array
     */
    protected function getCustomerData($addAddress = true)
    {
        // Add billing details
        $billingDetails = $this->user->billingDetails;

        if ($addAddress) {

            $customer = $this->findCustomer();
            if (empty($customer->addresses)) {
                $this->createAddress($billingDetails);
            } elseif ($address = array_first($customer->addresses)) {
                $this->updateAddress($address->id, $billingDetails);
            }
        }

        return [
            'firstName' => $this->user->firstName,
            'lastName'  => $this->user->lastName,
            'email'     => $this->user->email,
            'company'   => $company = array_get($billingDetails, 'companyName', '')
        ];
    }

    /**
     * Get billing data
     *
     * @param array $billingDetails
     *
     * @return array
     */
    protected function getBillingData(array $billingDetails)
    {
        $data = [
            'firstName'     => $this->user->firstName,
            'lastName'      => $this->user->lastName,
            'company'       => array_get($billingDetails, 'companyName', ''),
            'streetAddress' => array_get($billingDetails, 'street', ''),
            'postalCode'    => array_get($billingDetails, 'zipCode', ''),
            'locality'      => array_get($billingDetails, 'city', ''),
        ];

        if ($country = array_get($billingDetails, 'country')) {
            if (strlen($country) == 2) {
                $data['countryCodeAlpha2'] = strtoupper($country);
            } else {
                $data['countryName'] = $country;
            }
        }

        return $data;
    }
}