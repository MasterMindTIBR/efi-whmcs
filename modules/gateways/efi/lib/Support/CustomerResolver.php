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
 *
 * Toda string de documento que sai desta classe passou por `onlyDigits()` -- em profundidade,
 * mesmo os métodos de montagem de payload (`chargesCustomerDocument()`/`documentKey()`) reaplicam
 * `onlyDigits()` no valor recebido, para que nenhum caractere não numérico chegue à API da Efí
 * mesmo que uma chamada futura esqueça de higienizar antes.
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

    /**
     * Comprimento E dígito verificador. Rejeita documentos sintaticamente do tamanho certo mas
     * inválidos (todos os dígitos iguais, DV incorreto) antes de gastar uma chamada de API.
     */
    public static function isValidCpfOrCnpj(string $digitsOnly): bool
    {
        $digitsOnly = self::onlyDigits($digitsOnly);

        return match (strlen($digitsOnly)) {
            11 => self::isValidCpf($digitsOnly),
            14 => self::isValidCnpj($digitsOnly),
            default => false,
        };
    }

    public static function isValidCpf(string $digitsOnly): bool
    {
        if (strlen($digitsOnly) !== 11 || preg_match('/^(\d)\1{10}$/', $digitsOnly) === 1) {
            return false;
        }

        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $digitsOnly[$i] * ($position + 1 - $i);
            }
            $check = (($sum * 10) % 11) % 10;
            if ($check !== (int) $digitsOnly[$position]) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(string $digitsOnly): bool
    {
        if (strlen($digitsOnly) !== 14 || preg_match('/^(\d)\1{13}$/', $digitsOnly) === 1) {
            return false;
        }

        $weightsFirst = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weightsSecond = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([[12, $weightsFirst], [13, $weightsSecond]] as [$position, $weights]) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $digitsOnly[$i] * $weights[$i];
            }
            $check = $sum % 11;
            $check = $check < 2 ? 0 : 11 - $check;
            if ($check !== (int) $digitsOnly[$position]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Chave usada pela API Pix, onde CPF/CNPJ fica sempre na raiz de `devedor`.
     */
    public static function documentKey(string $digitsOnly): string
    {
        return strlen(self::onlyDigits($digitsOnly)) === 14 ? 'cnpj' : 'cpf';
    }

    /**
     * Estrutura `customer` da API de Cobranças (boleto/cartão one-step): CPF fica na raiz, mas
     * CNPJ precisa do envelope `juridical_person.{corporate_name,cnpj}` (ver
     * dev.efipay.com.br/docs/api-cobrancas/boleto e .../cartao). `$companyName` vazio cai de
     * volta para `$customerName`, já que a Efí exige `corporate_name` não vazio.
     */
    public static function chargesCustomerDocument(string $digitsOnly, string $customerName, ?string $companyName = null): array
    {
        $digitsOnly = self::onlyDigits($digitsOnly);

        if (strlen($digitsOnly) === 14) {
            $corporateName = trim((string) $companyName) !== '' ? trim((string) $companyName) : $customerName;

            return ['juridical_person' => [
                'corporate_name' => mb_substr($corporateName, 0, 200),
                'cnpj' => $digitsOnly,
            ]];
        }

        return ['cpf' => $digitsOnly];
    }
}
