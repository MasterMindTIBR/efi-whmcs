<?php

/**
 * Valida os campos do módulo na área de administração do WHMCS
 *
 * @param array $params
 * @throws Exception
 */
function ValidationFieldsAdmin($params)
{
    environment();
    tlsVersion();

    requiredFields($params);
    testAuthentication($params);

    if (!empty($params['activeBoleto']) && $params['activeBoleto'] === 'on') {
        requiredBilletFields($params);
    }

    if (!empty($params['activePix']) && $params['activePix'] === 'on') {
        requiredPixFields($params);
    }

    if (!empty($params['activeOpenFinance']) && $params['activeOpenFinance'] === 'on') {
        requiredOpenFinanceFields($params);
    }
}

function verifyEmptyValue($value, $fieldDescription)
{
    if (empty($value)) {
        generateException("O campo {$fieldDescription} não foi preenchido.");
    }
}

function environment()
{
    $https = $_SERVER['HTTPS'] ?? null;
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    $isLocalhost = strpos($referer, 'localhost') !== false;
    $isLoopback = strpos($referer, '127.0.0.1') !== false;

    if ($https !== 'on' || $isLocalhost || $isLoopback) {
        generateException(
            '<span style="line-height: 2;">Identificamos que o seu domínio não possui certificado de segurança HTTPS ou não é válido para registrar o Webhook!</span><br><br>'
        );
    }
}

function tlsVersion()
{
    $ch = curl_init('https://www.howsmyssl.com/a/check');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string) $response);

    $tlsVersionString = $data->tls_version ?? '';
    $tlsVersionParts = explode(' ', (string) $tlsVersionString);
    $tlsVersion = isset($tlsVersionParts[1]) ? (float) $tlsVersionParts[1] : 0.0;

    if (!($tlsVersion > 1.1)) {
        generateException(
            '<span style="line-height: 2;">Identificamos que a sua hospedagem não suporta uma versão segura do TLS (Transport Layer Security) para se comunicar com a Efí. Para conseguir gerar transações, será necessário que você contate o administrador do seu servidor e solicite a atualização para suportar TLS na versão mínima 1.2.</span><br><br>'
        );
    }
}

function requiredFields($params)
{
    $tests = [
        'Client_id produção' => $params['clientIdProd'] ?? null,
        'Client_secret produção' => $params['clientSecretProd'] ?? null,
        'Client_id sandbox' => $params['clientIdSandbox'] ?? null,
        'Client_secret sandbox' => $params['clientSecretSandbox'] ?? null,
        'identificador da conta' => $params['idConta'] ?? null,
        'administrador do whmcs' => $params['whmcsAdmin'] ?? null,
    ];

    foreach ($tests as $message => $value) {
        verifyEmptyValue($value, $message);
    }

    $activeBoleto = $params['activeBoleto'] ?? '';
    $activeCredit = $params['activeCredit'] ?? '';
    $activePix = $params['activePix'] ?? '';
    $activeOpenFinance = $params['activeOpenFinance'] ?? '';

    if ($activeBoleto !== 'on' && $activeCredit !== 'on' && $activePix !== 'on' && $activeOpenFinance !== 'on') {
        generateException('Nenhuma forma de pagamento ativa!');
    }
}

function requiredBilletFields($params)
{
    if (!empty($params['descontoBoleto'])) {
        $desconto = str_replace(',', '.', (string) $params['descontoBoleto']);
        if (!is_numeric($desconto)) {
            generateException('O campo desconto do boleto não foi preenchido corretamente.');
        }

        $desconto = (float) $desconto;
        if (($params['tipoDesconto'] ?? '') === '1' && $desconto >= 100) {
            generateException('Parece que você tentou aplicar um desconto no Boleto igual ou superior a 100%. Por favor, corrija o valor informado para conseguir salvar as configurações.');
        }
    }

    if (
        $params['numDiasParaVencimento'] === ''
        || $params['numDiasParaVencimento'] === null
        || !is_numeric($params['numDiasParaVencimento'])
    ) {
        generateException('O campo dias para vencimento do boleto não foi preenchido corretamente.');
    }

    if (!empty($params['fineValue'])) {
        $multa = str_replace(',', '.', (string) $params['fineValue']);
        if (!is_numeric($multa)) {
            generateException('O campo multa não foi preenchido corretamente.');
        }
    }

    if (!empty($params['interestValue'])) {
        $juros = str_replace(',', '.', (string) $params['interestValue']);
        if (!is_numeric($juros)) {
            generateException('O campo juros não foi preenchido corretamente.');
        }
    }
}

function requiredPixFields($params)
{
    $tests = [
        'chave Pix' => $params['pixKey'] ?? null,
        'validade da cobrança Pix' => $params['pixDays'] ?? null,
    ];

    foreach ($tests as $message => $value) {
        verifyEmptyValue($value, $message);
    }

    $pixKey = (string) ($params['pixKey'] ?? '');
    if (!isPixKeyValid($pixKey)) {
        generateException('Chave PIX inválida.');
    }

    $params['pixCert'] = verifyCertificatePath((string) ($params['pixCert'] ?? ''));

    if (!empty($params['pixDiscount'])) {
        $descontoPix = str_replace(',', '.', str_replace('%', '', (string) $params['pixDiscount']));
        if (!is_numeric($descontoPix)) {
            generateException('O campo desconto Pix não foi preenchido corretamente.');
        }

        $descontoPix = (float) $descontoPix;
        if ($descontoPix >= 100) {
            generateException('Parece que você tentou aplicar um desconto Pix igual ou superior a 100%. Por favor, corrija o valor informado para conseguir salvar as configurações.');
        }
    }

    try {
        $params['debug'] = 'off';
        $gn_instance = getGerencianetApiInstance($params);
        createWebhook($gn_instance, $params);
    } catch (\Throwable $th) {
        generateException($th->getMessage());
    }
}

function requiredOpenFinanceFields($params)
{
    $tests = [
        'Nome' => $params['nome'] ?? null,
        'Documento' => $params['documento'] ?? null,
        'Agência' => $params['agencia'] ?? null,
        'Conta' => $params['conta'] ?? null,
    ];

    foreach ($tests as $message => $value) {
        verifyEmptyValue($value, $message);
    }

    try {
        $params['debug'] = 'off';
        $open_finance_instance = new OpenFinanceEfi($params);
        $open_finance_instance->updateConfigOpenFinance();
    } catch (\Throwable $th) {
        logActivity('EFI Open Finance error | ' . get_class($th) . ' | ' . $th->getFile() . ':' . $th->getLine());
        generateException($th->getMessage());
    }
}

function testAuthentication($params)
{
    try {
        $gnIntegration = new GerencianetIntegration(
            $params['clientIdProd'] ?? '',
            $params['clientSecretProd'] ?? '',
            $params['clientIdSandbox'] ?? '',
            $params['clientSecretSandbox'] ?? '',
            $params['sandbox'] ?? '',
            $params['idConta'] ?? ''
        );

        $gnIntegration->testIntegration();
    } catch (\Throwable $th) {
        generateException('<strong>Credenciais inválidas</strong>. Por favor, verifique se as suas credenciais estão corretas e tente novamente.');
    }
}

function isPixKeyValid($pixKey)
{
    if (!defined('CPF_PATTERN')) {
        define('CPF_PATTERN', '/^[0-9]{11}$/');
        define('CNPJ_PATTERN', '/^[0-9]{14}$/');
        define('PHONE_PATTERN', '/^\+[1-9][0-9]\d{1,14}$/');
        define('EMAIL_PATTERN', '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/');
        define('EVP_PATTERN', "/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/");
    }

    return preg_match(CPF_PATTERN, $pixKey)
        || preg_match(CNPJ_PATTERN, $pixKey)
        || preg_match(PHONE_PATTERN, $pixKey)
        || preg_match(EMAIL_PATTERN, $pixKey)
        || preg_match(EVP_PATTERN, $pixKey);
}

function generateException($message)
{
    throw new \Exception($message);
}

function verifyCertificatePath($relativePath)
{
    if ($relativePath === '') {
        generateException('Caminho do certificado não informado.');
    }

    if (is_readable($relativePath)) {
        return (string) realpath($relativePath);
    }

    $basePaths = [
        realpath(ROOTDIR),
        realpath($_SERVER['DOCUMENT_ROOT'] ?? ''),
    ];

    $normalizedRelativePath = rtrim($relativePath, '/');

    foreach ($basePaths as $basePath) {
        if (!$basePath) {
            continue;
        }

        $basePath = rtrim($basePath, '/');
        $adjustedPath = str_replace($basePath, '', $normalizedRelativePath);
        $fullPath = $basePath . '/' . ltrim($adjustedPath, '/');
        $resolvedFullPath = realpath($fullPath);

        if ($resolvedFullPath && is_readable($resolvedFullPath)) {
            updateGatewaySetting('pixCert', $resolvedFullPath);
            return $resolvedFullPath;
        }
    }

    $examplePath = __DIR__;
    $message = '<div style="line-height: 1.5; word-break: break-all;">Não foi possível encontrar o certificado no caminho informado:<br><strong>' .
        htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') .
        '</strong><br><br>Insira o caminho completo do arquivo, como no exemplo abaixo:<br><strong>' .
        htmlspecialchars($examplePath, ENT_QUOTES, 'UTF-8') .
        '</strong><br></div>';

    generateException($message);
}

function updateGatewaySetting($settingName, $newValue)
{
    $command = 'UpdateModuleConfiguration';
    $postData = [
        'moduleType' => 'gateway',
        'moduleName' => 'efi',
        'parameters' => [$settingName => $newValue],
    ];

    $response = localAPI($command, $postData);

    return ($response['result'] ?? '') === 'success';
}
