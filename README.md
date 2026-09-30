# Efí para WHMCS

Módulos de gateway de pagamento Efí para WHMCS, reescritos do zero sobre o SDK oficial
[`efipay/sdk-php-apis-efi`](https://github.com/efipay/sdk-php-apis-efi): boleto, Pix imediato,
Pix Automático e cartão de crédito como quatro gateways independentes, com checkout transparente,
cartão salvo cifrado e recorrência automática.

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
- **Pix Automático**: a primeira fatura recebe o pagamento imediato e a autorização recorrente
  em um único Pix copia e cola; as faturas seguintes são agendadas pela API Efí.
- **Parcelamento** consultado em tempo real na conta Efí.
- **Callback robusto**: idempotência real, processa todos os eventos em ordem, com reconciliação
  diária como rede de segurança.
- **Vencimento de boleto sincronizado** com o vencimento da fatura, de forma consistente entre
  emissão e reemissão.
- **CPF/CNPJ automático** a partir do Campo Personalizado de Cliente do WHMCS.
- **Botões de admin**: capturar pagamento manualmente e testar conexão com a Efí.
- **Link do boleto/Pix nos emails**: disponível como merge field para os templates do WHMCS.
- Quatro módulos independentes — ative/restrinja boleto, Pix imediato, Pix Automático e cartão por
  grupo de produto separadamente.

## Requisitos

- WHMCS 8.x ou 9.x, PHP >= 8.1 (compatível com o PHP 8.1/8.2 usado pelo WHMCS 8.x e com o PHP
  8.2/8.3 exigido pelo WHMCS 9.x).
- Conta Efí com API de Cobranças (boleto/cartão) e API Pix + certificado `.p12`/`.pem`. Pix
  Automático exige Conta Digital Efí Empresas e os escopos `rec.*`, `cobr.*`,
  `payloadlocationrec.*`, `webhookrec.*` e `webhookcobr.*` na aplicação.
- Um Campo Personalizado de Cliente para CPF/CNPJ (a maioria das instalações WHMCS brasileiras já
  tem um — por padrão o módulo procura por `CPF/CNPJ`, mas o nome é configurável).

## Instalação

1. Extraia o pacote da [release](../../releases) mais recente na raiz da sua instalação WHMCS,
   mantendo a mesma estrutura de pastas do repositório (`modules/`, `includes/`). O `vendor/` do SDK
   já vem pronto, não é necessário rodar Composer.
2. Em **Configurações > Pagamentos > Gateways de Pagamento**, ative **Efí - Boleto Bancário**,
   **Efí - Pix**, **Efí - Pix Automático** e/ou **Efí - Cartão de Crédito**. Cada gateway pode
   ser restringido por grupo de produto.
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

4. Se o total de uma fatura com Pix ativo for alterado, o módulo revisa automaticamente o valor
   da cobrança na Efí. Pix imediato conta sua expiração a partir da emissão, não da data de
   vencimento da fatura.
5. Para renovar um QR Code expirado — ou emitir outro antes disso — abra a fatura no admin e use
   **Gerar nova cobrança Pix**. A ação desativa primeiro a cobrança ativa anterior; não está
   disponível para faturas pagas, canceladas ou estornadas.

### Pix Automático

1. Ative **Efí - Pix Automático**, preencha a chave/certificado Pix e salve. O módulo cadastra
   os webhooks de Pix imediato, recorrência e cobrança recorrente na Efí.
2. Restrinja o gateway a produtos com o ciclo configurado em **Periodicidade autorizada**. A
   primeira fatura mostra o Pix copia e cola da jornada 3: o cliente paga a fatura atual e aceita
   a autorização no aplicativo do banco.
3. O módulo vincula uma autorização a cada cliente. Após o banco aprová-la, o hook
   `InvoiceCreated` cria uma CobR para cada nova fatura não paga com esse gateway.
4. A autorização fixa o valor recorrente. Se uma fatura futura tiver outro total, o módulo não
   cria a cobrança e registra a necessidade de uma nova autorização. O cliente deve cancelar a
   autorização anterior no banco; após o callback `CANCELADA`, ele pode escolher Pix Automático
   em uma nova fatura.
5. O webhook comum de Pix aceita os segredos de Pix imediato e Pix Automático. Você pode usar a
   mesma chave Pix nos dois gateways.



### Hooks e recorrência

Confirme que `includes/hooks/efi_hooks.php` ficou na raiz do WHMCS (não em `modules/gateways/`). Sem ele:
cancelamento de fatura não cancela o boleto/CobR, vencimento não realinha, CobRs não são criadas
quando a fatura nasce e a reconciliação diária não roda.

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
