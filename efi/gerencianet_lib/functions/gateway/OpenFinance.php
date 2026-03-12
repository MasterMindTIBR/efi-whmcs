<?php

use Gerencianet\Gerencianet;
use Gerencianet\Exception\GerencianetException;
use Illuminate\Database\Capsule\Manager as Capsule;

class OpenFinanceEfi
{
    private array $params;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function startOFPayment($api_instance, $gatewayParams)
    {
        $pagador = [
            'idParticipante' => $gatewayParams['paramsOF']['favoredbankOF'],
            'cpf' => str_replace(['.', '-', '/'], '', $gatewayParams['paramsOF']['favoredDocumentOF']),
        ];

        if (!empty($gatewayParams['paramsOF']['favoredPJDocumentOF'])) {
            $pagador['cnpj'] = str_replace(['.', '-', '/'], '', $gatewayParams['paramsOF']['favoredPJDocumentOF']);
        }

        $body = [
            'pagador' => $pagador,
            'favorecido' => [
                'contaBanco' => [
                    'codigoBanco' => '09089356',
                    'nome'        => $gatewayParams['nome'],
                    'documento'   => $gatewayParams['documento'],
                    'conta'       => $gatewayParams['conta'],
                    'tipoConta'   => $gatewayParams['tipoConta'],
                    'agencia'     => $gatewayParams['agencia'],
                ],
            ],
            'valor'     => (string) $gatewayParams['amount'],
            'idProprio' => (string) $gatewayParams['invoiceid'],
        ];

        try {
            return $api_instance->ofStartPixPayment([], $body);
        } catch (GerencianetException $e) {
            throw new \Exception($e->errorDescription ?? 'Erro ao iniciar pagamento Open Finance', 0, $e);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
    }

    public function validateRequiredParamsOF(array $gatewayParams): void
    {
        $requiredParams = [
            'clientIdProd'        => $gatewayParams['clientIdProd'],
            'clientSecretProd'    => $gatewayParams['clientSecretProd'],
            'clientIdSandbox'     => $gatewayParams['clientIdSandbox'],
            'clientSecretSandbox' => $gatewayParams['clientSecretSandbox'],
            'nome'                => $gatewayParams['nome'],
            'documento'           => $gatewayParams['documento'],
            'conta'               => $gatewayParams['conta'],
            'tipoConta'           => $gatewayParams['tipoConta'],
            'agencia'             => $gatewayParams['agencia'],
            'invoiceid'           => $gatewayParams['invoiceid'],
        ];

        $errors = [];

        foreach ($requiredParams as $key => $value) {
            if (empty($value)) {
                $errors[] = "{$key} é um campo obrigatório.";
            }
        }

        if (!empty($errors)) {
            throw new \Exception(implode(' ', $errors));
        }
    }

    public function updateConfigOpenFinance(): void
    {
        try {
            $apiInstance = new Gerencianet($this->getOptionsApiInstance());
            $requestBody = $this->getBodyConfigOpenFinance();

            $apiInstance->ofConfigUpdate([], $requestBody);
        } catch (\Throwable $th) {
            logActivity(
                'EFI Open Finance error | ' .
                    get_class($th) . ' | ' .
                    $th->getFile() . ':' . $th->getLine()
            );

            $message = trim((string) $th->getMessage());

            if ($th instanceof GerencianetException && !empty($th->error['message'])) {
                $message = $th->error['message'];
            }

            if ($message === '') {
                $message = 'Falha ao validar a configuração do Open Finance.';
            }

            throw new \Exception($message, (int) $th->getCode(), $th);
        }
    }

    public static function createEfiOFTable(): void
    {
        $tableName = 'tblefiopenfinance';

        if (!Capsule::schema()->hasTable($tableName)) {
            Capsule::schema()->create($tableName, function ($table) {
                $table->integer('invoiceid')->primary();
                $table->string('identificadorPagamento')->unique();
                $table->string('e2eid');
            });
        }
    }

    public function storePaymentIdentifier(string $paymentID, int $invoiceID): void
    {
        try {
            $exists = Capsule::table('tblefiopenfinance')
                ->where('invoiceid', $invoiceID)
                ->exists();

            if ($exists) {
                Capsule::table('tblefiopenfinance')
                    ->where('invoiceid', $invoiceID)
                    ->update(['identificadorPagamento' => $paymentID]);
            } else {
                Capsule::table('tblefiopenfinance')->insert([
                    'invoiceid' => $invoiceID,
                    'identificadorPagamento' => $paymentID,
                    'e2eid' => '',
                ]);
            }
        } catch (\Throwable $th) {
            logActivity(
                'EFI Open Finance DB error | ' .
                    get_class($th) . ' | ' .
                    $th->getFile() . ':' . $th->getLine()
            );
        }
    }

    private function getBodyConfigOpenFinance(): array
    {
        $systemUrl = rtrim($this->params['systemurl'], '/') . '/';

        $callbackUrl = $systemUrl . 'modules/gateways/callback/efi/openFinance.php';
        $redirectUrl = $systemUrl . 'modules/gateways/efi/gerencianet_lib/html/redirect.php';

        if (($this->params['mtls'] ?? '') === 'on') {
            $webhookSecurity = ['type' => 'mtls'];
        } else {
            $hash = hash('sha256', $this->params['clientIdProd']);
            $webhookSecurity = ['type' => 'hmac', 'hash' => $hash];
        }

        return [
            'webhookURL'      => $callbackUrl,
            'redirectURL'     => $redirectUrl,
            'webhookSecurity' => $webhookSecurity,
        ];
    }

    private function getOptionsApiInstance(): array
    {
        $sandbox = ($this->params['sandbox'] ?? '') === 'on';
        $debug   = ($this->params['debug'] ?? '') === 'on';
        $mtls    = ($this->params['mtls'] ?? '') === 'on';

        return [
            'client_id'     => $sandbox ? $this->params['clientIdSandbox'] : $this->params['clientIdProd'],
            'client_secret' => $sandbox ? $this->params['clientSecretSandbox'] : $this->params['clientSecretProd'],
            'certificate'   => $this->params['pixCert'],
            'sandbox'       => $sandbox,
            'debug'         => $debug,
            'headers'       => [
                'x-skip-mtls-checking' => $mtls ? 'false' : 'true',
            ],
        ];
    }
}
