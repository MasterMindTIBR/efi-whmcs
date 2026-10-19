<?php

namespace EfiWhmcs\Webhook;

use Efi\EfiPay;
use Efi\Exception\EfiException;
use EfiWhmcs\Ledger\ChargeRepository;
use EfiWhmcs\Ledger\EventRepository;
use EfiWhmcs\Support\GatewayLog;

/**
 * Processador de notificação Pix.
 *
 * Diferente da API de Cobranças, a API Pix empurra o payload diretamente no corpo do POST (é o
 * padrão definido pelo Banco Central), protegido por mTLS na camada de transporte. Como defesa
 * em profundidade dentro da aplicação (a mTLS é configurada no servidor web, fora do alcance
 * deste módulo -- ver README.md, seção "Pix" e "Nginx/Apache"), NUNCA confiamos no valor/status do payload recebido:
 * toda notificação é confirmada consultando `pixDetailCharge` com nossas credenciais antes de
 * qualquer efeito ser aplicado, e o parâmetro `?ws=` da URL cadastrada é validado antes de gastar
 * uma chamada de API.
 */
final class PixNotificationProcessor
{
    public function __construct(private readonly EfiPay $api, private readonly array $gatewayParams)
    {
    }

    /**
     * @param string $rawBody corpo bruto do POST (JSON)
     * @return array<int, array{charge: object, status: string, valueCents: int, e2eId: string}>
     */
    public function fetchNewTransitions(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        $entries = $payload['pix'] ?? null;

        if ((!is_array($entries) || $entries === []) && empty($payload['devolucoes'])) {
            // Notificação de teste do cadastro do webhook, ou payload sem entradas -- não é erro.
            return [];
        }

        $transitions = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $this->recordDevolutions($entry, $entry['devolucoes'] ?? []);
            $txid = (string) ($entry['txid'] ?? '');
            $e2eId = (string) ($entry['endToEndId'] ?? '');

            if ($txid === '' || $e2eId === '') {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'Entrada Pix malformada ignorada', $entry);
                continue;
            }

            $charge = ChargeRepository::findByEfiChargeId($txid);

            if ($charge === null) {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'txid sem correspondência no ledger local', $entry);
                continue;
            }

            $claimed = EventRepository::tryClaim($charge->id, $e2eId, $charge->status, 'CONCLUIDA', $txid);

            if (!$claimed) {
                continue; // reentrega -- já processado.
            }

            // Nunca confiar no `valor` do payload: busca o estado autoritativo via API.
            try {
                $detail = $this->api->pixDetailCharge(['txid' => $txid]);
            } catch (EfiException $e) {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'Falha ao confirmar cobrança Pix (' . $e->code . ')', [
                    'txid' => $txid,
                    'error' => $e->errorDescription,
                ]);
                continue;
            }

            $pixRecebidos = $detail['pix'] ?? [];
            $confirmed = null;

            foreach ($pixRecebidos as $recebido) {
                if (($recebido['endToEndId'] ?? null) === $e2eId) {
                    $confirmed = $recebido;
                    break;
                }
            }

            if ($confirmed === null) {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'e2eId do webhook não encontrado na consulta autoritativa -- pagamento NÃO creditado', [
                    'txid' => $txid,
                    'e2eId' => $e2eId,
                ]);
                continue;
            }

            $transitions[] = [
                'charge' => $charge,
                'eventId' => $claimed,
                'status' => (string) ($detail['status'] ?? 'CONCLUIDA'),
                'valueCents' => (int) round(((float) $confirmed['valor']) * 100),
                'e2eId' => $e2eId,
            ];
        }

        // Some Efí webhook integrations send received devolutions at the top level rather than
        // nested below the original Pix. They carry the same endToEndId as the payment.
        $this->recordDevolutions([], $payload['devolucoes'] ?? []);

        return $transitions;
    }

    /** Records a received devolution/MED without making an accounting reversal in WHMCS. */
    private function recordDevolutions(array $pix, mixed $devolutions): void
    {
        if (!is_array($devolutions)) {
            return;
        }

        foreach ($devolutions as $devolution) {
            if (!is_array($devolution)) {
                continue;
            }

            $rtrId = (string) ($devolution['rtrId'] ?? '');
            $e2eId = (string) ($devolution['endToEndId'] ?? $pix['endToEndId'] ?? '');
            $txid = (string) ($devolution['txid'] ?? $pix['txid'] ?? '');

            if ($rtrId === '' || ($txid === '' && $e2eId === '')) {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'Devolução Pix malformada ignorada', $devolution);
                continue;
            }

            $charge = $txid !== '' ? ChargeRepository::findByEfiChargeId($txid) : ChargeRepository::findByPixEndToEndId($e2eId);
            if ($charge === null) {
                GatewayLog::error('efi_pix', $this->gatewayParams, 'Devolução Pix sem correspondência no ledger local', $devolution);
                continue;
            }

            $eventId = EventRepository::tryClaim(
                (int) $charge->id,
                'devolucao:' . $rtrId,
                $charge->status,
                (string) ($devolution['status'] ?? 'NAO_REALIZADO'),
                (string) $charge->efi_charge_id
            );

            if ($eventId === null) {
                continue;
            }

            EventRepository::markApplied($eventId);
            GatewayLog::error('efi_pix', $this->gatewayParams, 'Devolução Pix/MED recebida — revisão manual necessária', [
                'invoice_id' => $charge->invoice_id,
                'charge_id' => $charge->id,
                'txid' => $charge->efi_charge_id,
                'endToEndId' => $e2eId,
                'rtrId' => $rtrId,
                'status' => $devolution['status'] ?? null,
                'payload' => $devolution,
            ]);
        }
    }
}
