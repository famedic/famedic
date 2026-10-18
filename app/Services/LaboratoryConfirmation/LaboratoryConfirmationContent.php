<?php

namespace App\Services\LaboratoryConfirmation;

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Models\LaboratoryPurchase;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPresenter;

class LaboratoryConfirmationContent
{
    public function __construct(private readonly LaboratoryPreparationPresenter $presenter) {}

    public function presentation(LaboratoryPurchase $purchase): array
    {
        return $this->presenter->present($purchase);
    }

    public function snapshot(LaboratoryPurchase $purchase, array $presentation, bool $auto): array
    {
        if (! $auto) {
            // The presenter's purchase-item originals remain the only fallback source.
            $presentation['has_ai_summary'] = false;
            $presentation['ai_status'] = LaboratoryPreparationPresenter::STATUS_FALLBACK;
            $presentation['source'] = 'fallback';
            $presentation['summary'] = ['text' => null, 'sections' => [], 'special_instructions' => [], 'individual_instructions' => []];
        }
        $user = $purchase->customer?->user;
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('missing_recipient');
        }
        $data = LaboratoryPurchaseConfirmationViewData::build($purchase, $user, false, $presentation);
        $data['unifiedConfirmation'] = true;
        $withAppointment = LaboratoryPurchaseConfirmationViewData::hasAppointmentForConfirmation($purchase);
        $notification = new LaboratoryPurchaseCreated($purchase, $data, $withAppointment);
        $mail = $notification->toMail($user);

        $html = (string) $mail->render();
        return ['recipient' => $user->email, 'subject' => $mail->subject, 'html' => $html,
            'text' => $this->plainText($html)];
    }
    /** Derive the plain alternative from the same escaped HTML, preserving literal clinical text. */
    private function plainText(string $html): string
    {
        $document = new \DOMDocument;
        if (! $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException('confirmation_text_unavailable');
        }
        $render = function (\DOMNode $node) use (&$render): string {
            if ($node instanceof \DOMText) { return $node->nodeValue; }
            $tag = strtolower($node->nodeName);
            if (in_array($tag, ['head', 'style', 'script', 'title'], true)) { return ''; }
            if ($tag === 'br') { return "\n"; }
            $text = '';
            foreach ($node->childNodes as $child) { $text .= $render($child); }
            if ($tag === 'a' && $node instanceof \DOMElement && $node->hasAttribute('href')) {
                $url = $node->getAttribute('href');
                if ($url !== '' && trim($text) !== $url) { $text .= ' ('.$url.')'; }
            }
            if (in_array($tag, ['p', 'div', 'tr', 'li', 'h1', 'h2', 'h3', 'table'], true)) { $text = "\n".$text."\n"; }
            if (in_array($tag, ['td', 'th'], true)) { $text .= "\t"; }
            return $text;
        };
        $text = $render($document->getElementsByTagName('body')->item(0) ?? $document);
        $text = preg_replace('/[\t \x{00a0}]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }

}
