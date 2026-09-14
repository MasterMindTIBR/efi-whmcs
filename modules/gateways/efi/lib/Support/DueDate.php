<?php

namespace EfiWhmcs\Support;

use DateInterval;
use DateTime;

/**
 * Cálculo de vencimento do boleto e realinhamento quando a fatura WHMCS muda de data.
 *
 * Regra (ver docs/ARQUITETURA.md §1.7): o offset em dias configurado
 * (`due_days_offset`) é aplicado UMA VEZ, sobre a data de vencimento *da fatura WHMCS*, tanto
 * na emissão quanto em qualquer realinhamento futuro. O offset efetivamente usado na emissão é
 * gravado por cobrança (`mod_efi_charges.due_offset_days`) e é esse valor gravado -- não a
 * configuração corrente do gateway, que pode ter mudado -- que deve ser reaplicado em
 * realinhamentos, para que o boleto sempre acompanhe a fatura com o MESMO deslocamento relativo.
 */
final class DueDate
{
    /**
     * Data de vencimento a usar na emissão do boleto.
     *
     * @param string $invoiceDueDate formato Y-m-d, vencimento atual da fatura WHMCS
     * @param int $offsetDays dias corridos a somar (pode ser 0)
     */
    public static function forCreation(string $invoiceDueDate, int $offsetDays): string
    {
        $base = self::parse($invoiceDueDate);
        $today = new DateTime('today');

        // Se a fatura já está vencida no momento da emissão, usa hoje como base -- não faz
        // sentido pedir à Efí um boleto com vencimento no passado.
        if ($base < $today) {
            $base = $today;
        }

        return self::addDays($base, $offsetDays)->format('Y-m-d');
    }

    /**
     * Nova data de vencimento a enviar para `updateBillet` quando a fatura WHMCS muda de data,
     * reaplicando o MESMO offset gravado na emissão original.
     */
    public static function forRealignment(string $newInvoiceDueDate, int $offsetDaysUsedAtCreation): string
    {
        return self::addDays(self::parse($newInvoiceDueDate), $offsetDaysUsedAtCreation)->format('Y-m-d');
    }

    /**
     * A API Efí exige que o novo vencimento seja estritamente maior que a data atual
     * (doc oficial: "A nova data de vencimento deve ser pelo menos maior que a data atual").
     */
    public static function isAcceptableByEfi(string $candidateDate): bool
    {
        return self::parse($candidateDate) > new DateTime('today');
    }

    private static function parse(string $date): DateTime
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);

        if ($parsed === false) {
            throw new \InvalidArgumentException("Data inválida (esperado Y-m-d): {$date}");
        }

        $parsed->setTime(0, 0, 0);

        return $parsed;
    }

    private static function addDays(DateTime $date, int $days): DateTime
    {
        $clone = clone $date;

        if ($days > 0) {
            $clone->add(new DateInterval('P' . $days . 'D'));
        } elseif ($days < 0) {
            $clone->sub(new DateInterval('P' . abs($days) . 'D'));
        }

        return $clone;
    }
}
