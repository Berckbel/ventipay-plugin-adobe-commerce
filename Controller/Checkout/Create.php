<?php
namespace VentiPay\Gateway\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;

class Create extends Action
{
    protected $resultJsonFactory;
    protected $orderRepository;
    protected $curl;
    protected $config;
    protected $request;
    protected $url;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request,
        UrlInterface $url
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->curl = $curl;
        $this->config = $scopeConfig;
        $this->request = $request;
        $this->url = $url;
        parent::__construct($context);
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if ($this->request->getMethod() !== 'POST') {
          return $resultJson->setData([
            'message' => 'route_not_found'
          ])->setHttpResponseCode(404);
        };

        $rawBody = $this->request->getContent();
        $data = json_decode($rawBody, true);

        if (!isset($data['orderId'])) {
          return $resultJson->setData([
            'message' => 'orderid_is_required'
          ])->setHttpResponseCode(400);
        }

        $orderId = $data['orderId'];

        $order = null;

        try {
          $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
          return $resultJson->setData([
            'message' => 'order_not_found'
          ])->setHttpResponseCode(404);
        }

        $orderStatus = $order->getStatus();

        // TODO: remove payment_review
        if ($orderStatus !== 'pending' and $orderStatus !== 'payment_review') {
          return $resultJson->setData([
            'message' => 'order_status_' . $orderStatus
          ])->setHttpResponseCode(400);
        }

        $apiKey = $this->config->getValue(
          'payment/ventipay_gateway/merchant_gateway_key',
          \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
          $order->getStoreId()
        );

        $currency = strtolower($order->getOrderCurrencyCode());
        $totalAmount = $order->getGrandTotal();

        $orderData = [
          'authorize' => true,
          'currency' => $currency,
          'items' => array([
            'name' => 'magento',
            'unit_price' => $currency === 'usd' ? $totalAmount * 100 : $totalAmount,
            'quantity' => 1
          ]),
          'metadata' => [
            'order_id' => $orderId,
            'increment_id' => $order->getIncrementId(),
            'customer_email' => $order->getCustomerEmail(),
            'billing_address' => $order->getBillingAddress()->getData(),
          ],
          'cancel_url' => $this->url->getUrl(''),
          'cancel_url_method' => 'get',
          'success_url' => $this->url->getUrl('payment_ventipay/payment/success?orderId=' . $orderId),
          'success_url_method' => 'get',
          'notification_url' => $this->url->getUrl('payment_ventipay/webhooks/checkout?orderId=' . $orderId),
          'notification_events' => ['checkout.paid']
        ];

        $this->curl->setCredentials($apiKey, '');
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post(
          'https://api.ventipay.com/v1/checkouts',
          json_encode($orderData)
        );
        
        $body = $this->curl->getBody();
        $response = json_decode($body);

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('ventipay_checkout_id', $response->id);
        $payment->save();

        $order->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $this->orderRepository->save($order);

        return $resultJson->setData([
          'id' => $response->id,
          'url' => $response->url
        ]);
    }
}