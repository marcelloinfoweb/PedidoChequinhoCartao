<?php

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
     * @return string|void
     * @throws \JsonException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $orderId = $this->_request->getParam('order_id');
        $order = ObjectManager::getInstance()->create(Order::class)->load($orderId);
        $payment = $order->getPayment()->getMethod();
        $customerTaxvat = $order->getCustomerTaxvat();

        /**
         * @var \Magento\Store\Model\StoreManagerInterface $this- >_storeManager
         */
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();
        $urlControle = 'https://controle.supermercadoescola.org.br/site';
        $urlApiLimite = 'rest/V1/funarbe-supermercadoescolaapi/integrator-rm-cliente-fornecedor';

        $curlRm = $this->_curl;
        $curlRm->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curlRm->get($baseUrl . $urlApiLimite . "?cpf=" . $customerTaxvat);
        $respLimit = json_decode($curlRm->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (isset($respLimit[0]['CAMPOLIVRE'])) {
            $matricula = $respLimit[0]['CAMPOLIVRE'];
            $limiteCredito = $respLimit[0]['LIMITECREDITO'];

            $curlAberturaPontoUfv = $this->_curl;
            $curlAberturaPontoUfv->get($urlControle . "/abertura-ponto-ufv-api");
            //$responseAberturaPontoUfv = json_decode($curlAberturaPontoUfv->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $curlAberturaPontoFnb = $this->_curl;
            $curlAberturaPontoFnb->get($urlControle . "/abertura-ponto-fnb-api");
            $responseAberturaPontoFnb = json_decode($curlAberturaPontoFnb->getBody(), true);

            if ($payment === 'chequinho_se' && (strpos($matricula, 'F') === 0)) {
                $dataInicio = $responseAberturaPontoFnb['data_inicio'];
                $dataFinal = $responseAberturaPontoFnb['data_final'];

                $respLimitDisp = $this->limiteDisponivel($customerTaxvat, $dataInicio, $dataFinal);
                $classificacao = $this->_integratorRm->getClassificacaoRmClienteFornecedor($customerTaxvat);
                $limiteDisponivel = $respLimitDisp[0]['LIMITEDISPONIVELCHEQUINHO'];

                $limitCredito = $this->_pricingHelper->currency($limiteCredito, true, false);
                $limitCreditoDisponivel = $this->_pricingHelper->currency($limiteDisponivel, true, false);

                return "Limite: $limitCredito <br> Limite Disponível: $limitCreditoDisponivel<br> Classificação: " .
                    $classificacao[0]['CAMPOALFAOP2'];
            }
        }
    }

    /**
     * @param $customerTaxvat
     * @param $dataInicio
     * @param $dataFinal
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function limiteDisponivel($customerTaxvat, $dataInicio, $dataFinal)
    {
        /**
         * @var \Magento\Store\Model\StoreManagerInterface $this->_storeManager
         */
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();

        $curlRm = $this->_curl;
        $curlRm->get($baseUrl .
            "rest/V1/funarbe-supermercadoescolaapi/integrator-rm-cliente-fornecedor-limite-disponivel?cpf=" .
            $customerTaxvat . "&expand=LIMITEDISPONIVELCHEQUINHO&dataAbertura=" . $dataInicio . "&dataFechamento=" .
            $dataFinal);
        return json_decode($curlRm->getBody(), true);
    }
}
