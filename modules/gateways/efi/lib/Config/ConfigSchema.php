<?php

namespace EfiWhmcs\Config;

/**
 * Campos de configuração compartilhados pelos três módulos de gateway. Cada módulo chama
 * `ConfigSchema::common($friendlyName)` e mescla os campos específicos dele (boleto/pix/cartão)
 * — ver docs/ARQUITETURA.md §3.3.
 */
final class ConfigSchema
{
    public static function common(string $friendlyName): array
    {
        return [
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => $friendlyName,
            ],
            'environment' => [
                'FriendlyName' => 'Ambiente',
                'Type' => 'dropdown',
                'Options' => [
                    'sandbox' => 'Homologação (sandbox)',
                    'production' => 'Produção',
                ],
                'Description' => 'Selecione Produção somente após validar em homologação.',
            ],
            'client_id' => [
                'FriendlyName' => 'Client ID',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Client_Id da aplicação Efí (Produção ou Homologação, conforme o Ambiente acima).',
            ],
            'client_secret' => [
                'FriendlyName' => 'Client Secret',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Client_Secret da aplicação Efí.',
            ],
            'account_id' => [
                'FriendlyName' => 'Identificador de Conta (payee_code)',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Menu API > Introdução > Identificador de Conta, na sua conta Efí. Necessário para a tokenização de cartão no navegador.',
            ],
            'debug_level' => [
                'FriendlyName' => 'Nível de Log',
                'Type' => 'dropdown',
                'Options' => [
                    'errors' => 'Somente erros (recomendado)',
                    'full' => 'Completo (requisições e respostas, use só para depurar)',
                    'none' => 'Nenhum',
                ],
                'Description' => 'Segredos (tokens, senhas) nunca são gravados no log, independente do nível.',
            ],
        ];
    }

    public static function webhookSecret(): array
    {
        return [
            'webhook_secret' => [
                'FriendlyName' => 'Segredo do Webhook',
                'Type' => 'password',
                'Size' => '64',
                'Description' => 'Valor aleatório usado para validar a autenticidade das notificações recebidas. Sugestão: gere com <code>openssl rand -hex 32</code>.',
            ],
        ];
    }
}
