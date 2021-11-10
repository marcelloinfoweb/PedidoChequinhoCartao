<?php

/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Funarbe\PedidoChequinhoCartao\Block\Adminhtml;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;

class PedidoChequinhoCartao extends \Magento\Backend\Block\Template
{
    private \Magento\Framework\HTTP\Client\Curl $_curl;
    private \Magento\Framework\Pricing\Helper\Data $_pricingHelper;
    private \Funarbe\SupermercadoEscolaApi\Api\IntegratorRmClienteFornecedorManagementInterface $_integratorRm;

    /**
     * Constructor
     *
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Pricing\Helper\Data $pricingHelper
     * @param \Funarbe\SupermercadoEscolaApi\Api\IntegratorRmClienteFornecedorManagementInterface $integratorRm
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        \Funarbe\SupermercadoEscolaApi\Api\IntegratorRmClienteFornecedorManagementInterface $integratorRm,
        array $data = []
    ) {
        $this->_curl = $curl;
        $this->_pricingHelper = $pricingHelper;
        $this->_integratorRm = $integratorRm;
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
        $limitecredito = $respLimit[0]['LIMITECREDITO'];

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
            $classificacao = $this->_integratorRm->getClassificacaoRmClienteFornecedor($customer_taxvat);
            $limiteDisponivel = $respLimitDisp[0]['LIMITEDISPONIVELCHEQUINHO'];

            $limitCredito = $this->_pricingHelper->currency($limitecredito, true, false);
            $limitCreditoDisponivel = $this->_pricingHelper->currency($limiteDisponivel, true, false);

            return "Limite: $limitCredito <br> Limite Disponível: $limitCreditoDisponivel<br> Classificação: " . $classificacao[0]['CAMPOALFAOP2'];
        }

        // TODO
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
         * @var \Magento\Store\Model\StoreManagerInterface $this- >_storeManager
         */
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();

        $curlRm = $this->_curl;
        $curlRm->get($baseUrl . "rest/V1/funarbe-supermercadoescolaapi/integrator-rm-cliente-fornecedor-limite-disponivel?cpf=" . $customer_taxvat . "&expand=LIMITEDISPONIVELCHEQUINHO&dataAbertura=" . $dataInicio . "&dataFechamento=" . $dataFinal);
        return json_decode($curlRm->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
