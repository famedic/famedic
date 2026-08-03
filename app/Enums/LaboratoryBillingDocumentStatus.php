<?php

namespace App\Enums;

enum LaboratoryBillingDocumentStatus: string
{
    case Complete = 'complete';
    case MissingPdf = 'missing_pdf';
    case MissingXml = 'missing_xml';
    case NoDocuments = 'no_documents';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Completa',
            self::MissingPdf => 'Falta PDF',
            self::MissingXml => 'Falta XML',
            self::NoDocuments => 'Sin documentos',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Complete => 'lime',
            self::MissingPdf => 'amber',
            self::MissingXml => 'amber',
            self::NoDocuments => 'zinc',
        };
    }
}
