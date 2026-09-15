# Efí para WHMCS

Módulos de gateway de pagamento Efí para WHMCS, reescritos do zero sobre o SDK oficial
[`efipay/sdk-php-apis-efi`](https://github.com/efipay/sdk-php-apis-efi): boleto, Pix e cartão de
crédito como três gateways independentes, com checkout transparente (sem popup), cartão salvo
cifrado e recorrência automática.

## Recursos

- **Checkout transparente**: boleto e Pix renderizados direto na fatura do WHMCS; cartão via
  iframe nativo do WHMCS (Remote Input Gateway) — sem modal/popup customizado.
- **Cartão tokenizado no navegador**: PAN/CVV nunca chegam ao servidor (lib oficial
  `payment-token-efi`); o token salvo ainda recebe uma camada extra de cifragem (AES-256-GCM)
  antes de virar o `gatewayid` do WHMCS.
- **Recorrência automática**: usa o motor de cobrança nativo do WHMCS (`_capture`) — sem
  cron/tabela própria, sem duplo calendário de cobrança.
- **Pagamento recusado não trava a fatura**: o cliente pode tentar de novo ou trocar de cartão
  livremente (fluxo nativo de métodos de pagamento do WHMCS).
- **Parcelamento** consultado em tempo real na conta Efí.
- **Callback robusto**: idempotência real, processa todos os eventos em ordem, com reconciliação
  diária como rede de segurança.
- **Vencimento de boleto sincronizado** com o vencimento da fatura, de forma consistente entre
  emissão e reemissão.
- **CPF/CNPJ automático** a partir do Campo Personalizado de Cliente do WHMCS.
- **Botões de admin**: capturar pagamento manualmente e testar conexão com a Efí.
- **Link do boleto/Pix nos emails**: disponível como merge field para os templates do WHMCS.
- Três módulos independentes — ative/restrinja boleto, Pix e cartão por grupo de produto
  separadamente.

## Requisitos

- WHMCS 8.x ou 9.x, PHP >= 8.1 (compatível com o PHP 8.1/8.2 usado pelo WHMCS 8.x e com o PHP
  8.2/8.3 exigido pelo WHMCS 9.x).
- Conta Efí com API de Cobranças (boleto/cartão) e, se for usar Pix, API Pix + certificado
  `.p12`/`.pem`.
- Um Campo Personalizado de Cliente para CPF/CNPJ (a maioria das instalações WHMCS brasileiras já
  tem um — por padrão o módulo procura por `CPF/CNPJ`, mas o nome é configurável).

## Instalação

1. Extraia o pacote da [release](../../releases) mais recente na raiz da sua instalação WHMCS,
   mantendo a mesma estrutura de pastas do repositório (`modules/`, `includes/`). O `vendor/` do SDK
   já vem pronto, não é necessário rodar Composer.
2. Em **Configurações > Pagamentos > Gateways de Pagamento**, ative **Efí - Boleto Bancário**,
   **Efí - Pix** e/ou **Efí - Cartão de Crédito** — cada um separadamente, com a possibilidade de
   restringir por grupo de produto.
3. Preencha Client ID/Client Secret (Menu API > Aplicações na sua conta Efí) e o Identificador de
   Conta (Menu API > Introdução) em cada módulo ativado.

### Cartão de crédito

Preencha **Chave de Cifragem do Cartão** com um valor aleatório forte (`openssl rand -hex 32`)
antes de salvar. Guarde uma cópia em local seguro — se for perdida ou trocada, cartões já salvos
precisam ser recadastrados pelo cliente.

### Pix

1. Gere o certificado Pix no painel Efí e envie o `.p12`/`.pem` para o servidor (fora do webroot
   público, se possível).
2. Preencha o caminho do certificado e um **Segredo do Webhook** (`openssl rand -hex 32`). Ao
   salvar, o módulo registra o webhook na Efí automaticamente.
3. **Requisito de infraestrutura**: a API Pix exige mTLS na camada de transporte — configure seu
   servidor web para apresentar/validar o certificado público da Efí, conforme
   [dev.efipay.com.br/docs/api-pix/webhooks](https://dev.efipay.com.br/docs/api-pix/webhooks).
   Isso é feito no Nginx/Apache, não neste módulo.

### Hooks e recorrência

Confirme que `includes/hooks/efi_hooks.php` ficou na raiz do WHMCS (não em `modules/gateways/`). Sem ele:
cancelamento de fatura não cancela o boleto, vencimento não realinha, e a reconciliação diária não
roda.

Para cobrança automática de cartão, apenas ative a Cobrança Automática de Faturas do próprio
WHMCS (Configurações > Automação) — o módulo não precisa de nenhuma configuração adicional para
recorrência.

### Link do boleto/Pix no email da fatura

Os merge fields `{$efi_boleto_link}`, `{$efi_boleto_barcode}` e `{$efi_pix_copiaecola}` ficam
disponíveis para uso nos templates de email (Configurações > Sistema > Modelos de Email — ex.:
"Fatura Criada", "Lembrete de Pagamento"). Adicione-os ao template desejado para que o cliente
receba o link/código de pagamento direto no email.

## Créditos

- **[Jung](https://github.com/junglivre)** — reescrita e arquitetura deste módulo.
- **[Efí Pay](https://github.com/efipay)** — SDK oficial `sdk-php-apis-efi` sobre o qual este
  módulo é construído. Os ícones de ativação em `modules/gateways/efi_boleto/`,
  `modules/gateways/efi_pix/` e `modules/gateways/efi_cartao/` usam a marca oficial da Efí Pay.
- **[Marcelo Machado](https://github.com/marcelo-machado-efi)** — autor do módulo WHMCS original da Efí, que serviu de referência de
  comportamento e requisitos de negócio para esta reescrita.

## Licença

[MIT](LICENSE)
