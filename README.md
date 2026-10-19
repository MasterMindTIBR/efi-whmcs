# Efí para WHMCS

Módulos de gateway de pagamento Efí para WHMCS, reescritos do zero sobre o SDK oficial
[`efipay/sdk-php-apis-efi`](https://github.com/efipay/sdk-php-apis-efi): boleto, Pix imediato,
Pix Automático e cartão de crédito como quatro gateways independentes, com checkout transparente,
cartão salvo cifrado e recorrência automática.

## Recursos

- **Checkout transparente**: boleto e Pix renderizados direto na fatura do WHMCS; cartão via
  iframe nativo do WHMCS (Remote Input Gateway) — sem modal/popup customizado.
- **Cartão tokenizado no navegador**: PAN/CVV nunca chegam ao servidor (lib oficial
  `payment-token-efi`); o token salvo ainda recebe uma camada extra de cifragem (AES-256-GCM,
  com chave derivada por PBKDF2 e salt por token) antes de virar o `gatewayid` do WHMCS.
- **Recorrência automática**: usa o motor de cobrança nativo do WHMCS (`_capture`) — sem
  cron/tabela própria, sem duplo calendário de cobrança.
- **Valor sempre calculado no servidor**: boleto, cartão e Pix cobram o saldo real da fatura
  (nunca o total, nunca um valor enviado pelo navegador) — uma fatura com pagamento parcial
  cobra só o restante.
- **Pagamento recusado não trava a fatura**: o cliente pode tentar de novo ou trocar de cartão
  livremente (fluxo nativo de métodos de pagamento do WHMCS).
- **Pix Automático**: a primeira fatura recebe o pagamento imediato e a autorização recorrente
  em um único Pix copia e cola; as faturas seguintes são agendadas pela API Efí dentro da janela
  de 2 a 10 dias antes do vencimento exigida pelo Banco Central.
- **Parcelamento** consultado em tempo real na conta Efí.
- **Callback e reconciliação com idempotência real**: todo pagamento confirmado passa por um
  único ponto de aplicação (`PaymentApplier`), com trava por fatura e reconciliação diária como
  rede de segurança contra falha pontual de callback/webhook.
- **Vencimento de boleto sincronizado** com o vencimento da fatura, de forma consistente entre
  emissão e reemissão.
- **CPF/CNPJ automático** a partir do Campo Personalizado de Cliente do WHMCS.
- **Botões de admin**: capturar pagamento manualmente e testar conexão com a Efí.
- **Link do boleto/Pix nos emails**: disponível como merge field para os templates do WHMCS.
- Quatro módulos independentes — ative/restrinja boleto, Pix imediato, Pix Automático e cartão por
  grupo de produto separadamente.

## Requisitos

- WHMCS 8.x ou 9.x. WHMCS 9 exige PHP 8.2+; o módulo roda em PHP 8.1, 8.2 e 8.3 (matriz testada
  no CI, ver `.github/workflows/release.yml`).
- Conta Efí com API de Cobranças (boleto/cartão) e API Pix + certificado `.p12`/`.pem`. Pix
  Automático exige Conta Digital Efí Empresas e os escopos `rec.*`, `cobr.*`,
  `payloadlocationrec.*`, `webhookrec.*` e `webhookcobr.*` na aplicação.
- Um Campo Personalizado de Cliente para CPF/CNPJ (a maioria das instalações WHMCS brasileiras já
  tem um — por padrão o módulo procura por `CPF/CNPJ`, mas o nome é configurável).
- **Telefone brasileiro válido no cadastro do cliente é obrigatório para pagar com cartão**
  (checkout e recorrência automática): a API de Cobranças da Efí exige `phone_number` em toda
  cobrança de cartão. Sem telefone válido, o checkout recusa e a recorrência automática falha
  com uma mensagem clara no Log do Módulo.

## Instalação

1. Baixe o pacote da [release](../../releases) correspondente (ou clone o repositório na tag
   desejada) e extraia na raiz da sua instalação WHMCS, mantendo a mesma estrutura de pastas
   (`modules/`, `includes/`). O `vendor/` do SDK já vem pronto, não é necessário rodar Composer.
2. Em **Configurações > Pagamentos > Gateways de Pagamento**, ative **Efí - Boleto Bancário**,
   **Efí - Pix**, **Efí - Pix Automático** e/ou **Efí - Cartão de Crédito**. Cada gateway pode
   ser restringido por grupo de produto.
3. Preencha Client ID/Client Secret (Menu API > Aplicações na sua conta Efí) e o Identificador de
   Conta (Menu API > Introdução) em cada módulo ativado.
4. Confirme que o **cron diário do WHMCS** (`DailyCronJob`) está configurado e rodando — veja a
   seção [Cron](#cron) abaixo; sem ele, a reconciliação de pagamentos e o agendamento de CobR do
   Pix Automático não acontecem.

### Cartão de crédito

Preencha **Chave de Cifragem do Cartão** com um valor aleatório forte (`openssl rand -hex 32`)
antes de salvar. Guarde uma cópia em local seguro — se for perdida ou trocada, cartões já salvos
precisam ser recadastrados pelo cliente.

Para a recorrência automática funcionar, o cliente precisa ter, no cadastro: um CPF/CNPJ válido
no Campo Personalizado configurado (o módulo grava automaticamente o documento digitado no
checkout quando o campo está vazio ou com valor inválido) e um telefone brasileiro válido.

**Política de `approved`**: a Efí devolve `approved` antes da análise antifraude terminar. O
módulo já dá a fatura como paga em `approved` (o cliente vê "Pagamento em análise" no checkout).
Se a Efí depois derrubar a cobrança (`approved`/`paid` → `unpaid`/`canceled`), o módulo desfaz o
crédito — lança uma saída (`AddTransaction` com `amountout`, transid `<charge_id>-reversao`),
reabre a fatura como `Unpaid` e grava um alerta **CRÍTICO** no Log do Módulo. A suspensão do
serviço, se for o caso, fica a seu critério. `refunded`/`contested`/`charged_back` só geram
alerta; o tratamento contábil é manual.

Estorno: o módulo usa sempre o `transid` informado pelo próprio WHMCS (nunca presume "a última
cobrança desta fatura"), soma os estornos parciais já feitos e marca a cobrança como
`partially_refunded` até o total estornado alcançar o valor cobrado.

**Runbook — cobrança de cartão com resultado desconhecido**: se a chamada à Efí cair por
timeout/erro de rede, o resultado real é desconhecido e a cobrança fica `unknown` (ou
`failed-unknown`, se nem o `charge_id` foi recebido). Nesses estados o módulo **recusa** nova
tentativa automática para a fatura (o cliente vê "Não tente de novo…") — retentar poderia cobrar
o cartão duas vezes. Resolução: procure a cobrança no painel da Efí pelo `custom_id`
`whmcs_invoice_<id da fatura>`. Se foi cobrada, registre o pagamento manualmente no WHMCS com o
`charge_id` como transid; se não foi, altere o status da linha em `mod_efi_charges` para
`canceled` para liberar uma nova tentativa.

### Pix

1. Gere o certificado Pix no painel Efí e envie o `.p12`/`.pem` para o servidor (fora do webroot
   público, se possível).
2. Preencha o caminho do certificado e um **Segredo do Webhook** (`openssl rand -hex 32`). Ao
   salvar, o módulo registra o webhook na Efí automaticamente.
3. **Requisito de infraestrutura — mTLS**: a API Pix exige mTLS na camada de transporte ao
   RECEBER o webhook — configure seu servidor web para apresentar/validar o certificado público
   da Efí, conforme
   [dev.efipay.com.br/docs/api-pix/webhooks](https://dev.efipay.com.br/docs/api-pix/webhooks).
   Isso é feito no Nginx/Apache, não neste módulo.
4. **Alternativa — Pular verificação mTLS**: em hospedagem compartilhada onde não é possível
   configurar mTLS no servidor (comum em Plesk/cPanel compartilhado), ative **"Pular verificação
   mTLS no cadastro do webhook"** na configuração de cada gateway Pix/Pix Automático. O módulo
   envia o header `x-skip-mtls-checking` só na chamada de cadastro do webhook (nunca nas demais
   chamadas) e, como medida compensatória, passa a validar a origem de cada notificação recebida
   contra uma lista de IPs permitidos (**"IPs permitidos para o webhook"**, pré-preenchida com o
   IP documentado hoje pela Efí — confirme na doc oficial se as notificações pararem de chegar,
   o IP pode mudar). Se o seu servidor estiver atrás de um proxy reverso/CDN, preencha também
   **"Proxies confiáveis do webhook Pix"** (lista de IPs do proxy) para que o módulo leia o IP
   real do cliente via `X-Forwarded-For` em vez do IP do proxy.
5. Se o total de uma fatura com Pix ativo for alterado, o módulo revisa automaticamente o valor
   da cobrança na Efí. Pix imediato conta sua expiração a partir da emissão, não da data de
   vencimento da fatura, e é renovado automaticamente ao reabrir a fatura quando expirado.
6. Para renovar um QR Code expirado manualmente — ou emitir outro antes disso — abra a fatura no
   admin e use **Gerar nova cobrança Pix**. A ação desativa primeiro a cobrança ativa anterior;
   não está disponível para faturas pagas, canceladas ou estornadas.

### Pix Automático

1. Ative **Efí - Pix Automático**, preencha a chave/certificado Pix, **agência/conta/tipo de
   conta do recebedor** (exigidos pelo Banco Central em toda cobrança recorrente) e salve. O
   módulo cadastra os webhooks de Pix imediato, recorrência e cobrança recorrente na Efí.
2. Restrinja o gateway a produtos com o ciclo configurado em **Periodicidade autorizada**. A
   primeira fatura mostra o Pix copia e cola da jornada 3: o cliente paga a fatura atual e aceita
   a autorização no aplicativo do banco.
3. O módulo vincula **uma autorização por cliente** (não por serviço/produto — se o mesmo
   cliente tiver mais de uma assinatura recorrente, as duas CobR competem pela mesma
   autorização; uma autorização por serviço é uma mudança de schema fora do escopo atual).
4. Após o banco aprovar a autorização, cada nova fatura desse cliente recebe uma CobR agendada
   dentro da janela de 2 a 10 dias antes do vencimento, exigida pelo Banco Central — nem antes,
   nem depois. O cron diário tenta agendar faturas que ainda não couberam nessa janela e
   reprocessa CobR que falhou (`status=error`) automaticamente.
5. **Faturas sem CobR agendada não ficam sem forma de pagamento**: se a CobR da fatura não
   existir (fora da janela, erro de agendamento, etc.), o módulo oferece automaticamente um Pix
   avulso para aquela fatura específica, usando a mesma chave e credenciais já configuradas
   neste gateway — as próximas faturas continuam automáticas normalmente. Esse Pix avulso é
   creditado, reconciliado e cancelado com as credenciais do Pix Automático mesmo com o gateway
   **Efí - Pix** inativo. Estorno desse Pix avulso (e das CobR) não é feito pelo botão de
   reembolso do WHMCS (o gateway não implementa `_refund`): devolva pelo painel da Efí e registre
   o estorno manualmente.
6. O webhook comum de Pix aceita os segredos de Pix imediato e Pix Automático; a chave Pix usada
   para o Pix avulso de fallback (item 5) é sempre a configurada neste próprio gateway.
7. **Runbook — autorização presa em "criando"**: se o processo morrer entre o pedido de
   autorização à Efí e a gravação do identificador (`idRec`) local, o cron sinaliza o caso no Log
   do Módulo após 10 minutos ("revisão manual necessária") em vez de tentar recriar sozinho —
   pode já existir uma autorização ativa na Efí para aquele cliente, e recriar geraria
   duplicidade. Resolução: consulte o painel da Efí pelo `contrato` (`WHMCS-<client_id>`) e
   decida manualmente entre reaproveitar o `idRec` existente (grave em
   `mod_efi_pix_auto_recurrences.efi_recurrence_id`) ou cancelar e deixar o cliente reiniciar.
8. **Pendências de validação (sandbox Efí)**: o payload de `recebedor` (campo exigido pelo
   Banco Central em toda CobR) e o texto do erro "txid já utilizado" em 400 seguem o spec
   publicado, mas não foram testados contra o sandbox real da Efí. Valide antes de ativar para
   cliente real.

### Hooks e recorrência

Confirme que `includes/hooks/efi_hooks.php` ficou na raiz do WHMCS (não em `modules/gateways/`). Sem ele:
cancelamento de fatura não cancela o boleto/CobR, vencimento não realinha, CobRs não são criadas
quando a fatura nasce e a reconciliação diária não roda.

Para cobrança automática de cartão, apenas ative a Cobrança Automática de Faturas do próprio
WHMCS (Configurações > Automação) — o módulo não precisa de nenhuma configuração adicional para
recorrência.

`includes/hooks/disable_unsafe_card_delete.php` é opcional: desabilita visualmente o link
nativo "Excluir" na tela de Métodos de Pagamento do cliente para o único cartão Efí salvo ou
para o cartão padrão (quando há mais de um), evitando que o cliente tente excluir um cartão em
uso. Depende de `modules/gateways/efi_cartao/remote_unsafe_delete_ids.php`, já incluído.

### Link do boleto/Pix no email da fatura

Os merge fields `{$efi_boleto_link}`, `{$efi_boleto_barcode}`, `{$efi_pix_qrcode}`,
`{$efi_pix_copiaecola}` e `{$efi_pix_link}` ficam disponíveis para uso nos templates de email
(Configurações > Sistema > Modelos de Email — ex.: "Fatura Criada", "Lembrete de Pagamento").
Adicione-os ao template desejado para que o cliente receba o link/código de pagamento direto no
email. O Pix só é incluído quando a cobrança ainda está `ATIVA` e não expirada (nunca mostra um
QR morto).

### Nginx/Apache — bloquear acesso direto ao `vendor/`

`modules/gateways/efi/vendor/.htaccess` já nega acesso HTTP direto ao SDK e suas dependências
em servidores Apache/LiteSpeed com `AllowOverride` habilitado para esse diretório. **Em Nginx,
`.htaccess` não tem efeito** — adicione manualmente ao `server {}` do seu vhost:

```nginx
location ^~ /modules/gateways/efi/vendor/ {
    deny all;
    return 403;
}
```

Isso impede o acesso direto a `vendor/efipay/sdk-php-apis-efi/examples/` (que faz chamadas
reais à API e inclui um conversor de certificado `.p12` sem autenticação) e a qualquer outro
arquivo do SDK que não deveria ser servido como estático.

### Cron

O cron diário do WHMCS (`php -q crons/cron.php` ou o cron nativo da Automation Settings) precisa
rodar para que o módulo funcione de forma confiável — ele é responsável por:

- Reconciliar pagamentos de boleto, cartão (`waiting` e `approved`) e Pix que não chegaram via
  webhook (rede de segurança), numa janela de 2 a 60 dias. Cada Pix é consultado com as
  credenciais do gateway que o emitiu (Pix imediato ou Pix avulso do Pix Automático).
- Pix Automático: consultar autorizações ainda pendentes (`CRIADA`) e o Pix inicial de faturas
  que continuam em aberto — se o webhook `/rec` ou o do pagamento inicial se perder, a
  autorização e o crédito da primeira fatura ainda acontecem.
- Agendar CobR do Pix Automático dentro da janela de 2 a 10 dias antes do vencimento, e
  reprocessar CobR que falhou.
- Resolver cobranças/reservas presas em `creating` há mais de 10 minutos (reconcilia se já
  houver identificador capturado; marca `failed-unknown`/sinaliza para revisão manual, senão).
- Reprocessar eventos de webhook que ficaram `pending` (ex.: um crash entre registrar o evento e
  aplicar o pagamento) e fechar esses eventos quando a cobrança já estiver resolvida (paga,
  cancelada, expirada), para não voltarem à fila todo dia.

Em instalações com Cobrança Automática de Faturas desativada ou cron não configurado, nenhum
desses mecanismos roda, e você depende inteiramente do callback síncrono/webhook.

## Troubleshooting

- **"Módulo não está ativo" num botão/endpoint do admin**: o gateway correspondente não está
  salvo/ativo em Configurações > Pagamentos. Os hooks e callbacks deste módulo nunca quebram a
  página inteira quando isso acontece (erro tratado), mas as ações específicas desse gateway
  ficam indisponíveis.
- **Webhook Pix nunca chega / cadastro falha com erro de mTLS**: ative "Pular verificação mTLS"
  (ver seção Pix acima) ou configure mTLS real no servidor. Confira o Log do Módulo
  (`efi_pix`/`efi_pix_automatico`) para a mensagem exata de rejeição.
- **Webhook chega mas o pagamento não aparece na fatura**: confira o Log do Módulo por
  "pagamento recebido fora do ciclo de cobrança" (a fatura já estava Paga/Cancelada/Refunded por
  outro meio quando o webhook chegou — o evento é fechado como `failed` e a resolução é manual:
  gerar crédito ou estornar conforme a política do lojista) ou por um erro de rede/lock
  (`Falha ao obter trava da fatura`): nesse caso o evento fica `pending` e o cron diário o
  reprocessa consultando a Efí.
- **CPF/CNPJ sempre recusado no formulário de boleto/cartão/Pix**: confira se o Campo
  Personalizado configurado (`document_field_name`) já tem um valor com dígito verificador
  válido; se tiver um valor inválido salvo, o módulo usa o valor digitado no formulário em vez
  dele (não trava mais no campo antigo inválido).
- **Recorrência de cartão falha com "telefone/CPF não encontrado"**: cadastre um telefone
  brasileiro válido e um CPF/CNPJ válido no Campo Personalizado do cliente; a recorrência
  automática nunca recebe entrada do navegador, só lê o cadastro.
- **CobR do Pix Automático não é criada**: confira se o vencimento da próxima fatura cai dentro
  da janela de 2 a 10 dias (o cron tenta de novo diariamente até caber na janela) e se
  agência/conta/tipo de conta do recebedor estão preenchidos na configuração do gateway.

## Upgrade

As migrações de schema deste módulo (`mod_efi_schema_version`) são automáticas e idempotentes:
rodam na primeira requisição após o deploy (tela de configuração de qualquer gateway Efí, ou o
cron diário) e nunca mais que uma vez por processo. Não é necessário rodar nenhum script manual
ao atualizar de uma versão para outra. Veja o `CHANGELOG.md` para mudanças de comportamento
entre versões.

## Créditos

- **[Jung](https://github.com/junglivre)** — reescrita e arquitetura deste módulo.
- **[Efí Pay](https://github.com/efipay)** — SDK oficial `sdk-php-apis-efi` sobre o qual este
  módulo é construído. Os ícones de ativação em `modules/gateways/efi_boleto/`,
  `modules/gateways/efi_pix/` e `modules/gateways/efi_cartao/` usam a marca oficial da Efí Pay.
- **[Marcelo Machado](https://github.com/marcelo-machado-efi)** — autor do módulo WHMCS original da Efí, que serviu de referência de
  comportamento e requisitos de negócio para esta reescrita.

## Licença

[MIT](LICENSE)
