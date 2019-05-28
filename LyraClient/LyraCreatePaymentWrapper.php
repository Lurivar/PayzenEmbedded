<?php
/*************************************************************************************/
/*      Copyright (c) Franck Allimant, CQFDev                                        */
/*      email : thelia@cqfdev.fr                                                     */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE      */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded\LyraClient;

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Tools\URL;

/**
 * A wrapper around CreatePayment service to manage bith Javascript Client and PCI-DSS calls
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 27/05/2019 17:33
 */

class LyraCreatePaymentWrapper extends LyraClientWrapper
{
    const PAYEMENT_STATUS_PAID = 1;
    const PAYEMENT_STATUS_NOT_PAID = 2;
    const PAYEMENT_STATUS_IN_PROGRESS = 3;

    /**
     * @var boolean
     */
    protected $oneClickEnabled;
    /**
     * @var Tlog
     */
    protected $log;
    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher, TLog $log = null)
    {
        parent::__construct();

        $this->oneClickEnabled = boolval(PayzenEmbedded::getConfigValue('allow_one_click_payments'));

        $this->log = null === $log ? Tlog::getInstance() : $log;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Build CreatePayement web service input parameters from the givent order, and call the service.
     *
     * @param Order $order
     *
     * @return array CreatePayement response
     *
     * @throws LyraException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function sendCreatePayementRequest(Order $order)
    {
        $currency = $order->getCurrency();
        $customer = $order->getCustomer();

        if ($this->oneClickEnabled) {
            $formAction = 'ASK_REGISTER_PAY';
        } else {
            $formAction = 'PAYMENT';
        }

        // Request parameters (see https://payzen.io/en-EN/rest/V4.0/api/playground.html?ws=Charge/CreatePayment)
        $store = [
            "amount" => intval(strval($order->getTotalAmount() * 100)),
            'contrib' => 'Thelia version ' . ConfigQuery::read('thelia_version'),
            'currency' => strtoupper($currency->getCode()),
            'orderId' => $order->getRef(),
            'formAction' => $formAction,

            'customer' => [
                'email' => $customer->getEmail(),
                'reference' => $customer->getRef()
            ],

            'strongAuthentication' => PayzenEmbedded::getConfigValue('strong_authentication', 'AUTO'),
            'ipnTargetUrl' => URL::getInstance()->absoluteUrl('/payzen-embedded/ipn-callback'),

            'transactionOptions' => [
                'cardOptions' => [
                    'captureDelay' => PayzenEmbedded::getConfigValue('capture_delay', 0),
                    'manualValidation' => PayzenEmbedded::getConfigValue('validation_mode', null) ?: null,
                    'paymentSource' => PayzenEmbedded::getConfigValue('payment_source', null) ?: null
                ]
            ],
        ];

        // Add 1-click payment token if we have one, and if it is allowed
        if ($this->oneClickEnabled && (null !== $tokenData = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()))) {
            $store['paymentMethodToken'] = $tokenData->getPaymentToken();
        }

        return $this->post("V4/Charge/CreatePayment", $store);
    }

    /**
     * Process a CreatePayment response and update the order accordingly.
     *
     * @param array $response a CreatePayment response
     * @return bool true if the payement is successful, false otherwise.
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function processPaymentResponse($response)
    {
        $status = self::PAYEMENT_STATUS_NOT_PAID;

        // Be sure to have transaction data.
        if (isset($response['transactions'])) {
            /* Retrieve the transaction id from the response data */
            $transaction = $response['transactions'][0];

            /* get some parameters from the answer */
            $orderStatus = $response['orderStatus'];
            $orderRef = $response['orderDetails']['orderId'];
            $transactionUuid = $transaction['uuid'];

            $this->log->addInfo(Translator::getInstance()->trans("Payzen platform request received for order %ref.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));

            if (null !== $order = $this->getOrderByRef($orderRef)) {
                // Store the transaction ID
                $event = new OrderEvent($order);
                $event->setTransactionRef($transactionUuid);
                $this->dispatcher->dispatch(TheliaEvents::ORDER_UPDATE_TRANSACTION_REF, $event);

                if ($orderStatus === 'PAID') {
                    if ($order->isPaid()) {
                        $this->log->addInfo(Translator::getInstance()->trans("Order %ref is already paid.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));
                    } else {
                        $this->log->addInfo(Translator::getInstance()->trans("Order %ref payment was successful.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));

                        // Payment OK !
                        $this->setOrderStatus($order, OrderStatusQuery::getPaidStatus());

                        // Check if customer has registered its card for 1-click payment
                        if (isset($transaction['paymentMethodToken']) && !empty($transaction['paymentMethodToken'])) {
                            if (null === $tokenData = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($order->getCustomerId())) {
                                $tokenData = (new PayzenEmbeddedCustomerToken())
                                    ->setCustomerId($order->getCustomerId());
                            }

                            // Update customer payment token
                            $tokenData
                                ->setPaymentToken($transaction['paymentMethodToken'])
                                ->save();
                        }

                        $status = self::PAYEMENT_STATUS_PAID;
                    }
                } else if ($orderStatus === 'UNPAID') {
                    $this->log->addInfo(Translator::getInstance()->trans("Order %ref payment was not successful.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));

                    // Cancel the order (be sure that the status is "not paid")
                    if ($order->getStatusId() !== OrderStatusQuery::getNotPaidStatus()->getId()) {
                        $this->setOrderStatus($order, OrderStatusQuery::getNotPaidStatus());
                    }
                } else if ($orderStatus === 'RUNNING' || $orderStatus === 'PARTIALLY_PAID') {
                    $this->log->addInfo(Translator::getInstance()->trans("Order %ref payment is in progress (%status).", ['%status' => $orderStatus, '%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));

                    // Be sure that the status is "not paid"
                    if ($order->getStatusId() !== OrderStatusQuery::getNotPaidStatus()->getId()) {
                        $this->setOrderStatus($order, OrderStatusQuery::getNotPaidStatus());
                    }

                    $status = self::PAYEMENT_STATUS_IN_PROGRESS;
                }
            }

            $this->log->info(Translator::getInstance()->trans("PayZen IPN request for order %ref processing teminated.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));
        }

        return $status;
    }


    /**
     * Get an order and issue a log message if not found.
     * @param string $orderReference
     * @return null|\Thelia\Model\Order
     */
    protected function getOrderByRef($orderReference)
    {
        if (null == $order = OrderQuery::create()->filterByRef($orderReference)->findOne()) {
            $this->log->addError(
                Translator::getInstance()->trans("Unknown order reference:  %ref", array('%ref' => $orderReference))
            );
        }

        return $order;
    }

    public function setOrderStatus(Order $order, OrderStatus $orderStatus)
    {
        $event = new OrderEvent($order);

        $event->setStatus($orderStatus->getId());

        $this->dispatcher->dispatch(TheliaEvents::ORDER_UPDATE_STATUS, $event);

        $this->log->addInfo(
            Translator::getInstance()->trans(
                "Order ref. %ref, ID %id has been successfully paid.",
                array('%ref' => $order->getRef(), '%id' => $order->getId())
            )
        );
    }
}
