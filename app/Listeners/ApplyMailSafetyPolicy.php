<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailables\Address as LaravelAddress;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;

class ApplyMailSafetyPolicy
{
    public const POLICY_NAME = 'MAIL_SAFE_MODE';

    public function handle(MessageSending $event): bool
    {
        if (app()->isProduction()) {
            return true;
        }

        if (! $this->isSafeModeEnabled()) {
            return true;
        }

        $message = $event->message;
        $allRecipients = $this->collectRecipients($message);
        $blockedRecipients = $this->findBlockedRecipients($allRecipients);

        if ($blockedRecipients === []) {
            return true;
        }

        if (config('mail.safe_mode.log_blocked')) {
            Log::warning(self::POLICY_NAME.': correo bloqueado por destinatario no permitido', [
                'environment' => app()->environment(),
                'subject' => $message->getSubject() ?? '',
                'recipients' => $allRecipients,
                'blocked_recipients' => $blockedRecipients,
                'policy' => self::POLICY_NAME,
            ]);
        }

        if (config('mail.safe_mode.block_disallowed')) {
            return false;
        }

        return true;
    }

    private function isSafeModeEnabled(): bool
    {
        $enabled = config('mail.safe_mode.enabled');

        if ($enabled === null || $enabled === '') {
            return true;
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<string>
     */
    private function collectRecipients(Email $message): array
    {
        return collect([
            ...$this->extractEmails($message->getTo()),
            ...$this->extractEmails($message->getCc()),
            ...$this->extractEmails($message->getBcc()),
        ])
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string>
     */
    private function findBlockedRecipients(array $recipients): array
    {
        $allowedRecipients = config('mail.safe_mode.allowed_recipients', []);
        $allowedDomains = config('mail.safe_mode.allowed_domains', []);

        return collect($recipients)
            ->reject(fn (string $email) => $this->isRecipientAllowed($email, $allowedRecipients, $allowedDomains))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $allowedRecipients
     * @param  list<string>  $allowedDomains
     */
    private function isRecipientAllowed(string $email, array $allowedRecipients, array $allowedDomains): bool
    {
        if (in_array($email, $allowedRecipients, true)) {
            return true;
        }

        $atPosition = strrpos($email, '@');

        if ($atPosition === false) {
            return false;
        }

        $domain = substr($email, $atPosition + 1);

        return $domain !== '' && in_array($domain, $allowedDomains, true);
    }

    /**
     * @param  array<int|string, mixed>|null  $addresses
     * @return list<string>
     */
    private function extractEmails(?array $addresses): array
    {
        if ($addresses === null || $addresses === []) {
            return [];
        }

        $emails = [];

        foreach ($addresses as $key => $value) {
            if ($value instanceof SymfonyAddress) {
                $emails[] = $value->getAddress();

                continue;
            }

            if ($value instanceof LaravelAddress) {
                $emails[] = $value->address;

                continue;
            }

            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $value;

                continue;
            }

            if (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $key;
            }
        }

        return $emails;
    }
}
