<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use App\Services\Api\V1\Benefits\BenefitErrorResponse;
use App\Services\Api\V1\Benefits\FirstPartyBenefitConsent;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;

final class FirstPartyBenefitConsentController
{
    public function __construct(private FirstPartyBenefitConsent $consent, private BenefitApprovals $approvals) {}

    private function input(Request $r, bool $query = false): Request
    {
        if (! $r->isJson()) {
            $input = $query ? $r->query->all() : $r->request->all();
            unset($input['_token']);
            $r->setJson(new InputBag($input));
        }

        return $r;
    }

    public function page(Request $r)
    {
        if (! $this->approvals->consent()) {
            return $this->html(['disabled' => true]);
        }

        return $this->execute($r, function () use ($r) {
            $this->input($r, true);
            $data = $this->consent->review($r);

            return $this->reviewData($r, $data);
        }, true);
    }

    public function review(Request $r)
    {
        return $this->execute($r, function () use ($r) {
            $data = $this->consent->review($this->input($r));

            return $r->expectsJson() ? $data : $this->reviewData($r, $data);
        }, ! $r->expectsJson());
    }

    public function issue(Request $r)
    {
        return $this->execute($r, function () use ($r) {
            $ticket = null;
            if (! $r->isJson()) {
                $ticket = $r->request->get('web_intent');
                $stored = is_string($ticket) ? $r->session()->get('benefit_reviews.'.$ticket) : null;
                $form = array_diff_key($r->request->all(), array_flip(['_token', 'web_intent']));
                if (! $stored || $stored['expires_at'] <= now()->timestamp || ! hash_equals($stored['hash'], hash('sha256', json_encode($form, JSON_THROW_ON_ERROR)))) {
                    throw new BenefitFailure('CONFIRMATION_CONTEXT_CHANGED');
                }
                $r->request->remove('web_intent');
            }
            $result = $this->consent->issue($this->input($r));
            if ($ticket) {
                $r->session()->forget('benefit_reviews.'.$ticket);
            }

            return $result;
        });
    }

    private function reviewData(Request $r, array $data): array
    {
        $ticket = bin2hex(random_bytes(24));
        $form = $r->json()->all();
        $pending = $r->session()->get('benefit_reviews', []);
        $pending = array_filter($pending, fn ($v) => $v['expires_at'] > now()->timestamp);
        if (count($pending) >= 10) {
            array_shift($pending);
        }
        $pending[$ticket] = ['hash' => hash('sha256', json_encode($form, JSON_THROW_ON_ERROR)), 'expires_at' => strtotime($data['expires_at'])];
        $r->session()->put('benefit_reviews', $pending);

        return ['review' => $data, 'form' => $form + ['web_intent' => $ticket]];
    }

    public function revoke(Request $r, string $confirmation_ref)
    {
        return $this->execute($r, fn () => $this->consent->revoke($r, $confirmation_ref));
    }

    private function html(array $data)
    {
        $text = app(\App\Services\Api\V1\Benefits\ConsentTextRegistry::class)->active($this->approvals->textVersion());
        if ($text) {
            $data['confirmationText'] = $text->text;
        }

        return response()->view('benefits.consent', $data)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'", 'X-Content-Type-Options' => 'nosniff']);
    }

    private function execute(Request $r, callable $f, bool $page = false)
    {
        try {
            $data = $f();
            if ($page || ! $r->expectsJson()) {
                return $this->html($data);
            }$response = ApiResponse::success($data);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } catch (\Throwable $e) {
            $error = BenefitErrorResponse::make($e);
            if (! $r->expectsJson()) {
                return $this->html(['notice' => 'No pudimos completar esta confirmación. Vuelve a revisar el beneficio y confirma de nuevo tu contraseña si la sesión lo requiere.'])->setStatusCode($error->getStatusCode());
            }

            return $error;
        }
    }
}
