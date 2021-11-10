<?php

/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Funarbe\PedidoChequinhoCartao\Block\Adminhtml;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Sales\Model\Order;

class PedidoChequinhoCartao extends \Magento\Backend\Block\Template
{
    /**
     * Constructor
     *
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param \Magento\Backend\Block\Template\Context $context
     * @param array $data
     */
    public function __construct(
        Curl $curl,
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        array $data = []
    ) {
        $this->_curl = $curl;
        $this->pricingHelper = $pricingHelper;
        parent::__construct($context, $data);
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \JsonException
     */
    public function execute()
    {
        $orderId = $this->_request->getParam('order_id');
        $objectManager = ObjectManager::getInstance();
        $order = $objectManager->create(Order::class)->load($orderId);
        $payment = $order->getPayment()->getMethod();
        $customer_taxvat = $order->getCustomerTaxvat();

        /**
         * @var \Magento\Store\Model\StoreManagerInterface $this- >_storeManager
         */
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();
        $urlControle = 'https://controle.supermercadoescola.org.br/site';
        $urlApiLimite = 'rest/V1/funarbe-supermercadoescolaapi/integrator-rm-cliente-fornecedor';

        $curlRm = $this->_curl;
        $curlRm->get($baseUrl . $urlApiLimite . "?cpf=" . $customer_taxvat);
        $respLimit = json_decode($curlRm->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $matricula = $respLimit[0]['CAMPOLIVRE'];

        $curlAberturaPontoUfv = $this->_curl;
        $curlAberturaPontoUfv->get($urlControle . "/abertura-ponto-ufv-api");
        $responseAberturaPontoUfv = json_decode($curlAberturaPontoUfv->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $curlAberturaPontoFnb = $this->_curl;
        $curlAberturaPontoFnb->get($urlControle . "/abertura-ponto-fnb-api");
        $responseAberturaPontoFnb = json_decode($curlAberturaPontoFnb->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if ($payment === 'chequinho_se' && (strpos($matricula, 'F') === 0)) {
            $dataInicio = $responseAberturaPontoFnb['data_inicio'];
            $dataFinal = $responseAberturaPontoFnb['data_final'];

            $respLimitDisp = $this->LimiteDisponivel($customer_taxvat, $dataInicio, $dataFinal);

            $limitCredito = $this->pricingHelper->currency($respLimit[0]['LIMITECREDITO'], true, false);
            $limitCreditoDisponivel = $this->pricingHelper->currency($respLimitDisp[0]['LIMITEDISPONIVELCHEQUINHO'], true, false);

            return "Limite: $limitCredito <br> Limite Disponível: $limitCreditoDisponivel";
        }

        // if ($payment === 'cartao_se') {
        //     return 'Cartão Alimentação';
        // }
        // return false;
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \JsonException
     */
    public function LimiteDisponivel($customer_taxvat, $dataInicio, $dataFinal)
    {
        /**
         * @var \Magento\Store\Model\StoreManagerInterface $this->_storeManager
         */
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();

        $curlRm = $this->_curl;
        $curlRm->get($baseUrl . "rest/V1/funarbe-supermercadoescolaapi/integrator-rm-cliente-fornecedor-limite-disponivel?cpf=" . $customer_taxvat . "&expand=LIMITEDISPONIVELCHEQUINHO&dataAbertura=" . $dataInicio . "&dataFechamento=" . $dataFinal);
        return json_decode($curlRm->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
