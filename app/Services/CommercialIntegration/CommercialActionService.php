<?php

namespace App\Services\CommercialIntegration;

use App\Models\ClinicalCommercialDraft;
use App\Models\User;
use App\Services\ClinicalOrder\ClinicalOrderService;

/**
 * Commercial actions now persist a Clinical Order and derive cart/quote payloads from it.
 * CommercialIntegrationEngine (proposal math) remains unchanged.
 */
class CommercialActionService
{
    public function __construct(
        private CommercialIntegrationEngine $engine,
        private ClinicalOrderService $clinicalOrders,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $validatedItems
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function proposal(array $validatedItems, ?string $sessionId = null, ?User $operator = null, array $context = []): array
    {
        $proposal = $this->engine->buildProposal($validatedItems, $sessionId);

        if (! $operator) {
            return [
                'proposal' => $proposal,
                'clinical_order' => null,
            ];
        }

        $preview = $this->clinicalOrders->preview($operator, array_merge($context, [
            'session_id' => $sessionId,
            'validation_items' => $validatedItems,
        ]));

        return [
            'proposal' => $proposal,
            'clinical_order' => $preview['summary'],
            'clinical_order_detail' => $preview['clinical_order'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function saveDraft(User $operator, array $context): array
    {
        $order = $this->clinicalOrders->save($operator, array_merge($context, [
            'status' => 'draft',
        ]));

        $this->mirrorDraft($operator->id, $order->session_id, 'draft', $order);

        return [
            'ok' => true,
            'clinical_order' => $order->toSummaryArray(),
            'clinical_order_detail' => $order->toDetailArray(),
            'proposal' => $this->engine->buildProposal(
                $context['validation_items'] ?? [],
                $order->session_id
            ),
            'draft_id' => $order->id,
            'message' => 'Clinical Order guardada.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function prepareQuote(User $operator, array $context): array
    {
        $order = $this->clinicalOrders->save($operator, array_merge($context, [
            'status' => 'validated',
        ]));
        $order = $this->clinicalOrders->markQuotePrepared($order);
        $this->mirrorDraft($operator->id, $order->session_id, 'quote_prepared', $order);

        return [
            'ok' => true,
            'clinical_order' => $order->toSummaryArray(),
            'clinical_order_detail' => $order->toDetailArray(),
            'quote_payload' => $order->quote_payload,
            'proposal' => $this->engine->buildProposal(
                $context['validation_items'] ?? [],
                $order->session_id
            ),
            'draft_id' => $order->id,
            'status' => 'quote_prepared',
            'message' => 'Clinical Order actualizada · cotización preparada desde la orden.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function prepareCart(User $operator, array $context): array
    {
        $order = $this->clinicalOrders->save($operator, array_merge($context, [
            'status' => 'validated',
        ]));
        $order = $this->clinicalOrders->markCartPrepared($order);
        $this->mirrorDraft($operator->id, $order->session_id, 'cart_prepared', $order);

        return [
            'ok' => true,
            'clinical_order' => $order->toSummaryArray(),
            'clinical_order_detail' => $order->toDetailArray(),
            'cart_payload' => $order->cart_payload,
            'proposal' => $this->engine->buildProposal(
                $context['validation_items'] ?? [],
                $order->session_id
            ),
            'draft_id' => $order->id,
            'status' => 'cart_prepared',
            'message' => 'Clinical Order actualizada · carrito preparado desde la orden.',
        ];
    }

    private function mirrorDraft(int $userId, ?string $sessionId, string $status, $order): void
    {
        ClinicalCommercialDraft::query()->create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'status' => $status,
            'proposal' => [
                'clinical_order_id' => $order->id,
                'clinical_order_uuid' => $order->uuid,
                'summary' => $order->commercial['summary'] ?? [],
                'cart_payload' => $order->cart_payload,
                'quote_payload' => $order->quote_payload,
            ],
            'subtotal_cents' => $order->total_cents,
            'discount_cents' => $order->discount_cents,
            'total_cents' => $order->total_cents,
        ]);
    }
}
