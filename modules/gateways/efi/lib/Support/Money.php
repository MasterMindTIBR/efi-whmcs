<?php

namespace EfiWhmcs\Support;

/**
 * Conversões monetárias. A API Efí trabalha em centavos (inteiro); o WHMCS trabalha em
 * valores decimais (string/float). Centralizar aqui evita erros de arredondamento espalhados
 * pelos três módulos.
 */
final class Money
{
    public static function toCents(float|string $reais): int
    {
        return (int) round(((float) $reais) * 100);
    }

    public static function toReais(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /**
     * Formata para o campo `discount.value`/`configurations.fine` etc. da Efí, que espera
     * inteiro de centavos (para valores monetários) ou inteiro de "centésimos de percentual"
     * (para multa: 200 = 2%, faixa 1-1000) / "milésimos de percentual" (para juros: 33 = 0,033%,
     * faixa 1-33 conforme documentação de boleto).
     */
    public static function percentToFineUnits(float $percent): int
    {
        // multa: 0,01% a 10% -> unidade = percentual * 100
        return (int) round($percent * 100);
    }

    public static function percentToInterestUnits(float $percent): int
    {
        // juros: 0,001% a 0,33% ao dia -> unidade = percentual * 1000
        return (int) round($percent * 1000);
    }
}
