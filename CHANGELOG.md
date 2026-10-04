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
- Opção "Pular verificação mTLS no cadastro do webhook" para os gateways Pix e Pix Automático
  (útil em hospedagem compartilhada que não suporta apresentar o certificado cliente da Efí),
  com checagem do IP de origem documentado pela Efí como medida compensatória quando ativa.

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
- Removida a opção "3D Secure obrigatório acima de (R$)" do gateway de cartão: nenhum fluxo de
  3DS é implementado por este módulo e o campo nunca teve efeito algum; mantê-lo configurável
  passava ao lojista a falsa impressão de que cobranças acima do valor definido tinham essa
  proteção.

### Corrigido

- Checkout de cartão agora mantém a resposta AJAX em JSON quando o log completo está ativo; uma
  resposta inválida é exibida ao cliente como `Erro desconhecido.`.
- Hook Efí carrega funções de gateway apenas dentro dos callbacks que precisam delas, impedindo
  a redeclaração de `loadgatewaymodule()` durante a renderização de faturas.
- Cobranças de cartão agora usam o endpoint oficial `POST /v1/charge/one-step`; falhas HTTP da
  API não são mais apresentadas como recusa da operadora.
- Checkout e recorrência de cartão enviam `phone_number` obrigatório, validado a partir do
  telefone cadastrado no cliente do WHMCS.
- Salvar cartão não falha mais após uma cobrança aprovada: a validade (`mmyy`) agora é enviada a
  `createCardPayMethod`, que exigia o formato e rejeitava o valor nulo anterior com
  `Invalid Expiry Date`.
- Boleto: a emissão agora envia `juridical_person`/`corporate_name` quando o documento do
  cliente é CNPJ (antes a Efí rejeitava ou tratava a cobrança como se fosse de pessoa física).
- Boleto: reemitir após cancelamento atualiza (upsert) o registro já existente no ledger em vez
  de criar um novo, evitando um boleto "órfão" apontando para uma cobrança já cancelada.
- Boleto: o valor agora é realinhado junto com o vencimento quando o total da fatura muda; antes
  só a data era realinhada, e o boleto ficava com o valor antigo.
- Pix Automático: a CobR agora é agendada dentro da janela de 2 a 10 dias antes do vencimento
  exigida pelo Bacen, em vez de só ser criada no gatilho `InvoiceCreated` (que podia cair fora
  da janela aceita).
- Pix Automático: usa o valor mínimo aceito pelo recebedor (`valorMinimoRecebedor`) em vez de um
  valor fixo (`valorRec`), permitindo reajuste de preço sem travar a cobrança recorrente.
- Pix Automático: uma CobR que volta com status de erro agora é reprocessada automaticamente, e
  o admin é avisado no log quando não é possível agendar.
- Boleto/Pix/CobR agora também são cancelados nos eventos `InvoicePaid`, `InvoiceDeleted` e
  `InvoiceRefunded` (antes só em `InvoiceCancelled`), evitando uma cobrança pendente numa fatura
  que deixou de estar em aberto por outro motivo.
- A reconciliação diária (rede de segurança contra falha pontual de callback) passou a olhar uma
  janela maior e também cobranças de cartão com status `waiting`.
- Pix: o id de devolução enviado em `pixDevolution` agora é só alfanumérico e determinístico.
  Antes usava `uniqid()`, que gera `_` e `.` -- caracteres rejeitados pelo Bacen --, de modo que
  todo estorno de Pix falhava; o novo id também evita duplicar o estorno numa nova tentativa.
- Pix: o desconto configurado agora é lançado como item negativo na fatura (`UpdateInvoice`)
  assim que o pagamento é confirmado, em vez de creditar um valor menor que o total. Antes a
  fatura nunca era marcada como paga quando havia desconto, porque o saldo (o próprio desconto)
  ficava em aberto.
- Pix: uma cobrança imediata expirada agora é renovada automaticamente ao reabrir a fatura. A
  Efí nunca muda o status de uma cobrança sozinha quando ela expira; o módulo passou a rastrear
  a validade (`expires_at`) e substitui a cobrança por uma nova quando necessário.
- Cartão: o callback assíncrono agora atualiza o status local do ledger ANTES de validar o
  transid junto ao WHMCS. Essa validação pode encerrar o script inteiro quando vê um transid
  repetido, o que deixava o ledger local parado no status antigo e, se a notificação trouxesse
  mais de uma cobrança no mesmo lote, abortava o processamento das demais.
- Hooks e callbacks que leem configuração de gateway nunca mais derrubam a requisição inteira
  (erro 500) quando o módulo correspondente nunca foi salvo em Configurações > Pagamentos; cada
  um agora responde com uma mensagem de erro própria.

### Segurança

- Cartão: o valor cobrado é sempre calculado no servidor a partir do total da fatura, nunca a
  partir do campo enviado pelo navegador. Antes um cliente podia cobrar qualquer valor no
  próprio cartão e ainda assim fazer o WHMCS marcar a fatura inteira como paga.
- Cartão: proteção contra cobrança duplicada por reenvio (duplo clique, retry de rede, nova aba):
  uma nova tentativa é recusada enquanto já existir uma cobrança em andamento (`waiting`) ou já
  concluída para a mesma fatura.
- Cartão: os botões de ação administrativa na fatura ("Tentar Capturar Pagamento" e "Gerar nova
  cobrança Pix") agora exigem um token CSRF por sessão, além da sessão de admin ativa; a captura
  de pagamento também passou a recusar a ação quando a sessão não tem um usuário administrador
  identificado (antes caía silenciosamente para uma chamada de API sem restrição de permissão).
- O cache de token OAuth e o caminho resolvido do certificado mTLS do SDK da Efí saem do
  diretório temporário compartilhado do sistema operacional para um diretório privado deste
  módulo -- relevante em hospedagem compartilhada, onde `/tmp` pode ser acessado por outros
  sites/processos da mesma máquina.
- Scripts de terceiros carregados via CDN na página de cobrança de cartão (`payment-token-efi`,
  responsável por tokenizar o cartão no navegador, e o SweetAlert2) agora usam versão exata
  fixada e hash de integridade (Subresource Integrity), em vez de `@latest` sem verificação
  alguma.

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
