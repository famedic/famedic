<?php

namespace App\Notifications;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class LaboratoryPurchaseCreated extends Notification
{
    use Queueable;

    public function __construct(
        protected LaboratoryPurchase $laboratoryPurchase
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $purchase = $this->laboratoryPurchase->loadMissing([
            'laboratoryPurchaseItems',
            'transactions',
        ]);

        $transaction = $purchase->transactions->first();

        $studyLines = $purchase->laboratoryPurchaseItems->map(function (LaboratoryPurchaseItem $item) {
            return [
                'name' => $this->sanitizeTableCell($item->name),
                'price' => $item->formatted_price,
            ];
        })->all();

        $payment = $this->paymentPresentation($transaction);

        $instructionPayload = $this->buildInstructionSectionPayload($purchase->laboratoryPurchaseItems);

        return (new MailMessage)
            ->subject($this->buildSubject($purchase))
            ->markdown('mail.laboratory-purchase-created', [
                'purchase' => $purchase,
                'studyLines' => $studyLines,
                'payment' => $payment,
                'instructionSectionVisible' => $instructionPayload['visible'],
                'instructionSharedGroups' => $instructionPayload['shared_groups'],
                'instructionByStudy' => $instructionPayload['by_study'],
                'laboratory' => $purchase->laboratory,
                'orderUrl' => route('laboratory-purchases.show', $purchase),
                'storesUrl' => route('laboratory-stores.index', ['brand' => $purchase->brand->value]),
                'generalGuidelines' => $this->generalGuidelines(),
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }

    private function buildSubject(LaboratoryPurchase $purchase): string
    {
        return sprintf(
            'Confirmación de compra · estudios de laboratorio · Folio %s',
            $purchase->gda_order_id
        );
    }

    private function sanitizeTableCell(string $text): string
    {
        return str_replace('|', '·', $text);
    }

    /**
     * Construye bloques de instrucciones: por estudio (sin mezclar) y grupos opcionales cuando
     * la misma indicación textual aplica a varios estudios.
     *
     * @param  Collection<int, LaboratoryPurchaseItem>  $items
     * @return array{
     *     visible: bool,
     *     shared_groups: array<int, array{instruction: string, study_names: array<int, string>}>,
     *     by_study: array<int, array{study_name: string, lines: array<int, string>}>
     * }
     */
    private function buildInstructionSectionPayload(Collection $items): array
    {
        /** @var array<string, array<int, string>> $byStudyName nombre de estudio => líneas únicas */
        $byStudyName = [];

        foreach ($items as $item) {
            $lines = $this->extractInstructionLinesFromItem($item);
            if ($lines === []) {
                continue;
            }

            $studyName = trim((string) $item->name);
            if ($studyName === '') {
                $studyName = 'Estudio sin nombre';
            }

            if (! isset($byStudyName[$studyName])) {
                $byStudyName[$studyName] = [];
            }

            $byStudyName[$studyName] = array_values(array_unique(array_merge($byStudyName[$studyName], $lines)));
        }

        if ($byStudyName === []) {
            return [
                'visible' => false,
                'shared_groups' => [],
                'by_study' => [],
            ];
        }

        /** @var array<string, array{display: string, studies: array<string, true>}> */
        $lineKeyToMeta = [];

        foreach ($byStudyName as $studyName => $lines) {
            foreach ($lines as $line) {
                $key = $this->normalizeInstructionKey($line);
                if ($key === '') {
                    continue;
                }
                if (! isset($lineKeyToMeta[$key])) {
                    $lineKeyToMeta[$key] = [
                        'display' => $line,
                        'studies' => [],
                    ];
                }
                $lineKeyToMeta[$key]['studies'][$studyName] = true;
            }
        }

        $sharedKeys = [];
        foreach ($lineKeyToMeta as $key => $meta) {
            if (count($meta['studies']) >= 2) {
                $sharedKeys[$key] = true;
            }
        }

        $sharedGroups = [];
        foreach ($sharedKeys as $key => $_) {
            $meta = $lineKeyToMeta[$key];
            $studyNames = array_keys($meta['studies']);
            sort($studyNames, SORT_NATURAL | SORT_FLAG_CASE);
            $sharedGroups[] = [
                'instruction' => $meta['display'],
                'study_names' => $studyNames,
            ];
        }

        usort($sharedGroups, fn ($a, $b) => strcmp($a['instruction'], $b['instruction']));

        /** Quitar de cada estudio las líneas que ya salen en grupo compartido */
        foreach ($byStudyName as $studyName => $lines) {
            $byStudyName[$studyName] = array_values(array_filter($lines, function (string $line) use ($sharedKeys) {
                $key = $this->normalizeInstructionKey($line);

                return $key === '' || ! isset($sharedKeys[$key]);
            }));
        }

        $byStudyBlocks = [];
        foreach ($byStudyName as $studyName => $lines) {
            if ($lines === []) {
                continue;
            }
            $byStudyBlocks[] = [
                'study_name' => $studyName,
                'lines' => array_values($lines),
            ];
        }

        usort($byStudyBlocks, fn ($a, $b) => strcmp($a['study_name'], $b['study_name']));

        $visible = $sharedGroups !== [] || $byStudyBlocks !== [];

        return [
            'visible' => $visible,
            'shared_groups' => $sharedGroups,
            'by_study' => $byStudyBlocks,
        ];
    }

    /**
     * Usa `instructions` si existe (array o texto); si no, `indications` del ítem de compra.
     *
     * @return array<int, string>
     */
    private function extractInstructionLinesFromItem(LaboratoryPurchaseItem $item): array
    {
        $raw = $item->getAttribute('instructions');

        if ($raw === null) {
            $raw = $item->indications;
        }

        if (is_array($raw)) {
            $all = [];
            foreach ($raw as $fragment) {
                if (is_string($fragment)) {
                    $all = array_merge($all, $this->extractInstructionLines($fragment));
                }
            }

            return array_values(array_unique($all));
        }

        return $this->extractInstructionLines(is_string($raw) ? $raw : null);
    }

    private function normalizeInstructionKey(string $line): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $line));

        if ($t === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($t, 'UTF-8')
            : strtolower($t);
    }

    /**
     * @return array<int, string>
     */
    private function extractInstructionLines(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $normalized = str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $raw);
        $text = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return collect($lines)
            ->map(fn (string $l) => trim(preg_replace('/\s+/u', ' ', $l)))
            ->filter(fn (string $l) => $l !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, last_four: ?string, paid: bool, status_text: string}
     */
    private function paymentPresentation(?Transaction $transaction): array
    {
        if (! $transaction) {
            return [
                'label' => 'No disponible en este comprobante',
                'last_four' => null,
                'paid' => false,
                'status_text' => 'En verificación',
            ];
        }

        $details = is_array($transaction->details) ? $transaction->details : [];
        $methodKey = $transaction->payment_method ?? $transaction->gateway;

        $methodLabel = match ($methodKey) {
            'efevoopay' => 'Tarjeta bancaria (EfevooPay)',
            'stripe' => 'Tarjeta bancaria',
            'paypal' => 'PayPal',
            'odessa' => 'Caja de ahorro Odessa (saldo)',
            default => $methodKey ? ucfirst((string) $methodKey) : 'No especificado',
        };

        $lastFour = $details['token_info']['card_last_four']
            ?? $details['card_last_four']
            ?? null;

        $paid = $this->transactionIsPaid($transaction);
        $statusText = $paid
            ? 'Pagado'
            : $this->paymentStatusLabel($transaction->payment_status);

        return [
            'label' => $methodLabel,
            'last_four' => $lastFour ? (string) $lastFour : null,
            'paid' => $paid,
            'status_text' => $statusText,
        ];
    }

    private function transactionIsPaid(Transaction $transaction): bool
    {
        $status = strtolower((string) ($transaction->payment_status ?? ''));

        if (in_array($status, ['captured', 'completed', 'paid', 'credit', 'succeeded'], true)) {
            return true;
        }

        if (in_array($status, ['failed', 'declined', 'cancelled', 'canceled'], true)) {
            return false;
        }

        if ($status === 'pending') {
            return false;
        }

        if (($transaction->payment_method === 'efevoopay' || $transaction->gateway === 'efevoopay')
            && $transaction->gateway_transaction_id) {
            return true;
        }

        return true;
    }

    private function paymentStatusLabel(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'pending' => 'Pendiente de confirmación',
            'failed' => 'No completado',
            'captured', 'completed', 'paid' => 'Pagado',
            '' => 'En verificación',
            default => (string) $status,
        };
    }

    /**
     * @return array<int, string>
     */
    private function generalGuidelines(): array
    {
        return [
            'Presenta en sucursal el folio de esta orden o muestra este comprobante desde tu cuenta.',
            'Lleva identificación oficial del paciente.',
            'Llega con anticipación si cuentas con cita u horario acordado.',
            'Mantente hidratado antes de tus estudios, salvo que el laboratorio o tu médico indiquen ayuno u otra restricción.',
        ];
    }
}
