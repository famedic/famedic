<?php

namespace App\Exceptions\TaxProfiles;

use RuntimeException;

class ConstanciaExtractionException extends RuntimeException
{
    public const LEGAL_ENTITY_NOT_ALLOWED = 'TAX_PROFILE_LEGAL_ENTITY_NOT_ALLOWED';

    public const INVALID_DOCUMENT = 'TAX_CERTIFICATE_INVALID_DOCUMENT';

    public const PROTECTED = 'TAX_CERTIFICATE_PROTECTED';

    public const UNREADABLE = 'TAX_CERTIFICATE_UNREADABLE';

    public const NOT_CSF = 'TAX_CERTIFICATE_NOT_CSF';

    public const EXTRACTION_FAILED = 'TAX_CERTIFICATE_EXTRACTION_FAILED';

    public const EXTRACTION_TIMEOUT = 'TAX_CERTIFICATE_EXTRACTION_TIMEOUT';

    public const RATE_LIMITED = 'TAX_CERTIFICATE_RATE_LIMITED';

    public const ALREADY_PROCESSING = 'TAX_CERTIFICATE_ALREADY_PROCESSING';

    public const INCONSISTENT_DATA = 'TAX_CERTIFICATE_INCONSISTENT_DATA';

    public function __construct(
        public readonly string $errorCode,
        string $publicMessage,
        public readonly int $status = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    public static function legalEntityNotAllowed(): self
    {
        return new self(
            self::LEGAL_ENTITY_NOT_ALLOWED,
            'La constancia corresponde a una persona moral. Actualmente, Famedic solo permite facturación a personas físicas.',
            422,
        );
    }

    public static function invalidDocument(string $message = 'El archivo de constancia no es válido. Debe ser un PDF de máximo 5 MB.'): self
    {
        return new self(self::INVALID_DOCUMENT, $message, 422);
    }

    public static function protectedDocument(): self
    {
        return new self(
            self::PROTECTED,
            'No pudimos leer la constancia porque el PDF está protegido. Usa un PDF sin contraseña o captura los datos manualmente.',
            422,
        );
    }

    public static function unreadable(): self
    {
        return new self(
            self::UNREADABLE,
            'No pudimos leer el contenido de la constancia. Si es un escaneo, captura los datos manualmente.',
            422,
        );
    }

    public static function notCsf(): self
    {
        return new self(
            self::NOT_CSF,
            'El archivo no parece ser una Constancia de Situación Fiscal. Sube el PDF emitido por el SAT o captura los datos manualmente.',
            422,
        );
    }

    public static function extractionFailed(): self
    {
        return new self(
            self::EXTRACTION_FAILED,
            'No pudimos extraer los datos de la constancia. Puedes capturarlos manualmente.',
            422,
        );
    }

    public static function extractionTimeout(): self
    {
        return new self(
            self::EXTRACTION_TIMEOUT,
            'La extracción tardó demasiado. Intenta de nuevo o captura los datos manualmente.',
            422,
        );
    }

    public static function alreadyProcessing(): self
    {
        return new self(
            self::ALREADY_PROCESSING,
            'Ya hay una extracción en curso. Espera un momento e intenta de nuevo.',
            429,
        );
    }

    public static function inconsistentData(): self
    {
        return new self(
            self::INCONSISTENT_DATA,
            'Los datos de la constancia son inconsistentes. Revisa el archivo o captura los datos manualmente.',
            422,
        );
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
