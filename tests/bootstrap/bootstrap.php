<?php

/**
 * Bootstrap da suíte de testes do módulo Efí. NUNCA enviado para produção (ver
 * tests/composer.json e .gitignore) -- simula só o suficiente do runtime do WHMCS para exercitar
 * as classes de `modules/gateways/efi/lib` isoladamente (SQLite em memória por padrão; MySQL
 * real quando `EFI_TEST_DB=mysql`, usado só pelo teste de concorrência).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../../modules/gateways/efi/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

final class TestCapsule extends IlluminateCapsule
{
}

// `WHMCS\Database\Capsule` é uma classe real do core do WHMCS (subclasse do Capsule Manager da
// Illuminate com os mesmos métodos estáticos `::table()`/`::schema()`/`::connection()`). Fora do
// WHMCS ela não existe; para os testes, uma classe equivalente é suficiente -- nosso código de
// produção nunca é alterado para testar.
if (!class_exists(\WHMCS\Database\Capsule::class)) {
    class_alias(TestCapsule::class, \WHMCS\Database\Capsule::class);
}

function efi_test_boot_database(): void
{
    $capsule = new TestCapsule();

    if ((getenv('EFI_TEST_DB') ?: 'sqlite') === 'mysql') {
        $mysqlPassword = getenv('EFI_TEST_MYSQL_PASSWORD');
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => getenv('EFI_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('EFI_TEST_MYSQL_PORT') ?: '3306',
            'database' => getenv('EFI_TEST_MYSQL_DB') ?: 'efi_test',
            'username' => getenv('EFI_TEST_MYSQL_USER') ?: 'root',
            'password' => $mysqlPassword === false ? '' : $mysqlPassword,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
    } else {
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    $capsule->setAsGlobal();
    $capsule->bootEloquent();
}

/**
 * Stubs mínimos das funções globais do WHMCS usadas por `lib/**`. Cada teste que precisa de um
 * comportamento específico (ex.: capturar o valor passado a `addInvoicePayment`) sobrescreve via
 * `$GLOBALS['efi_test_*']` antes de rodar -- nunca via mock de classe, porque são funções globais
 * do WHMCS, não de uma classe.
 */
if (!function_exists('logTransaction')) {
    function logTransaction(string $gateway, $data, string $status): void
    {
        $GLOBALS['efi_test_log_transactions'][] = compact('gateway', 'data', 'status');
    }
}

if (!function_exists('addInvoicePayment')) {
    function addInvoicePayment(int $invoiceId, string $transId, $amount = 0, $fees = 0, string $gateway = ''): void
    {
        $GLOBALS['efi_test_invoice_payments'][] = compact('invoiceId', 'transId', 'amount', 'fees', 'gateway');

        if (isset($GLOBALS['efi_test_add_invoice_payment_fn'])) {
            ($GLOBALS['efi_test_add_invoice_payment_fn'])($invoiceId, $transId, $amount, $fees, $gateway);
        }
    }
}

efi_test_boot_database();
