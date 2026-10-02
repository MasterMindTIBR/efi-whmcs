# Changelog

Todas as mudanças notáveis deste projeto são documentadas aqui.
Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).

## [Unreleased]

### Adicionado

- Gateway `efi_pix_automatico`: jornada 3 da Efí, autorização na primeira fatura e CobR para
  faturas futuras do mesmo cliente.
- Webhooks de recorrência e cobrança Pix Automático com consulta autoritativa, idempotência e
  reconciliação diária.
- Cancelamento da CobR pendente ao cancelar a fatura no WHMCS.

### Alterado

- Formulário de cartão agrupa automaticamente o número conforme a bandeira e exibe a validade
  como `MM/AA`; a tokenização continua enviando o ano com quatro dígitos à Efí.

### Corrigido

- Checkout de cartão agora mantém a resposta AJAX em JSON quando o log completo está ativo; uma
  resposta inválida é exibida ao cliente como `Erro desconhecido.`.
- Hook Efí carrega funções de gateway apenas dentro dos callbacks que precisam delas, impedindo
  a redeclaração de `loadgatewaymodule()` durante a renderização de faturas.
- Cobranças de cartão agora usam o endpoint oficial `POST /v1/charge/one-step`; falhas HTTP da
  API não são mais apresentadas como recusa da operadora.
- Checkout e recorrência de cartão enviam `phone_number` obrigatório, validado a partir do
  telefone cadastrado no cliente do WHMCS.

## [1.0.0]

Reescrita completa do módulo Efí para WHMCS a partir do zero, usando o SDK oficial
`efipay/sdk-php-apis-efi`, como três gateways independentes: `efi_boleto`, `efi_pix` e
`efi_cartao`.

### Adicionado

- Checkout transparente: boleto e Pix renderizados na própria página da fatura; cartão via
  iframe nativo do WHMCS (Remote Input Gateway), sem popup.
- Cartão de crédito tokenizado no navegador (PAN/CVV nunca chegam ao servidor) com cifragem
  adicional (AES-256-GCM) do token salvo.
- Recorrência automática de cartão usando o motor de cobrança nativo do WHMCS (`_capture`), sem
  cron/tabela próprios.
- Callback/webhook robusto com idempotência real, processamento de todos os eventos em ordem, e
  reconciliação diária como rede de segurança.
- Realinhamento consistente de vencimento do boleto quando a fatura muda de data.
- Suporte a parcelamento (consulta de parcelas da Efí) no checkout de cartão.
- Botão de captura manual de pagamento e teste de conexão no admin.
- Revisão automática do valor de Pix ativo quando o total da fatura muda e controle administrativo
  para desativar e renovar uma cobrança Pix.
- CPF/CNPJ resolvido automaticamente do Campo Personalizado de Cliente.
- Link do boleto/Pix disponível como merge field para os templates de email do WHMCS.

### Corrigido

- Carregamento das funções de gateway do WHMCS no processamento de cartão tokenizado.
- Extração e recuperação do link e da linha digitável de boletos emitidos pela API Efí.
- Carregamento das funções de fatura adiado para o cron, evitando colisão com as funções já
  carregadas nas páginas da loja.
