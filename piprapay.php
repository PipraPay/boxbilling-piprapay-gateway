<?php
/**
 * PipraPay
 *
 * @copyright PipraPay (https://www.piprapay.com)
 * @license   Apache-2.0
 *
 * Copyright PipraPay
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */


class Payment_Adapter_piprapay implements \Box\InjectionAwareInterface
{
    protected $di;
    protected $config = [];

    public function setDi($di) { $this->di = $di; }
    public function getDi() { return $this->di; }

    public function __construct($config)
    {
        $this->config    = $config;
        if (empty($config['api_key']) || empty($config['api_url']) || empty($config['currency'])) {
            throw new Payment_Exception('PipraPay module is misconfigured. Please provide API Key, API URL, and currency.');
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => 'Accept PipraPay payments',
            'form' => [
                'api_key'   => ['text', ['label'=>'API Key:']],
                'api_url'   => ['text', ['label'=>'API Base URL:']],
                'currency'  => ['text', ['label'=>'Currency Code (BDT / USD):']],
                'auto_redirect' => ['checkbox', ['label'=>'Auto submit payment form']],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id)
    {
        $invoice = $api_admin->invoice_get(['id'=>$invoice_id]);
        $data    = $this->buildChargeData($invoice);
        $url     = $this->createCharge($data);

        $form = '<form action="'. $url .'" method="GET">';
        $form .= '<input type="submit" value="Pay via PipraPay" class="bb-button bb-button-submit">';
        $form .= '</form>';
        if (!empty($this->config['auto_redirect'])) {
            $form .= "<script>document.forms[0].submit();</script>";
        }
        return $form;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $logFile = BB_PATH_ROOT . '/piprapay_ipn_log.txt';
        file_put_contents($logFile, "IPN Received: " . json_encode($data) . "\n", FILE_APPEND);
    
        // Fallback: decode from raw_post_data if POST is empty
        $ipn = $data['post'] ?? [];
        if (empty($ipn) && !empty($data['http_raw_post_data'])) {
            $ipn = json_decode($data['http_raw_post_data'], true);
            file_put_contents($logFile, "Decoded raw_post_data: " . json_encode($ipn) . "\n", FILE_APPEND);
        }
    
        // Basic validation
        if (!isset($ipn['pp_id'], $ipn['status'], $ipn['metadata']['invoiceid'])) {
            file_put_contents($logFile, "Missing required fields in IPN.\n", FILE_APPEND);
            throw new Payment_Exception('Invalid IPN');
        }
    
        if ($this->isIpnDuplicate($ipn)) {
            file_put_contents($logFile, "Duplicate IPN Detected.\n", FILE_APPEND);
            throw new Payment_Exception('Duplicate IPN');
        }
    
        // Verify payment with PipraPay API
        $verify = $this->verifyPayment($ipn['pp_id']);
        file_put_contents($logFile, "Verification Result: " . json_encode($verify) . "\n", FILE_APPEND);
    
        if (isset($verify['status']) && strtolower($verify['status']) === 'completed') {
            $invoiceId = $verify['metadata']['invoiceid'] ?? null;
            if (!$invoiceId) {
                file_put_contents($logFile, "Invoice ID missing in verification metadata.\n", FILE_APPEND);
                throw new Payment_Exception('Missing invoice ID');
            }
    
            $invoice     = $this->di['db']->getExistingModelById('Invoice', $invoiceId, 'Invoice not found');
            $transaction = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');
            $tx_service  = $this->di['mod_service']('Invoice', 'Transaction');
    
            $tx_data = [
                'txn_id'     => $verify['transaction_id'],
                'amount'     => $verify['amount'],
                'currency'   => $this->config['currency'],
                'txn_status' => $verify['status'],
                'type'       => $verify['payment_method'],
                'status'     => 'complete',
            ];
    
            $tx_service->update($transaction, $tx_data);
            file_put_contents($logFile, "Transaction Updated: " . json_encode($tx_data) . "\n", FILE_APPEND);
    
            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
            $this->di['mod_service']('client')->addFunds($client, $verify['amount'], 'PipraPay payment');
            $this->di['mod_service']('Invoice')->payInvoiceWithCredits($invoice);
    
            file_put_contents($logFile, "Payment completed successfully.\n", FILE_APPEND);
            return true;
        }
    
        file_put_contents($logFile, "Payment verification failed.\n", FILE_APPEND);
        throw new Payment_Exception('Payment verification failed.');
    }

    protected function buildChargeData($invoice)
    {
        return [
            'full_name'     => trim($invoice['client']['first_name'].' '.$invoice['client']['last_name']),
            'email_mobile'  => $invoice['client']['email'],
            'amount'        => (string)$invoice['total'],
            'currency'      => $this->config['currency'],
            'metadata'      => ['invoiceid'=>$invoice['id']],
            'redirect_url'  => $this->config['return_url'],
            'cancel_url'    => $this->config['cancel_url'],
            'webhook_url'   => $this->config['notify_url'],
            'return_type'   => 'POST',
        ];
    }

    protected function createCharge($data)
    {
        $response = $this->curlPost($this->config['api_url'].'/api/create-charge', $data);
        if (empty($response['pp_url'])) {
            throw new Payment_Exception('Failed to create charge: '.($response['message']??'Unknown'));
        }
        return $response['pp_url'];
    }

    protected function verifyPayment($pp_id)
    {
        $response = $this->curlPost($this->config['api_url'].'/api/verify-payments', ['pp_id'=>$pp_id]);
        if (empty($response) || !isset($response['status'])) {
            throw new Payment_Exception('Invalid verification response');
        }
        return $response;
    }

    protected function curlPost($url, $body)
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'mh-piprapay-api-key: '.$this->config['api_key'],
            ],
        ]);
        $resp = curl_exec($curl);
        $err  = curl_error($curl);
        curl_close($curl);
        if ($err) {
            throw new Payment_Exception('Connection error: '.$err);
        }
        return json_decode($resp, true);
    }

    protected function isIpnDuplicate($ipn)
    {
        $rows = $this->di['db']->getAll(
            'SELECT id FROM transaction WHERE txn_id = :tx ORDER BY id DESC LIMIT 1',
            [':tx'=>$ipn['pp_id']]
        );
        return !empty($rows);
    }
}
