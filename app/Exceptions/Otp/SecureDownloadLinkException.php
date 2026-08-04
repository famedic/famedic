<?php

namespace App\Exceptions\Otp;

class SecureDownloadLinkException extends OtpChallengeException
{
    /**
     * @param  array{
     *     purpose?: string,
     *     secure_link_row_id?: int,
     *     step_up_row_id?: int,
     *     resource_type?: string,
     *     resource_id?: int,
     *     laboratory_purchase_row_id?: int,
     *     order_row_id?: int,
     *     invoice_row_id?: int,
     *     max_opens?: int,
     *     open_count?: int
     * }|null  $auditContext  Safe internal IDs only — never tokens/URLs/public ids.
     */
    public function __construct(
        string $message,
        string $errorCode,
        public readonly int $httpStatus = 400,
        public readonly ?array $auditContext = null,
    ) {
        parent::__construct($message, $errorCode);
    }
}
