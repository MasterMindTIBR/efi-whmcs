<?php

declare(strict_types=1);

namespace Tests\Unit;

use Efi\EfiPay;
use EfiWhmcs\Card\CardService;
use EfiWhmcs\Ledger\Schema;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class CardDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::resetForTests();
        foreach (['mod_efi_schema_version', 'mod_efi_events', 'mod_efi_charges'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Schema::ensure();
    }

    private function chargeAndCapture(string $document, ?string $companyName): array
    {
        $captured = null;
        $api = $this->createMock(EfiPay::class);
        $api->method('__call')->willReturnCallback(function (string $method, array $args) use (&$captured): array {
            if ($method === 'createOneStepCharge') {
                $captured = $args[1] ?? null;

                return ['data' => ['charge_id' => 'charge-x', 'status' => 'approved']];
            }

            return [];
        });

        $service = new CardService($api, ['debug_level' => 'errors']);
        $service->chargeWithToken(1, 'token-x', 1000, 'Cliente Teste', $document, null, '11999999999', null, 1, $companyName);

        return $captured['payment']['credit_card']['customer'];
    }

    public function testCpfStaysAtTheCustomerRoot(): void
    {
        $customer = $this->chargeAndCapture('12345678901', null);

        self::assertSame('12345678901', $customer['cpf'] ?? null);
        self::assertArrayNotHasKey('juridical_person', $customer);
    }

    public function testCnpjBuildsJuridicalPerson(): void
    {
        $customer = $this->chargeAndCapture('12345678000199', 'Empresa Teste LTDA');

        self::assertArrayNotHasKey('cnpj', $customer);
        self::assertArrayHasKey('juridical_person', $customer);
        self::assertSame('12345678000199', $customer['juridical_person']['cnpj'] ?? null);
        self::assertSame('Empresa Teste LTDA', $customer['juridical_person']['corporate_name'] ?? null);
    }
}
