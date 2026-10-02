<?php

/**
 * Conteúdo renderizado DENTRO do iframe que o próprio WHMCS cria para gateways "Remote Input"
 * (checkout com cartão ou tela de Métodos de Pagamento). Aqui, e só aqui, existe um formulário
 * de número de cartão -- e mesmo assim o dado nunca é submetido para este servidor: a lib
 * `payment-token-efi` da Efí captura os campos e gera o `payment_token` inteiramente no
 * navegador antes de qualquer envio de rede (não para nosso PHP, não para mais ninguém além da
 * própria Efí).
 *
 * Requer sessão de cliente ativa (mesma origem do WHMCS, cookie de sessão compartilhado).
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../efi/vendor/autoload.php';

$clientId = (int) ($_SESSION['uid'] ?? 0);
$postedClientId = (int) ($_POST['clientid'] ?? 0);

if ($clientId <= 0 || $clientId !== $postedClientId) {
    http_response_code(403);
    die('Sessão inválida.');
}

$accountId = (string) ($_POST['account_id'] ?? '');
$environment = ($_POST['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
$invoiceId = (int) ($_POST['invoiceid'] ?? 0);
$amountCents = (int) ($_POST['amount_cents'] ?? 0);
$firstName = htmlspecialchars((string) ($_POST['firstname'] ?? ''));
$lastName = htmlspecialchars((string) ($_POST['lastname'] ?? ''));
$maxInstallments = max(1, (int) ($_POST['max_installments'] ?? 1));
$knownDocument = \EfiWhmcs\Support\CustomerResolver::onlyDigits((string) ($_POST['document'] ?? ''));
$isCharge = $invoiceId > 0 && $amountCents > 0;

$systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Cart&atilde;o de Cr&eacute;dito</title>
<link rel="stylesheet" href="<?= htmlspecialchars($systemUrl) ?>/modules/gateways/efi/assets/css/efi.css">
<script src="https://cdn.jsdelivr.net/npm/payment-token-efi/dist/payment-token-efi-umd.min.js"></script>
</head>
<body>
<div id="efi_adblock_warning" class="efi-error" style="display:none;">
    Detectamos que uma extens&atilde;o do seu navegador (ex.: bloqueador de an&uacute;ncios) pode
    estar impedindo o carregamento do script de seguran&ccedil;a da Efí. Desative-a para esta
    p&aacute;gina caso o pagamento falhe.
</div>
<form id="efiCardForm" class="efi-card-form" autocomplete="off">
    <div class="efi-field">
        <label>Nome impresso no cart&atilde;o</label>
        <input type="text" id="efi_holder_name" value="<?= $firstName . ' ' . $lastName ?>" required>
    </div>
    <?php if ($knownDocument === ''): ?>
    <div class="efi-field">
        <label>CPF/CNPJ do titular</label>
        <input type="text" id="efi_holder_document" required maxlength="18" placeholder="Somente n&uacute;meros">
    </div>
    <?php else: ?>
    <input type="hidden" id="efi_holder_document" value="<?= htmlspecialchars($knownDocument) ?>">
    <?php endif; ?>
    <div class="efi-field">
        <label>N&uacute;mero do cart&atilde;o</label>
        <input type="tel" id="efi_card_number" required maxlength="23" placeholder="0000 0000 0000 0000" autocomplete="cc-number" inputmode="numeric">
    </div>
    <div class="efi-row">
        <div class="efi-field">
            <label>Validade (MM/AA)</label>
            <input type="tel" id="efi_card_expiry" required maxlength="5" placeholder="MM/AA" autocomplete="cc-exp" inputmode="numeric">
        </div>
        <div class="efi-field">
            <label>CVV</label>
            <input type="tel" id="efi_card_cvv" required maxlength="4" autocomplete="cc-csc">
        </div>
    </div>
    <?php if ($isCharge && $maxInstallments > 1): ?>
    <div class="efi-field" id="efi_installments_wrap" style="display:none;">
        <label>Parcelas</label>
        <select id="efi_installments"></select>
    </div>
    <?php endif; ?>
    <div class="efi-field">
        <label><input type="checkbox" id="efi_save_card" checked> Salvar este cart&atilde;o para pr&oacute;ximos pagamentos</label>
    </div>
    <div id="efi_error" class="efi-error" style="display:none;"></div>
    <button type="submit" id="efi_submit" class="efi-submit">
        <?= $isCharge ? 'Pagar' : 'Salvar cart&atilde;o' ?>
    </button>
</form>
<script>
(function () {
    'use strict';

    var form = document.getElementById('efiCardForm');
    var errorBox = document.getElementById('efi_error');
    var submitBtn = document.getElementById('efi_submit');
    var installmentsWrap = document.getElementById('efi_installments_wrap');
    var installmentsSelect = document.getElementById('efi_installments');
    var accountId = <?= json_encode($accountId) ?>;
    var environment = <?= json_encode($environment) ?>;
    var amountCents = <?= (int) $amountCents ?>;
    var maxInstallments = <?= (int) $maxInstallments ?>;
    var lastDetectedBrand = null;

    EfiPay.CreditCard.isScriptBlocked().then(function (blocked) {
        if (blocked) {
            document.getElementById('efi_adblock_warning').style.display = 'block';
        }
    }).catch(function () { /* verificação best-effort */ });

    function showError(message) {
        errorBox.textContent = message;
        errorBox.style.display = 'block';
    }

    function cardGroupSizes(cardNumber) {
        if (/^3[47]/.test(cardNumber)) {
            return [4, 6, 5]; // American Express
        }
        if (/^(?:30[0-5]|36|38)/.test(cardNumber)) {
            return [4, 6, 4]; // Diners Club
        }

        return [4, 4, 4, 4, 3]; // Visa, Mastercard, Elo, Hipercard e cartões de 19 dígitos
    }

    function formatCardNumber(value) {
        var digits = value.replace(/\D/g, '').slice(0, 19);
        var groups = [];
        var offset = 0;

        cardGroupSizes(digits).forEach(function (size) {
            if (offset < digits.length) {
                groups.push(digits.slice(offset, offset + size));
                offset += size;
            }
        });

        return groups.join(' ');
    }

    function formatExpiry(value) {
        var digits = value.replace(/\D/g, '').slice(0, 6);

        // Aceita a colagem MM/AAAA, mas mantém no formulário apenas MM/AA.
        if (digits.length > 4) {
            digits = digits.slice(0, 2) + digits.slice(-2);
        }

        return digits.length > 2 ? digits.slice(0, 2) + '/' + digits.slice(2) : digits;
    }

    function formatInput(input, formatter) {
        var digitsBeforeCursor = input.value.slice(0, input.selectionStart).replace(/\D/g, '').length;
        var formatted = formatter(input.value);
        var cursor = 0;
        var seenDigits = 0;

        while (cursor < formatted.length && seenDigits < digitsBeforeCursor) {
            if (/\d/.test(formatted.charAt(cursor))) {
                seenDigits++;
            }
            cursor++;
        }

        input.value = formatted;
        input.setSelectionRange(cursor, cursor);
    }

    function detectBrand(cardNumber) {
        return EfiPay.CreditCard.setCardNumber(cardNumber).verifyCardBrand();
    }

    function loadInstallments(brand) {
        if (!installmentsSelect || brand === lastDetectedBrand) {
            return Promise.resolve();
        }

        lastDetectedBrand = brand;

        return EfiPay.CreditCard
            .setAccount(accountId)
            .setEnvironment(environment)
            .setBrand(brand)
            .setTotal(amountCents)
            .getInstallments()
            .then(function (result) {
                var options = (result && result.installments) || [];
                installmentsSelect.innerHTML = '';

                options.slice(0, maxInstallments).forEach(function (opt) {
                    var el = document.createElement('option');
                    el.value = opt.installment;
                    el.textContent = opt.installment + 'x de ' + (opt.currency || opt.value);
                    installmentsSelect.appendChild(el);
                });

                installmentsWrap.style.display = options.length > 1 ? 'block' : 'none';
            })
            .catch(function () {
                installmentsWrap.style.display = 'none';
            });
    }

    var cardNumberInput = document.getElementById('efi_card_number');
    var expiryInput = document.getElementById('efi_card_expiry');

    cardNumberInput.addEventListener('input', function () {
        formatInput(this, formatCardNumber);
    });

    expiryInput.addEventListener('input', function () {
        formatInput(this, formatExpiry);
    });

    document.getElementById('efi_card_number').addEventListener('blur', function () {
        var cardNumber = this.value.replace(/\D/g, '');

        if (cardNumber.length < 6 || !installmentsSelect) {
            return;
        }

        detectBrand(cardNumber).then(function (brand) {
            if (brand !== 'undefined' && brand !== 'unsupported') {
                return loadInstallments(brand);
            }
        }).catch(function () { /* detecção best-effort, erro real tratado no submit */ });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        errorBox.style.display = 'none';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processando...';

        var cardNumber = cardNumberInput.value.replace(/\D/g, '');
        var expiry = expiryInput.value.replace(/\D/g, '');
        var expMonth = expiry.substring(0, 2);
        var expYear = expiry.substring(2);
        var cvv = document.getElementById('efi_card_cvv').value.replace(/\D/g, '');
        var holderName = document.getElementById('efi_holder_name').value;
        var holderDocument = document.getElementById('efi_holder_document').value.replace(/\D/g, '');
        var saveCard = document.getElementById('efi_save_card').checked;
        var installments = installmentsSelect && installmentsSelect.value ? parseInt(installmentsSelect.value, 10) : 1;

        if (!/^(0[1-9]|1[0-2])\d{2}$/.test(expiry)) {
            submitBtn.disabled = false;
            submitBtn.textContent = <?= json_encode($isCharge ? 'Pagar' : 'Salvar cartão') ?>;
            showError('Informe a validade no formato MM/AA.');
            return;
        }

        detectBrand(cardNumber).then(function (brand) {
            if (brand === 'undefined' || brand === 'unsupported') {
                throw { message: 'Bandeira do cart\u00e3o n\u00e3o identificada ou n\u00e3o aceita.' };
            }

            return EfiPay.CreditCard
                .setAccount(accountId)
                .setEnvironment(environment)
                .setCreditCardData({
                    brand: brand,
                    number: cardNumber,
                    cvv: cvv,
                    expirationMonth: expMonth,
                    expirationYear: '20' + expYear,
                    holderName: holderName,
                    holderDocument: holderDocument,
                    reuse: true
                })
                .getPaymentToken();
        }).then(function (tokenResult) {
            return fetch('remote_process.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    clientid: <?= (int) $clientId ?>,
                    invoiceid: <?= (int) $invoiceId ?>,
                    amount_cents: <?= (int) $amountCents ?>,
                    payment_token: tokenResult.payment_token,
                    card_mask: tokenResult.card_mask,
                    brand: tokenResult.brand || null,
                    holder_document: holderDocument,
                    card_expiry_mmyy: expMonth + expYear,
                    installments: installments,
                    save_card: saveCard
                })
            });
        }).then(function (response) {
            return response.text().then(function (body) {
                try {
                    return JSON.parse(body);
                } catch (error) {
                    throw { message: 'Erro desconhecido.' };
                }
            });
        }).then(function (data) {
            if (!data.success) {
                throw { message: data.message || 'Pagamento recusado.' };
            }

            // Sucesso: sai do iframe e recarrega a página pai (fatura/lista de métodos de pagamento).
            window.top.location.reload();
        }).catch(function (error) {
            submitBtn.disabled = false;
            submitBtn.textContent = <?= json_encode($isCharge ? 'Pagar' : 'Salvar cartão') ?>;
            showError((error && error.message) || 'Não foi possível processar o cartão. Verifique os dados e tente novamente.');
        });
    });
})();
</script>
</body>
</html>
