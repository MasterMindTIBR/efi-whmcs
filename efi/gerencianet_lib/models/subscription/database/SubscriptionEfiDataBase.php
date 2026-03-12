<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Exception;

class EfiSubscriptionDatabase
{
    private const SUBSCRIPTION_TABLE = 'tblsubscriptionefi';
    private const SCHEDULE_TABLE = 'tblschedulepaymentefi';
    private const INVOICES_TABLE = 'tblinvoices';
    private const SUBSCRIPTION_RELID_UNIQUE_INDEX = 'tblsubscriptionefi_relid_unique';
    private const SCHEDULE_INVOICEID_INDEX = 'idx_tblschedulepaymentefi_invoiceid';
    private const SCHEDULE_INVOICEID_FK = 'tblschedulepaymentefi_invoiceid_foreign';

    /**
     * Create required tables used by the module.
     *
     * @throws QueryException
     * @throws Exception
     */
    public static function createEfiSubscriptionTable(): void
    {
        try {
            self::createSubscriptionTable();
            self::dropUniqueRelidIfPresent();
            self::createSchedulePaymentTable();
        } catch (QueryException $e) {
            logActivity('Erro ao criar as tabelas: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao criar as tabelas: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao criar as tabelas: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Add a subscription.
     *
     * @param string $paymentMethod
     * @param int $relId
     * @param array $customer
     * @return bool
     *
     * @throws QueryException
     * @throws Exception
     */
    public static function addSubscription(string $paymentMethod, int $relId, array $customer): bool
    {
        try {
            $inserted = Capsule::table(self::SUBSCRIPTION_TABLE)->insert([
                'payment_method' => $paymentMethod,
                'relid'          => $relId,
                'customer'       => json_encode($customer),
            ]);

            logActivity("Assinatura adicionada com sucesso para o rel ID: {$relId}.");
            return (bool) $inserted;
        } catch (QueryException $e) {
            logActivity('Erro ao adicionar assinatura: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao adicionar assinatura: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao adicionar assinatura: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Check whether all relIds exist in the subscription table.
     *
     * @param int[] $relIds
     * @return bool
     *
     * @throws QueryException
     * @throws Exception
     */
    public static function areAllSubscriptionsPresent(array $relIds): bool
    {
        try {
            $count = Capsule::table(self::SUBSCRIPTION_TABLE)
                ->whereIn('relid', $relIds)
                ->count();

            return $count === count($relIds);
        } catch (QueryException $e) {
            logActivity('Erro ao buscar assinaturas: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao buscar assinaturas: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao buscar assinaturas: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Delete a subscription by relId.
     *
     * @param int $relId
     * @return void
     *
     * @throws QueryException
     * @throws Exception
     */
    public static function deleteSubscription(int $relId): void
    {
        try {
            Capsule::table(self::SUBSCRIPTION_TABLE)->where('relid', $relId)->delete();
            logActivity("Assinatura removida com sucesso para o rel ID: {$relId}.");
        } catch (QueryException $e) {
            logActivity('Erro ao remover assinatura: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao remover assinatura: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao remover assinatura: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Add a scheduled payment.
     *
     * @param int $invoiceId
     * @param string $paymentToken
     * @param string $date
     * @param int $relId
     * @return bool
     *
     * @throws QueryException
     * @throws Exception
     */
    public static function addScheduledPayment(int $invoiceId, string $paymentToken, string $date, int $relId): bool
    {
        try {
            $inserted = Capsule::table(self::SCHEDULE_TABLE)->insert([
                'invoiceid'     => $invoiceId,
                'payment_token' => $paymentToken,
                'date'          => $date,
                'relid'         => $relId,
            ]);

            logActivity("Pagamento agendado adicionado com sucesso para a fatura ID: {$invoiceId}.");
            return (bool) $inserted;
        } catch (QueryException $e) {
            logActivity('Erro ao adicionar pagamento agendado: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao adicionar pagamento agendado: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao adicionar pagamento agendado: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Get scheduled payment by invoiceId.
     *
     * @param int $invoiceId
     * @return object|null
     *
     * @throws QueryException
     * @throws Exception
     */
    public static function getScheduledPaymentByInvoiceId(int $invoiceId): ?object
    {
        try {
            $payment = Capsule::table(self::SCHEDULE_TABLE)->where('invoiceid', $invoiceId)->first();
            logActivity("Pagamento agendado buscado com sucesso para a fatura ID: {$invoiceId}.");
            return $payment ?: null;
        } catch (QueryException $e) {
            logActivity('Erro ao buscar pagamento agendado: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao buscar pagamento agendado: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao buscar pagamento agendado: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Delete scheduled payment by invoiceId.
     *
     * @param int $invoiceId
     * @return void
     *
     * @throws QueryException
     * @throws Exception
     */
    public static function deleteScheduledPayment(int $invoiceId): void
    {
        try {
            Capsule::table(self::SCHEDULE_TABLE)->where('invoiceid', $invoiceId)->delete();
            logActivity("Pagamento agendado removido com sucesso para a fatura ID: {$invoiceId}.");
        } catch (QueryException $e) {
            logActivity('Erro ao remover pagamento agendado: ' . $e->getMessage());
            throw new QueryException($e->getSql(), $e->getBindings(), $e);
        } catch (Exception $e) {
            logActivity('Erro inesperado ao remover pagamento agendado: ' . $e->getMessage());
            throw new Exception('Erro inesperado ao remover pagamento agendado: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private static function createSubscriptionTable(): void
    {
        if (Capsule::schema()->hasTable(self::SUBSCRIPTION_TABLE)) {
            return;
        }

        Capsule::schema()->create(self::SUBSCRIPTION_TABLE, function ($table) {
            $table->increments('id');
            $table->string('payment_method');
            $table->string('customer', 10000);
            $table->unsignedInteger('relid');
        });

        logActivity('Tabela tblsubscriptionefi criada com sucesso.');
    }

    private static function createSchedulePaymentTable(): void
    {
        if (Capsule::schema()->hasTable(self::SCHEDULE_TABLE)) {
            return;
        }

        [$invoiceIdColumnType, $invoiceEngine] = self::getInvoiceSchemaInfo();

        Capsule::schema()->create(self::SCHEDULE_TABLE, function ($table) {
            $table->increments('id');
            $table->unsignedInteger('relid');
            $table->string('payment_token');
            $table->date('date');
            $table->index('relid');
        });

        self::addInvoiceIdColumnMatchingInvoices($invoiceIdColumnType);
        self::trySetScheduleEngineToInnoDb($invoiceEngine);
        self::tryAddScheduleForeignKey($invoiceIdColumnType, $invoiceEngine);
    }

    private static function trySetScheduleEngineToInnoDb(?string $invoiceEngine): void
    {
        if (!is_string($invoiceEngine) || strcasecmp($invoiceEngine, 'InnoDB') !== 0) {
            return;
        }

        try {
            Capsule::statement('ALTER TABLE `' . self::SCHEDULE_TABLE . '` ENGINE=InnoDB');
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function tryAddScheduleForeignKey(?string $invoiceIdColumnType, ?string $invoiceEngine): void
    {
        try {
            Capsule::statement(
                'ALTER TABLE `' . self::SCHEDULE_TABLE . '`
                 ADD CONSTRAINT `' . self::SCHEDULE_INVOICEID_FK . '`
                 FOREIGN KEY (`invoiceid`) REFERENCES `' . self::INVOICES_TABLE . '`(`id`)
                 ON DELETE CASCADE'
            );

            logActivity('Tabela tblschedulepaymentefi criada com sucesso (FK criada em tblinvoices.id).');
        } catch (\Throwable $e) {
            $engineText = is_string($invoiceEngine) ? $invoiceEngine : 'desconhecida';
            $colText = is_string($invoiceIdColumnType) ? $invoiceIdColumnType : 'desconhecido';

            logActivity(
                'Tabela tblschedulepaymentefi criada com sucesso (SEM FK - fallback). ' .
                    'Motivo: ' . $e->getMessage() . ' | tblinvoices ENGINE: ' . $engineText . ' | tblinvoices.id: ' . $colText
            );
        }
    }

    private static function getInvoiceSchemaInfo(): array
    {
        $dbName = Capsule::connection()->getConfig('database');

        $columnType = Capsule::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $dbName)
            ->where('TABLE_NAME', self::INVOICES_TABLE)
            ->where('COLUMN_NAME', 'id')
            ->value('COLUMN_TYPE');

        $engine = Capsule::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $dbName)
            ->where('TABLE_NAME', self::INVOICES_TABLE)
            ->value('ENGINE');

        return [$columnType, $engine];
    }

    private static function addInvoiceIdColumnMatchingInvoices(?string $invoiceIdColumnType): void
    {
        $sqlType = self::toSqlIntegerType($invoiceIdColumnType);

        Capsule::statement('ALTER TABLE `' . self::SCHEDULE_TABLE . '` ADD COLUMN `invoiceid` ' . $sqlType . ' NOT NULL');
        Capsule::statement('ALTER TABLE `' . self::SCHEDULE_TABLE . '` ADD INDEX `' . self::SCHEDULE_INVOICEID_INDEX . '` (`invoiceid`)');
    }

    private static function toSqlIntegerType(?string $invoiceIdColumnType): string
    {
        $sqlType = 'INT';

        if (!is_string($invoiceIdColumnType)) {
            return $sqlType;
        }

        $t = strtolower($invoiceIdColumnType);

        if (str_contains($t, 'bigint')) {
            $sqlType = 'BIGINT';
        } elseif (str_contains($t, 'mediumint')) {
            $sqlType = 'MEDIUMINT';
        } elseif (str_contains($t, 'smallint')) {
            $sqlType = 'SMALLINT';
        } elseif (str_contains($t, 'tinyint')) {
            $sqlType = 'TINYINT';
        } else {
            $sqlType = 'INT';
        }

        if (str_contains($t, 'unsigned')) {
            $sqlType .= ' UNSIGNED';
        }

        return $sqlType;
    }

    private static function dropUniqueRelidIfPresent(): void
    {
        if (!Capsule::schema()->hasTable(self::SUBSCRIPTION_TABLE)) {
            return;
        }

        $dbName = Capsule::connection()->getConfig('database');

        $exists = Capsule::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', self::SUBSCRIPTION_TABLE)
            ->where('index_name', self::SUBSCRIPTION_RELID_UNIQUE_INDEX)
            ->count() > 0;

        if (!$exists) {
            return;
        }

        Capsule::statement(
            'ALTER TABLE `' . self::SUBSCRIPTION_TABLE . '` DROP INDEX `' . self::SUBSCRIPTION_RELID_UNIQUE_INDEX . '`'
        );

        logActivity('Índice UNIQUE removido: ' . self::SUBSCRIPTION_RELID_UNIQUE_INDEX . ' em tblsubscriptionefi.relid.');
    }
}
