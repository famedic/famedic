<?php

namespace App\Services\LaboratoryConfirmation;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class LaboratoryConfirmationTransport
{
    public function send(array $payload, string $attemptId): string
    {
        $name = config('laboratory-confirmation.mailer') ?: config('mail.default');
        $config = config("mail.mailers.{$name}");
        if (! is_array($config) || in_array($config['transport'] ?? '', ['failover', 'roundrobin'], true)) {
            throw new DeliveryNotAccepted('unsupported_mailer');
        }
        if (! app()->environment('testing') && in_array($config['transport'] ?? '', ['log', 'array', 'sendmail'], true)) {
            throw new DeliveryNotAccepted('unsupported_delivery_transport');
        }
        $config['timeout'] = max(1, min(30, (int) config('laboratory-confirmation.transport_timeout_seconds', 20)));
        $config['client'] = [...($config['client'] ?? []), 'timeout' => $config['timeout'], 'max_duration' => $config['timeout']];
        if (in_array($config['transport'] ?? '', ['ses', 'ses-v2'], true)) {
            $config['http'] = [...($config['http'] ?? []), 'timeout' => $config['timeout'], 'connect_timeout' => $config['timeout']];
            $config['retries'] = 0; // The ledger, not an SDK, owns retry decisions.
        }
        $mailer = Mail::build($config);
        // MailManager::build does not apply the global addresses used by resolve().
        foreach (['from', 'reply_to', 'to', 'return_path'] as $type) {
            $address = $config[$type] ?? config('mail.'.$type);
            if (is_array($address) && isset($address['address'])) {
                $mailer->{'always'.Str::studly($type)}($address['address'], $address['name'] ?? null);
            }
        }
        $transport = $mailer->getSymfonyTransport();
        if ($transport instanceof SmtpTransport) {
            // This mailer sends one message. Never restart after acceptance and mistake
            // a failed reconnect for rejection of the already accepted message.
            $transport->setRestartThreshold(PHP_INT_MAX);
        }
        try {
            $sent = $mailer->send(['html' => new HtmlString($payload['html']), 'text' => isset($payload['text']) ? new HtmlString($payload['text']) : null], [], function (Message $message) use ($payload, $attemptId) {
                $message->to($payload['recipient'])->subject($payload['subject']);
                $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $attemptId.'@confirmation.famedic.local');
            });
        } catch (UnexpectedResponseException $exception) {
            // An explicit negative SMTP reply is distinct from a disconnect/timeout.
            if ($exception->getCode() >= 400 && $exception->getCode() <= 599) {
                throw new DeliveryNotAccepted('smtp_rejected');
            }
            throw $exception;
        }
        if ($sent === null) {
            throw new DeliveryNotAccepted('transport_not_accepted');
        }

        return $sent->getMessageId();
    }
}
