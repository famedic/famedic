<?php

namespace App\Data\Api\V1\Uat;

final class AkubicaUatFixtureContract
{
    public const NAMESPACE = 'akubica-uat-v1';

    public const FIXTURE_VERSION = 1;

    public const STORAGE_DISK = 'local';

    public const STORAGE_PREFIX = self::NAMESPACE.'/';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESETTING = 'resetting';

    public const STATUS_FAILED = 'failed';

    public const ALLOWED_STATUSES = [
        self::STATUS_PREPARING,
        self::STATUS_ACTIVE,
        self::STATUS_RESETTING,
        self::STATUS_FAILED,
    ];

    public const STORAGE_DOCUMENTS = [
        'results/result-ready.pdf',
        'results/foreign-order.pdf',
        'invoices/invoice-ready.pdf',
        'invoices/invoice-ready.xml',
        'invoices/foreign-order.pdf',
        'invoices/foreign-order.xml',
        'tax/fiscal-certificate.pdf',
    ];

    public static function storagePath(string $relativeDocument): string
    {
        return self::STORAGE_PREFIX.$relativeDocument;
    }
}
