<?php

namespace App\Support;

use App\Contracts\EfevooPayGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class LocalQaPreflight
{
    public static function inspect(bool $initial = false, bool $ready = false): array
    {
        if (! LocalCheckoutQa::enabled()) { return ['ok' => false, 'issues' => ['local_qa_disabled'], 'fingerprint' => LocalCheckoutQa::fingerprint()]; }
        $issues = [];
        if (! LocalCheckoutQa::enabled()) { $issues[] = 'local_qa_disabled'; }
        try {
            $recipient = LocalCheckoutQa::recipient();
            $users = User::whereRaw('LOWER(email) = ?', [$recipient])->get();
            if ($users->count() !== 1) { $issues[] = 'exactly_one_existing_qa_user_required'; }
            $user = $users->first();
            if ($user && (! $user->customer || ! $user->profile_is_complete || ! $user->has_verified_phone
                || ! $user->email_verified_at || ! $user->documentation_accepted_at)) { $issues[] = 'existing_verified_qa_customer_required'; }
        } catch (\Throwable) { $issues[] = 'local_qa_recipient_required'; }
        if (! app(EfevooPayGateway::class) instanceof \App\Services\EfevooPay\MockEfevooPayGateway) { $issues[] = 'mock_gateway_required'; }
        if (! app(\App\Services\OpenAi\OpenAiClient::class) instanceof LocalQaOpenAiClient) { $issues[] = 'mock_redactor_required'; }
        if (! app('mail.manager') instanceof LocalQaMailManager) { $issues[] = 'guarded_mail_manager_required'; }
        if (config('queue.default') !== 'database' || config('laboratory-confirmation.connection') !== 'database') { $issues[] = 'database_queue_required'; }
        if ((int) config('queue.connections.database.retry_after') <= 120) { $issues[] = 'retry_after_must_exceed_120'; }
        if (config('laboratory-confirmation.queue') !== 'laboratory-confirmations-qa'
            || config('laboratory-confirmation.generation_queue') !== 'laboratory-preparation-qa') { $issues[] = 'exclusive_qa_queues_required'; }
        if (! config('services.laboratory_preparation.deterministic_v3_enabled')
            || config('services.laboratory_preparation.deterministic_v3_shadow_enabled')) { $issues[] = 'deterministic_v3_required_without_shadow'; }
        if (! in_array(config('local-checkout-qa.scenario'), ['auto', 'fallback', 'late', 'error'], true)) { $issues[] = 'scenario_invalid'; }
        $mail = config('mail.mailers.'.config('mail.default'), []);
        if (! empty($mail['url'])) {
            $mail = (new \Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($mail);
            $mail['transport'] = $mail['driver'] ?? '';
        }
        if (($mail['transport'] ?? '') !== 'smtp' || strtolower($mail['host'] ?? '') !== 'smtp.gmail.com') { $issues[] = 'gmail_smtp_required'; }
        $ledger = DB::table('laboratory_purchase_confirmations')->count();
        $frontiers = DB::table('laboratory_confirmation_rollouts')->count();
        $queues = DB::table('jobs')->select('queue')->selectRaw('COUNT(*) as count')->groupBy('queue')->pluck('count', 'queue')->all();
        if ($initial && ($ledger || $frontiers || ($queues['laboratory-confirmations-qa'] ?? 0) || ($queues['laboratory-preparation-qa'] ?? 0))) { $issues[] = 'initial_state_not_empty_review_before_activation'; }
        if ($initial && config('laboratory-confirmation.enabled')) { $issues[] = 'initial_coordinator_must_be_off'; }
        if ($initial && filled(config('laboratory-confirmation.activated_at'))) { $issues[] = 'initial_activation_must_be_empty'; }
        if (! \App\Models\AiPrompt::where('domain', 'laboratory')->where('key', 'laboratory_preparation_redactor')->where('status', 'active')->exists()) { $issues[] = 'active_redactor_prompt_required'; }
        $activation = app(\App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator::class)->activation();
        if ($ready && $frontiers === 0 && $activation?->lessThan(now()->subMinutes(10))) { $issues[] = 'fresh_activation_required'; }
        $stored = DB::table('laboratory_confirmation_rollouts')->where('key', 'purchase_confirmation')->value('activated_at');
        if ($stored && $activation && ! \Carbon\CarbonImmutable::parse($stored, 'UTC')->equalTo($activation)) { $issues[] = 'stored_frontier_must_not_be_changed'; }

        if ($ready && ! app(\App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator::class)->activation()) { $issues[] = 'coordinator_not_active'; }
        return ['ok' => $issues === [], 'issues' => $issues, 'fingerprint' => LocalCheckoutQa::fingerprint(),
            'qa' => LocalCheckoutQa::enabled(), 'scenario' => config('local-checkout-qa.scenario'),
            'coordinator_enabled' => (bool) config('laboratory-confirmation.enabled'),
            'activated_at' => config('laboratory-confirmation.activated_at'),
            'retry_after' => config('queue.connections.database.retry_after'),
            'confirmations' => $ledger, 'frontiers' => $frontiers, 'queues' => $queues];
    }
}
