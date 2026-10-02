<?php

namespace EfiWhmcs\Support;

use WHMCS\Database\Capsule;

/**
 * A Efí exige CPF/CNPJ do pagador em toda cobrança (boleto/cartão/Pix), mas o cadastro padrão
 * de cliente do WHMCS não tem esse campo nativamente. A resolução, em ordem de prioridade:
 *
 *   1. Campo Personalizado de Cliente (Custom Client Field) cujo nome é configurado no gateway
 *      (`document_field_name`), se preenchido para o cliente.
 *   2. Valor informado no próprio formulário de pagamento (input que o módulo renderiza quando o
 *      campo acima está vazio/não configurado).
 *
 * Nunca inventamos ou deduzimos CPF/CNPJ a partir de outro dado.
 */
final class CustomerResolver
{
    public static function documentFromCustomField(int $clientId, ?string $fieldName): ?string
    {
        if ($clientId <= 0 || $fieldName === null || trim($fieldName) === '') {
            return null;
        }

        $value = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('f.fieldname', trim($fieldName))
            ->where('f.type', 'client')
            ->where('v.relid', $clientId)
            ->value('v.value');

        $document = self::onlyDigits((string) $value);

        return $document !== '' ? $document : null;
    }

    public static function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * A API de cartão da Efí recebe telefone brasileiro sem prefixo +55: DDD + número.
     */
    public static function brazilianPhoneNumber(string $value): ?string
    {
        $phone = self::onlyDigits($value);

        if (in_array(strlen($phone), [12, 13], true) && str_starts_with($phone, '55')) {
            $phone = substr($phone, 2);
        }

        return preg_match('/^\d{10,11}$/', $phone) === 1 ? $phone : null;
    }

    public static function isValidCpfOrCnpj(string $digitsOnly): bool
    {
        return in_array(strlen($digitsOnly), [11, 14], true);
    }

    public static function documentKey(string $digitsOnly): string
    {
        return strlen($digitsOnly) === 14 ? 'cnpj' : 'cpf';
    }
}
