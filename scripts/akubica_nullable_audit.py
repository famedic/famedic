"""Explicit 26-field evidence register. No blanket nullable replacement."""
from copy import deepcopy

PREFIX = 'properties/'


def row(identifier, path, action, evidence, behavior, consumer):
    return {'id': identifier, 'path': path, 'action': action, 'evidence': evidence,
            'runtime_or_request_behavior': behavior, 'consumer_impact': consumer}


ROWS = [
    row('N01','PhoneLookupRecognizedData/properties/personalization/properties/first_name','REMOVE_ANNOTATION',
        'app/Support/Api/V1/AtixPhoneLookupRecognitionPayload.php:16-20',
        'Omitido si no hay nombre permitido; no se serializa null en este productor.',
        'Leo/SDK: propiedad opcional string; no sustituir ausencia por null.'),
]
for identifier, field in zip(range(2,8), ['plan_code','plan_name','source','starts_at','expires_at','ineligibility_reason']):
    ROWS.append(row(f'N{identifier:02d}', 'MembershipStatus/properties/'+field, 'ALLOW_NULL',
        'app/Services/Api/V1/MembershipPlanCatalogService.php:62-70,100-106',
        'Null explicito para cuenta sin membresia; valores adicionales nulos para planes patrocinados o fechas ausentes.',
        'Leo/SDK/memberships status: aceptar valor null sin confundirlo con expiracion o falta de propiedad.'))
ROWS += [
    row('N08','LaboratoryTestListCategory','ALLOW_NULL','app/Http/Resources/Api/V1/LaboratoryTestListResource.php:22-25',
        'Category se emite null cuando la relacion no existe.','Catalogo/SDK: object o null; propiedades del objeto siguen tipadas.'),
    row('N09','LaboratoryTestListItem/properties/indications','ALLOW_NULL',
        'app/Http/Resources/Api/V1/LaboratoryTestListResource.php:26; database/migrations/2025_04_21_161348_modify_laboratory_tests_table.php:296-298',
        'Passthrough de columna declarada nullable. No se consulto DB para frecuencia real.','Catalogo/SDK: aceptar string o null.'),
    row('N10','OtpChallenge/properties/resource_type','REMOVE_ANNOTATION',
        'app/Services/Otp/AkubicaLoginOtpService.php:234-244; app/Services/Otp/StepUp/AkubicaStepUpOtpService.php:433',
        'Login/decoy omiten; step-up legitimo deriva contexto de recurso. Null no acreditado en flujos validos revisados.',
        'OTP/SDK: opcional string; revisar clientes que interpretaban nullable de OpenAPI 3.0.'),
    row('N11','OtpChallenge/properties/resource_id','REMOVE_ANNOTATION',
        'app/Services/Otp/AkubicaLoginOtpService.php:234-244; app/Services/Otp/StepUp/AkubicaStepUpOtpService.php:434',
        'Login/decoy omiten; step-up hace cast explicito a int, no null.',
        'OTP/SDK: opcional integer; no inventar null para el caso omitido.'),
    row('N12','OtpChallenge/properties/resend_available_at','REMOVE_ANNOTATION',
        'app/Services/Otp/AkubicaLoginOtpService.php:242,482,518; app/Services/Otp/Registration/AkubicaRegisterOtpService.php:590,699,718; app/Services/Otp/StepUp/AkubicaStepUpOtpService.php:436',
        'Productores revisados calculan timestamp no nulo; algunas respuestas legacy omiten el campo.',
        'OTP/SDK: opcional date-time; frecuencia y ramas de flags requieren prueba operativa autorizada.'),
    row('N13','Invoice/properties/uuid','ALLOW_NULL','app/Http/Resources/Api/V1/OrderInvoiceResource.php:32',
        'El recurso emite null explicitamente. Se mantiene string como alternativa declarada legacy, sin inventar UUID real.',
        'Documentos/SDK: no asumir UUID disponible en respuestas issued o pending.'),
    row('N14','Invoice/properties/issued_at','ALLOW_NULL','app/Http/Resources/Api/V1/OrderInvoiceResource.php:34',
        'Null cuando pendiente o created_at ausente; fecha ISO cuando existe.',
        'Documentos/SDK: date-time o null; no usar fecha por defecto inventada.'),
]
for identifier, field, lines, behavior in [
    (15,'public_price_cents','17-19','Null si columna publico es null.'),
    (16,'savings_cents','21-35','Null mientras comparacion no confiable; entero cuando disponible.'),
    (17,'savings_percentage','21-35','Null mientras comparacion no confiable; numero cuando disponible.'),
    (18,'tax_included','61-73','Configuracion ausente/no interpretable devuelve null; boolean en otro caso.'),
    (19,'effective_at','76-91','Fecha de configuracion ausente o invalida devuelve null.'),
    (20,'valid_until','76-91','Fecha de configuracion ausente o invalida devuelve null.'),
]:
    ROWS.append(row(f'N{identifier:02d}', 'LaboratoryPricing/properties/'+field,'ALLOW_NULL',
        'app/Services/Api/V1/LaboratoryPricingService.php:'+lines,behavior,
        'Catalogo/precios/SDK: aceptar null, conservar limites/formato y no presentarlo como ahorro cero.'))
ROWS += [
    row('N21','LaboratoryTest/properties/description','ALLOW_NULL',
        'app/Http/Resources/Api/V1/LaboratoryTestResource.php:18; database/migrations/2025_04_21_161348_modify_laboratory_tests_table.php:294',
        'Passthrough de columna nullable, sin consultar datos existentes.','Catalogo detalle/SDK: string o null.'),
    row('N22','LaboratoryTest/properties/category','ALLOW_NULL','app/Http/Resources/Api/V1/LaboratoryTestResource.php:20',
        'Operador null-safe devuelve null si falta categoria.','Catalogo detalle/SDK: string o null; distinto de objeto categoria en lista.'),
    row('N23','TaxProfileWriteRequest/properties/cfdi_use','ALLOW_NULL','app/Http/Requests/Api/V1/User/StoreTaxProfileRequest.php:22; app/Http/Requests/Api/V1/User/UpdateTaxProfileRequest.php:22',
        'Solicitud admite null explicitamente; no es una afirmacion sobre cada respuesta.',
        'Clientes fiscales/SDK: pueden enviar null; limite maxLength conservado.'),
    row('N24','TaxProfileWriteRequest/properties/email','ALLOW_NULL','app/Http/Requests/Api/V1/User/StoreTaxProfileRequest.php:24; app/Http/Requests/Api/V1/User/UpdateTaxProfileRequest.php:24',
        'Solicitud admite null; si hay string debe ser email valido.',
        'Clientes fiscales/SDK: null permitido sin quitar formato email o limite.'),
    row('N25','AddressWriteRequest/properties/additional_references','ALLOW_NULL','app/Http/Requests/Api/V1/User/StoreAddressRequest.php:24; app/Http/Requests/Api/V1/User/UpdateAddressRequest.php:24',
        'Solicitud admite null explicitamente.', 'Clientes de direccion/SDK: conservar limite y opcionalidad.'),
    row('N26','CancelOrderRequest/properties/reason','ALLOW_NULL','app/Http/Requests/Api/V1/Orders/CancelOrderRequest.php:17',
        'Solicitud admite null explicitamente.', 'Clientes de ordenes/SDK: conservar maxLength y no hacerlo obligatorio.'),
]


def node_at(schemas, path):
    node = schemas
    for part in path.split('/'):
        node = node[part]
    return node


def apply_reviewed_nullable(spec):
    results = []
    schemas = spec['components']['schemas']
    for entry in ROWS:
        node = node_at(schemas, entry['path'])
        if node.get('nullable') is not True or not isinstance(node.get('type'), str):
            raise ValueError('AUDIT_INPUT_CHANGED')
        before = deepcopy(node)
        node.pop('nullable')
        if entry['action'] == 'ALLOW_NULL':
            node['type'] = [node['type'], 'null']
        results.append({**entry, 'before': before, 'after': deepcopy(node)})
    return results
