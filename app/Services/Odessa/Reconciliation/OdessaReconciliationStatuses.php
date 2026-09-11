<?php

namespace App\Services\Odessa\Reconciliation;

class OdessaReconciliationStatuses
{
    public const COMPLETE = 'REGISTRO_COMPLETO';

    public const EMAIL_DIFFERENT = 'EXISTE_CORREO_DIFERENTE';

    public const USER_WITHOUT_ODESSA = 'USUARIO_SIN_RELACION_ODESSA';

    public const AFFILIATE_WITHOUT_MEMBERSHIP = 'AFILIADO_SIN_MEMBRESIA';

    public const EXPIRED_MEMBERSHIP = 'MEMBRESIA_VENCIDA';

    public const NOT_FOUND = 'NO_REGISTRADO_EN_FAMEDIC';

    public const MANUAL_REVIEW = 'REVISAR_MANUALMENTE';

    public const DISCREPANCY = 'DISCREPANCIA';

    public const DELETED_RECORD = 'REGISTRO_ELIMINADO';
}
