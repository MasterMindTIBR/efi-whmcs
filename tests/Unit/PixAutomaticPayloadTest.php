<?php

declare(strict_types=1);

namespace Tests\Unit;

use Efi\EfiPay;
use EfiWhmcs\PixAutomatic\PixAutomaticService;
use EfiWhmcs\PixAutomatic\Repository;
use EfiWhmcs\PixAutomatic\Schema;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

final class PixAutomaticPayloadTest extends TestCase
{
    private array $gatewayParams = [
        'pix_key' => 'chave@exemplo.com',
        'contract_object' => 'Serviços recorrentes WHMCS',
        'retry_policy' => 'NAO_PERMITE',
        'initial_expiration_days' => 1,
        'recebedor_agencia' => '0001',
        'recebedor_conta' => '123456',
        'recebedor_tipo_conta' => 'CORRENTE',
        'periodicity' => 'MENSAL',
        'debug_level' => 'errors',
    ];

    protected function setUp(): void
    {
        \EfiWhmcs\Ledger\Schema::resetForTests();
        Schema::resetForTests();
        foreach ([
            'mod_efi_schema_version', 'mod_efi_pix_auto_recurrences', 'mod_efi_pix_auto_charges',
            'mod_efi_pix_auto_events', 'mod_efi_charges', 'mod_efi_events', 'tblinvoices',
        ] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::schema()->create('tblinvoices', function ($table) {
            $table->increments('id');
            $table->decimal('total', 10, 2);
            $table->string('status');
        });
        \EfiWhmcs\Ledger\Schema::ensure();
        Schema::ensure();
    }

    public function testCobrPayloadIncludesConfiguredRecebedor(): void
    {
        $recurrence = $this->approvedRecurrence(amountCents: 2000);

        $captured = null;
        $api = $this->createMock(EfiPay::class);
        $api->method('__call')->willReturnCallback(function (string $method, array $args) use (&$captured): array {
            if ($method === 'pixCreateAutomaticChargeTxid') {
                $captured = $args[1] ?? null;

                return ['status' => 'CRIADA'];
            }

            return [];
        });

        $invoice = (object) ['id' => 10, 'userid' => (int) $recurrence->client_id, 'total' => '20.00', 'duedate' => date('Y-m-d', strtotime('+5 days'))];
        (new PixAutomaticService($api, $this->gatewayParams))->scheduleInvoice($invoice);

        self::assertIsArray($captured);
        self::assertSame([
            'agencia' => '0001',
            'conta' => '123456',
            'tipoConta' => 'CORRENTE',
        ], $captured['recebedor'] ?? null);
    }

    public function testScheduleInvoiceNoLongerBlocksAnAmountSmallerThanTheInitialAuthorization(): void
    {
        // Autorização inicial de R$ 20,00; fatura recorrente de apenas R$ 5,00 -- antes deste
        // rock, isso retornava uma mensagem de bloqueio sem nem tentar criar a CobR.
        $recurrence = $this->approvedRecurrence(amountCents: 2000);

        $created = false;
        $api = $this->createMock(EfiPay::class);
        $api->method('__call')->willReturnCallback(function (string $method) use (&$created): array {
            if ($method === 'pixCreateAutomaticChargeTxid') {
                $created = true;

                return ['status' => 'CRIADA'];
            }

            return [];
        });

        $invoice = (object) ['id' => 11, 'userid' => (int) $recurrence->client_id, 'total' => '5.00', 'duedate' => date('Y-m-d', strtotime('+5 days'))];
        $message = (new PixAutomaticService($api, $this->gatewayParams))->scheduleInvoice($invoice);

        self::assertTrue($created, 'A CobR deveria ter sido criada mesmo com valor menor que a autorização inicial.');
        self::assertNotNull($message);
        self::assertStringNotContainsString('valorMinimoRecebedor', (string) $message);
        self::assertSame('Cobrança Pix Automático agendada na Efí.', $message);
    }

    public function testFirstRecurringDateNeverReturnsAPastOrTodayDate(): void
    {
        $api = $this->createMock(EfiPay::class);
        $service = new PixAutomaticService($api, $this->gatewayParams);

        $method = new \ReflectionMethod($service, 'firstRecurringDate');
        $method->setAccessible(true);

        // Vencimento há 60 dias: dataInicial + 1 mês ainda cairia no passado sem o piso.
        $longOverdue = date('Y-m-d', strtotime('-60 days'));
        $result = $method->invoke($service, $longOverdue);

        $tomorrow = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
        self::assertGreaterThanOrEqual($tomorrow, $result);
    }

    private function approvedRecurrence(int $amountCents): object
    {
        $clientId = random_int(1000, 999999);
        Repository::createRecurrence([
            'client_id' => $clientId,
            'initial_invoice_id' => 1,
            'efi_recurrence_id' => 'REC' . $clientId,
            'initial_txid' => 'TXID' . $clientId,
            'status' => 'APROVADA',
            'amount_cents' => $amountCents,
            'metadata' => '{}',
        ]);

        return Repository::findRecurrenceByClient($clientId);
    }
}
