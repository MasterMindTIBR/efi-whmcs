/**
 * Poll leve de status da fatura, usado pelo checkout de Pix (aguardando confirmação) e,
 * opcionalmente, por fluxos de cartão pendentes (ex.: aguardando 3DS). Recarrega a página
 * quando o pagamento é confirmado -- sem popup, sem iframe.
 */
(function () {
    'use strict';

    function startEfiStatusPoll(invoiceId, systemUrl, intervalMs) {
        var elapsed = 0;
        var maxMs = 10 * 60 * 1000; // desiste de fazer poll após 10 minutos; usuário pode recarregar manualmente

        var timer = setInterval(function () {
            elapsed += intervalMs;

            if (elapsed > maxMs) {
                clearInterval(timer);
                return;
            }

            fetch(systemUrl + '/modules/gateways/efi/status.php?invoiceid=' + encodeURIComponent(invoiceId), {
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (data && data.paid) {
                        clearInterval(timer);
                        window.location.reload();
                    }
                })
                .catch(function () {
                    // falha de rede pontual -- tenta de novo no próximo ciclo
                });
        }, intervalMs);
    }

    window.efiStartStatusPoll = startEfiStatusPoll;
})();
