<?php

/**
 * Na tela "Métodos de Pagamento" do cliente, o WHMCS core só mostra o link "Excluir" quando
 * `Configuration > General Settings > Security > Allow Client Pay Method Removal` está ativo --
 * e, mesmo ativo, ele renderiza o link igual para TODAS as linhas (inclusive o cartão padrão),
 * sem desabilitar nada: ver `fieldgroup`/`{if $allowDelete}` em
 * `templates/twenty-one/account-paymentmethods.tpl`. Não há verificação nativa de "é o
 * padrão" nem "é o único" no template -- só o controller decide se o delete É ACEITO depois do
 * clique, retornando sucesso/falha.
 *
 * Este hook injeta JS que, só na página de Métodos de Pagamento, busca (via endpoint próprio)
 * quais cartões da Efí NÃO podem ser excluídos agora -- hoje isso é: o único cartão da Efí do
 * cliente, OU o cartão atualmente padrão (`order_preference` mínimo) quando existe mais de um.
 * Para esses, o link nativo "Excluir" é substituído por um botão cinza desabilitado com
 * explicação, em vez de deixar o clique ir até o servidor e falhar depois.
 *
 * A WHMCS não grava em nenhum lugar qual serviço usa qual CARTÃO especificamente (tblhosting/
 * tblorders só guardam o nome do GATEWAY, não o id do pay method) -- por isso a regra possível
 * de verificar é "é o único" / "é o padrão", que já cobre o caso prático: um serviço com
 * cobrança automática na Efí sempre usa o cartão padrão (ou o único, se não houver outro).
 *
 * Instalar em /includes/hooks/ (raiz do WHMCS). Depende de
 * modules/gateways/efi_cartao/remote_unsafe_delete_ids.php (versionado neste mesmo repositório).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('ClientAreaHeadOutput', 1, function (array $vars) {
    if (strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/account/paymentmethods') !== 0) {
        return '';
    }

    return <<<'HTML'
<script>
(function () {
    "use strict";

    function disableDeleteLinks() {
        fetch("/modules/gateways/efi_cartao/remote_unsafe_delete_ids.php", { credentials: "same-origin" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var unsafeIds = (data && data.unsafeIds) || [];
                var totalCount = (data && data.totalCount) || 0;
                if (!unsafeIds.length) {
                    return;
                }

                document.querySelectorAll('a.btn-delete').forEach(function (link) {
                    var match = link.getAttribute("href").match(/\/(\d+)\/delete\/?$/);
                    var id = match ? parseInt(match[1], 10) : null;

                    if (id === null || unsafeIds.indexOf(id) === -1) {
                        return;
                    }

                    var placeholder = document.createElement("span");
                    placeholder.className = link.className + " disabled";
                    placeholder.setAttribute("aria-disabled", "true");
                    placeholder.title = totalCount <= 1
                        ? "Este é o único cartão salvo; adicione outro antes de excluir."
                        : "Este é o cartão padrão; defina outro como padrão antes de excluir.";
                    placeholder.innerHTML = link.innerHTML;
                    placeholder.style.pointerEvents = "none";
                    placeholder.style.opacity = "0.6";
                    link.replaceWith(placeholder);
                });
            })
            .catch(function () { /* best-effort; link nativo continua funcional se isto falhar */ });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", disableDeleteLinks);
    } else {
        disableDeleteLinks();
    }
})();
</script>
HTML;
});
