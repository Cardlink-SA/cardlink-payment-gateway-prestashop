<?php

use Cardlink_Checkout\ApplePayHelper;

class Cardlink_CheckoutApplepayajaxModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function display()
    {
    }

    public function postProcess()
    {
        $action = Tools::getValue('apay_action', '');

        switch ($action) {
            case 'init':
                $this->handleInit();
                break;
            case 'createxid':
                $this->handleCreateXid();
                break;
            case 'signdata':
                $this->handleSignData();
                break;
            default:
                $this->jsonResponse(['error' => 'Invalid action'], 400);
                break;
        }
    }

    private function handleInit()
    {
        try {
            $initData = ApplePayHelper::getScriptInitData();

            $this->jsonResponse([
                'success' => true,
                'mid' => $initData['mid'],
                'queryString' => $initData['queryString'],
                'vposVersion' => $initData['vposVersion'],
            ]);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Apple Pay init error: ' . $e->getMessage(), 3);
            $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to initialize Apple Pay',
            ], 500);
        }
    }

    private function handleCreateXid()
    {
        try {
            $body = file_get_contents('php://input');
            $data = json_decode($body, true);

            $trId = $data['trId'] ?? '';
            $trExtId = $data['trExtId'] ?? '';
            $trMpiCounts = $data['trMpiCounts'] ?? '';

            if (empty($trId)) {
                $this->textResponse('Missing trId', 400);
                return;
            }

            $xid = ApplePayHelper::calculateXID($trId, $trExtId, $trMpiCounts);
            $this->textResponse($xid);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Apple Pay createXid error: ' . $e->getMessage(), 3);
            $this->textResponse('Error calculating XID', 500);
        }
    }

    private function handleSignData()
    {
        try {
            $body = file_get_contents('php://input');
            $data = json_decode($body, true);

            if (!$data) {
                $this->jsonResponse(['error' => 'Invalid JSON'], 400);
                return;
            }

            $result = ApplePayHelper::signMpiData($data);

            if (strpos($result['signature'], 'Error') === 0) {
                PrestaShopLogger::addLog('Cardlink Apple Pay sign error: ' . $result['signature'], 3);
                $this->jsonResponse(['error' => $result['signature']], 500);
                return;
            }

            $this->jsonResponse($result);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Apple Pay signData error: ' . $e->getMessage(), 3);
            $this->jsonResponse(['error' => 'Internal error signing data'], 500);
        }
    }

    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }

    private function textResponse(string $text, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $text;
        exit;
    }
}
