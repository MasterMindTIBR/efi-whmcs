# Changelog

Todas as mudanças notáveis deste projeto são documentadas aqui.
Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).

## [Unreleased]

Produção endurecida numa clarity break de 5 rocks (auditoria completa em `ISSUES.md`,
plano em `PLAN.md`). Cada item abaixo corrige um problema real, verificado no código (não
apenas documentado) e coberto por teste automatizado ou lint/análise estática, quando aplicável.

### Adicionado

- Gateway `efi_pix_automatico`: jornada 3 da Efí, autorização na primeira fatura e CobR para
  faturas futuras do mesmo cliente.
- `PaymentApplier` (`lib/Support/PaymentApplier.php`): único ponto que aplica um pagamento
  confirmado pela Efí a uma fatura do WHMCS. Usado por boleto, cartão, Pix e Pix Automático —
  callbacks e reconciliação diária, substituindo toda lógica duplicada de aplicação de
  pagamento que existia em cada trilho.
- `InvoiceBalance::centsDue()` (`lib/Support/InvoiceBalance.php`): único ponto que calcula o
  saldo devedor de uma fatura (total menos pagamentos já recebidos). Usado por boleto, cartão e
  Pix para cobrar sempre o saldo, nunca o total.
- Esquema de versão do banco (`mod_efi_schema_version`): migrações nomeadas e idempotentes,
  aplicadas no máximo uma vez por processo, com backfill verificado linha a linha contra
  `tblaccounts` (nunca marca um pagamento como "já aplicado" sem confirmar a transação
  correspondente).
- Opção "Pular verificação mTLS no cadastro do webhook" para os gateways Pix e Pix Automático
  (útil em hospedagem compartilhada que não suporta apresentar o certificado cliente da Efí),
  com allowlist de IP configurável, normalização de IPv4-mapeado-em-IPv6 e suporte a proxy
  reverso confiável (leitura de `X-Forwarded-For` só quando o IP imediato é um proxy
  configurado); origem rejeitada responde HTTP 403 (não 200), permitindo que a Efí reenvie.
- Pix Automático: opção de agência/conta/tipo de conta do recebedor (`recebedor`, campo
  obrigatório do Banco Central em toda CobR). [INFERENCE: formato não validado em sandbox real
  por falta de credenciais nesta sessão — valide antes de ativar para cliente real.]
- Pix Automático: fatura sem CobR agendada (fora da janela de 2-10 dias, erro, etc.) ganha um
  Pix avulso automático como alternativa, em vez de ficar sem forma de pagamento.
- `modules/gateways/efi/vendor/.htaccess`: nega acesso HTTP direto ao SDK e dependências em
  Apache/LiteSpeed (ver README para o equivalente em Nginx).
- `includes/hooks/disable_unsafe_card_delete.php`: versionado (antes só existia manualmente no
  staging) — desabilita visualmente o link "Excluir" do único cartão Efí salvo ou do cartão
  padrão, na tela de Métodos de Pagamento do cliente.
- CI (`release.yml`) roda em todo push/PR: lint em PHP 8.1/8.2/8.3, PHPStan nível 2, suíte de
  testes unitários (SQLite) e um teste de concorrência real contra MySQL (dois processos
  disputando a aplicação do mesmo pagamento, e dois pagamentos distintos concorrentes para a
  mesma fatura — prova que só um aplica). Empacotamento e release do GitHub continuam só em tag,
  agora depois do CI passar e com o zip construído antes da release ser criada (nunca mais uma
  release publicada sem o zip).

### Alterado

- Formulário de cartão agrupa automaticamente o número conforme a bandeira e exibe a validade
  como `MM/AA`; a tokenização continua enviando o ano com quatro dígitos à Efí.
- Após sucesso (cobrança ou salvar cartão), o formulário exibe um alerta `SweetAlert2` com
  temporizador de 3 segundos antes de redirecionar; ao pagar uma fatura, redireciona sempre
  para `viewinvoice.php` da fatura correspondente, mesmo quando o pagamento é iniciado a partir
  da página dedicada de pagamento (`/invoice/{id}/pay`); ao só salvar um cartão, redireciona
  para a tela "Métodos de Pagamento" em vez de recarregar a própria tela de adição.
- Apelido padrão de um cartão salvo não leva mais o prefixo "Efí -" (fica só `Cartão final
  XXXX` ou `Visa final XXXX`, por exemplo); o campo "Nome" (`Card-XXXX`) continua sendo gerado
  internamente pelo WHMCS e não é configurável pelo gateway.
- Editar um cartão salvo agora permite renomear o apelido (`tblpaymethods.description`) por um
  formulário mínimo próprio, já que o WHMCS esconde o campo nativo de descrição sempre que o
  gateway define `_remoteupdate`; o formulário usa as mesmas classes Bootstrap do tema (sem CSS
  próprio) para herdar a aparência nativa da página.
- Tela "Métodos de Pagamento": o link nativo "Excluir" (setting `CCAllowCustomerDelete`,
  "Allow Client Pay Method Removal") é desabilitado (cinza, com explicação) para o único
  cartão da Efí do cliente ou para o cartão padrão quando há mais de um -- evita que o cliente
  tente excluir um cartão em uso e só descubra a falha depois do clique.
- Removida a opção "3D Secure obrigatório acima de (R$)" do gateway de cartão (e a menção a
  "Suporte a 3D Secure" do `whmcs.json`): nenhum fluxo de 3DS é implementado por este módulo.
- `mod_efi_charges`/`mod_efi_events` (e os equivalentes do Pix Automático) passam a ser
  insert-only por emissão: reemitir uma cobrança (boleto renovado, Pix renovado, cartão
  reservado) nunca mais sobrescreve a linha existente — a anterior é marcada `superseded` e uma
  linha nova é inserida. Isso resolve o boleto cancelado-mas-impresso que a Efí ainda deixa
  pagar (o pagamento casa com a linha antiga via `findByEfiChargeId`, que busca em todas as
  linhas, ativas ou supersededs), e evita colisão de evento entre a cobrança antiga e a nova.
- Emissão de boleto e cobrança de cartão reservam uma linha `creating` sob trava nomeada por
  fatura (`GET_LOCK`) antes de chamar a API da Efí — duas abas/requisições concorrentes para a
  mesma fatura não geram mais duas cobranças reais.
- Reconciliação diária: janela de 2 a 60 dias (incluindo cartão `waiting`), leitura corrigida do
  status retornado pela API (`data.status`, não `data.status.current`, que nunca existiu),
  aplicação de pagamento sempre via `PaymentApplier`, e reprocessamento automático de eventos de
  webhook que ficaram `pending` (crash entre registrar o evento e aplicar o pagamento) e de
  reservas `creating` travadas há mais de 10 minutos.

### Corrigido

#### Núcleo / idempotência (todos os trilhos)

- O evento de webhook/reconciliação agora é marcado `applied` só depois do pagamento
  efetivamente aplicado (antes era gravado antes, e uma falha no meio perdia o pagamento para
  sempre sem nenhuma forma de reprocessar).
- Removido todo uso de `checkCbInvoiceID`/`checkCbTransID` fora do fluxo síncrono do checkout de
  cartão — esses helpers encerram o processo inteiro (`die()`) quando veem uma fatura excluída
  ou um transid repetido; no cron diário, isso abortava silenciosamente o resto da execução
  (inclusive o agendamento de CobR do Pix Automático, que roda depois).
- `PaymentApplier` consulta `tblaccounts` pelo transid canônico SEMPRE primeiro, antes de olhar
  o status da fatura — uma retentativa depois de um crash parcial (pagamento já aplicado, só o
  evento não foi marcado) nunca mais tenta aplicar o pagamento uma segunda vez.
- O ledger local é marcado `paid`/`CONCLUIDA` ANTES de chamar `addInvoicePayment`: o hook
  `InvoicePaid`, disparado de forma síncrona dentro dessa mesma chamada, já enxerga a cobrança
  como paga e não tenta cancelá-la (antes, cancelar boleto/Pix/CobR no ciclo de vida da fatura
  podia cancelar a cobrança que acabou de ser paga).

#### Pix imediato

- `PixService::cancelInvoiceCharge()` implementado (estava ausente — o hook
  `InvoiceCancelled`/`InvoicePaid` chamava um método inexistente, e o erro era engolido pelo
  `catch` genérico, deixando o Pix pagável numa fatura já fora do ciclo de cobrança).
- Reemissão passa a tratar `REMOVIDA_PELO_USUARIO_RECEBEDOR`/`REMOVIDA_PELO_PSP` como elegíveis
  a nova cobrança (antes só uma cobrança `ATIVA` expirada era renovada).
- Desconto: `base_amount_cents` gravado na emissão; o desconto lançado é sempre
  `base_amount_cents × percentual`, nunca mais "total atual da fatura menos valor recebido". Só
  lança o item quando o valor recebido bate exatamente com o esperado; uma divergência vira log
  para revisão manual em vez de reconciliar silenciosamente. Roda sempre dentro do `markPaid`
  que o `PaymentApplier` invoca — nunca antes de confirmar que o pagamento será de fato aplicado
  (uma versão anterior deste mesmo fix, dentro desta clarity break, lançava o desconto antes do
  `apply()`; uma retentativa de evento `pending` duplicava o desconto).
- Id de devolução Pix passa a reservar um número de tentativa atômico no ledger compartilhado —
  o Bacen recusa reutilizar o mesmo id para uma segunda devolução parcial da mesma cobrança.
- Devoluções recebidas (e MED) deixam de ser descartadas como reentrega de notificação: ganham
  um namespace de evento próprio (`devolucao:<rtrId>`) e aparecem no Log do Módulo para revisão
  manual (o módulo não lança estorno contábil automático no WHMCS — decisão de política de
  cobrança do lojista, não bug de código).

#### Boleto

- Reemite também quando o status é `expired` (não só `canceled`).
- Emissão e realinhamento de vencimento usam `InvoiceBalance::centsDue()` (saldo), não o total
  da fatura — antes uma fatura com pagamento parcial anterior cobrava o valor cheio de novo.
- Desconto lançado como item negativo na fatura, igual ao Pix, dentro do `markPaid`.
- CPF/CNPJ digitado no formulário volta a ser usado quando o campo personalizado do cliente tem
  dígito verificador inválido (antes o campo inválido travava o fluxo para sempre, mesmo com o
  cliente digitando um documento válido).
- `DueDate` usa `America/Sao_Paulo` explicitamente em toda conta de vencimento, em vez do fuso
  do processo PHP.
- Removido o hook `InvoiceDeleted`: não é um hook point real do WHMCS (fora do índice oficial),
  o handler nunca disparava.

#### Cartão

- O valor cobrado é sempre calculado no servidor a partir do **saldo** da fatura (nunca do
  total, nunca de algo enviado pelo navegador) — tanto no checkout quanto na recorrência
  automática (`_capture`).
- Recusa cobrança quando a moeda da fatura não é BRL, em vez de cobrar o número certo na moeda
  errada.
- `_capture` (recorrência automática) ganha a mesma proteção contra chamada duplicada que o
  checkout síncrono já tinha: nada impedia o WHMCS disparar uma segunda cobrança real no cartão
  do cliente se o cron de cobrança e um clique manual em "Tentar Capturar Pagamento"
  corressem ao mesmo tempo.
- `CardService::chargeWithToken` captura qualquer `\Throwable` (não só `EfiException`) ao redor
  da chamada à API — um timeout/erro de rede do Guzzle antes escapava sem gravar nada no
  ledger, liberando uma nova tentativa automática sobre uma cobrança cujo resultado real era
  desconhecido.
- Estorno envia o campo `amount` (não `value`, que a Efí ignorava ou rejeitava) e usa sempre o
  `transid` informado pelo WHMCS para localizar a cobrança (nunca mais "a última cobrança desta
  fatura no ledger local"); marca `partially_refunded` quando o valor estornado é menor que o
  total da cobrança.
- CNPJ monta `customer.juridical_person.{corporate_name,cnpj}` (antes ia incorretamente como
  `customer.cnpj` na raiz, que a Efí rejeita/trata como pessoa física).
- O CPF/CNPJ digitado no checkout é gravado de volta no Campo Personalizado do cliente (quando
  ainda estiver vazio), para que a recorrência automática — que só lê o cadastro e nunca recebe
  entrada do navegador — encontre o documento nas cobranças futuras.
- `Crypto` (cifragem do token de cartão salvo) ganha um formato versionado (`v2`): salt
  aleatório de 16 bytes por token + `hash_pbkdf2` com 100000 iterações, em vez de uma chave sem
  sal e sem custo computacional; tokens no formato anterior (`v1`) continuam sendo lidos
  normalmente (sem forçar recadastro); uma tag de autenticação truncada/adulterada passa a ser
  rejeitada explicitamente em vez de aceita silenciosamente pelo OpenSSL.
- Exceção interna não é mais devolvida ao cliente no checkout (`remote_process.php`); o detalhe
  continua só no Log do Módulo.

#### Pix Automático

- Removido o piso local baseado em `valorMinimoRecebedor` que recusava agendar a CobR de uma
  fatura com valor menor que a autorização inicial — interpretação errada do campo do Bacen, que
  é um teto configurado pelo PAGADOR, não um mínimo que o recebedor possa exigir por cobrança.
- `firstRecurringDate` nunca mais devolve uma data no passado/hoje quando a fatura inicial já
  está vencida há mais de um ciclo (o Bacen exige `dataInicial` futura).
- `createOrFetchAutomaticCharge`/`createOrFetchInitialCharge` também tratam 400 quando a
  descrição do erro identifica claramente "txid já utilizado", além do 409 já tratado (um 400
  genérico continua sendo relançado como erro real).
- Cron sinaliza (nunca recria sozinho) uma autorização presa em `creating` por mais de 10
  minutos — pode já existir um `idRec` na Efí sem registro local; resolução é manual (ver
  README, seção Pix Automático).

#### Admin / operação

- `admin_capture.php` (botão "Tentar Capturar Pagamento") volta a devolver
  `echo json_encode(...)` — uma regressão de uma correção anterior desta mesma clarity break
  fazia o admin ver sempre "falha" mesmo com a captura executada com sucesso.
- `admin_diagnostics.php` ("Testar Conexão") passa a validar um token CSRF por sessão (o mesmo
  padrão já usado por `admin_capture.php`/`admin_regenerate.php`) e a cobrir o Pix Automático
  (antes só boleto/Pix/cartão); removido um laço de JS sem nenhum efeito.
- `EfiClientFactory`: `redirectSdkCache()` verifica `function_exists('putenv')` antes de
  chamá-la — em hospedagem que desabilita `putenv` (mitigação comum contra bypass de
  `open_basedir`), isso antes derrubava TODA chamada à Efí com `Error: Call to undefined
  function`. `buildOptions()` passa a desligar o cache de token do SDK (`'cache' => false`): o
  token OAuth nunca mais chega ao disco, independentemente de `putenv` funcionar ou não; o
  redirecionamento de diretório (quando `putenv` está disponível) continua como defesa em
  profundidade para o diretório temporário que o construtor do SDK ainda cria.

### Segurança

- Cartão: proteção contra cobrança duplicada por reenvio (duplo clique, retry de rede, nova aba,
  cron concorrente com clique manual): uma nova tentativa é recusada enquanto já existir uma
  cobrança em andamento (`creating`/`waiting`/`approved`/`unknown`/`failed-unknown`) ou já
  concluída (`paid`) para a mesma fatura — tanto no checkout quanto na recorrência automática.
- Os botões de ação administrativa na fatura e na tela de configuração ("Tentar Capturar
  Pagamento", "Gerar nova cobrança Pix", "Testar Conexão") exigem um token CSRF por sessão, além
  da sessão de admin ativa.
- O cache de token OAuth do SDK da Efí é desligado por completo (`cache => false`); quando
  ainda disponível, o diretório temporário resolvido pelo SDK (certificado, etc.) sai do
  diretório compartilhado do sistema operacional para um diretório privado deste módulo.
- Scripts de terceiros carregados via CDN na página de cobrança de cartão (`payment-token-efi`,
  responsável por tokenizar o cartão no navegador, e o SweetAlert2) usam versão exata fixada e
  hash de integridade (Subresource Integrity), em vez de `@latest` sem verificação alguma.
- `vendor/` (SDK da Efí e dependências) nega acesso HTTP direto em Apache/LiteSpeed via
  `.htaccess` próprio; ver README para o equivalente em Nginx.

### Testes

Suíte dev-only em `tests/` (nunca enviada para o pacote de release — `tests/vendor/` e
`.phpunit.result.cache` ficam fora do Git), com `illuminate/database` simulando o `Capsule` do
WHMCS: testes unitários com SQLite em memória para a maior parte dos casos, e um teste de
concorrência real contra MySQL (via Docker/`services: mysql` do CI) para a trava por fatura do
`PaymentApplier`. Roda em todo push/PR (ver seção "Adicionado" acima).

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
