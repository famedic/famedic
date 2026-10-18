<?php

namespace App\Support;

use Illuminate\Mail\MailManager;

final class LocalQaMailManager extends MailManager
{
    public function createSymfonyTransport(array $config)
    {
        if (! LocalCheckoutQa::enabled()) {
            return parent::createSymfonyTransport($config);
        }
        // Fail closed for API, sendmail, custom, failover and alternative SMTP hosts.
        $driver = $config['transport'] ?? '';
        if ($driver !== 'smtp' || strtolower($config['host'] ?? '') !== 'smtp.gmail.com') {
            throw new \RuntimeException('local_qa_transport_blocked');
        }
        return new LocalQaTransport(parent::createSymfonyTransport($config));
    }
}
