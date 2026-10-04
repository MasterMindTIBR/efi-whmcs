<?php

namespace EfiWhmcs\Support;

/**
 * CSRF mínimo para os endpoints AJAX que o admin aciona via botão nas telas de fatura/gateway
 * (admin_capture.php, admin_regenerate.php, admin_diagnostics.php). Esses endpoints só validam
 * `$_SESSION['adminid']`, o que não impede CSRF: um admin autenticado que visite uma página
 * maliciosa pode ter o navegador disparando um POST para essas rotas usando o cookie de sessão
 * dele automaticamente. Token por sessão, gerado uma vez e reaproveitado nos botões renderizados
 * pelos hooks.
 */
final class AdminCsrf
{
    private const SESSION_KEY = 'efi_admin_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $providedToken): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected) && is_string($providedToken) && $providedToken !== ''
            && hash_equals($expected, $providedToken);
    }
}
