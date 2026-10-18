<?php

namespace App\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class LocalQaTransport implements TransportInterface
{
    public function __construct(private TransportInterface $inner)
    {
        if ($inner instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport) {
            $inner->setRestartThreshold(PHP_INT_MAX);
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (LocalCheckoutQa::enabled()) {
            if (! $message instanceof Email) {
                throw new \RuntimeException('local_qa_message_type_blocked');
            }
            LocalCheckoutQa::assertRecipients([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()]);
            LocalCheckoutQa::assertRecipients(($envelope ?? Envelope::create($message))->getRecipients());
        }
        return $this->inner->send($message, $envelope);
    }

    public function __toString(): string { return 'local-qa-guard'; }
}
