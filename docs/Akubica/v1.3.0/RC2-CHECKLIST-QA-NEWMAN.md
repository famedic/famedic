# Checklist de preparación QA y Newman RC2

## Antes de la ventana

- [ ] Rubén confirma backup restaurable de la base QA.
- [ ] Rubén confirma permisos mínimos y `log_bin_trust_function_creators=1` durante la migración.
- [ ] Se registra el SHA exacto que será desplegado.
- [ ] Seguridad aprueba secretos HMAC, máquina, OTP y Bearer mediante gestor seguro.
- [ ] Akúbica entrega fixtures QA autorizados para estudios, membresías y usuario Leo.
- [ ] B02 (S05): hay un paquete QA con nombre, marca, precio público, precio FAMEDIC y ahorro. `composition_status` es informativo y no se exige `validated` (criterio de Batch 1 · T6 en `RC2-B02-BLOQUEO-FUENTE-PAQUETES.md`).
- [ ] Se confirma el mecanismo de continuidad B24 hacia FAMEDIC.

## Fase A

1. Usar la colección **funcional** `Famedic-Akubica-v1.3.0-rc2-NEWMAN.postman_collection.json` con `Famedic-Akubica-v1.3.0-rc2-NEWMAN.environment.template.json`, bajo sus guardas (`allowed_hosts`, bloqueo de producción, `expected_release_sha`; ver `RC2-NEWMAN-LEEME.md`). La rc2-90 es un antecedente offline y omite todas sus peticiones.
2. Sustituir solo valores autorizados en el gestor de secretos; no guardar tokens en archivos.
3. Ejecutar ATIX 202/401/409/422, OTP 202/200/422 y ciclo de vida Bearer.
4. Guardar JSON y JUnit del mismo build; sanitizar antes de compartir.

## Fase B

1. Ejecutar catálogo, precios, Plan Básico y estado de membresía.
2. Ejecutar altas, duplicados, retiros, concurrencia, read-back y aislamiento de carritos.
3. Ejecutar checkout prepare y los negativos de Leo para pago, cupones y saldo legacy. Payment link (B25/S14) sólo como prueba negativa de seguridad, con credencial Leo y cuerpo válido (`brand`; rev5): HTTP 403, sin liga ni cobro, y readback de sólo lectura sin intento de pago creado.
4. Verificar que no se activa membresía antes de pago confirmado.
5. Ejecutar solo los cuatro casos R30 desde el documento first-party separado, nunca como capacidad de Leo.

## Flujos obligatorios de Leo (Batch 2, colección `…-rc2-LEO-FUNCIONAL`)

1. Sólo sobre el build de Batch 2 desplegado en staging, con `target_env=staging`, `expected_release_sha` verificado y `leo_batch2_contract=1` (ver `RC2-LEO-FUNCIONAL-LEEME.md`).
2. Credencial y Bearer de Leo (INT-003) en runtime. Nunca credencial native.
3. Step-up (L10/L14) sólo con `sms_authorized=1`. El código lo lee el titular y el operador lo teclea con `read -s`.
4. Escrituras (L06 carrito de laboratorio, L13 solicitud de factura y negativos con efecto) sólo con su bandera y los fixtures INT-005. La solicitud de factura usa el id opaco que entrega la lectura mínima (LF-L13b → `tax_profile_ref`); no hay fixture entero para Leo (rev4).
5. Las ligas seguras y la de continuidad no se abren desde Newman; su apertura es prueba de navegador.
6. B21x (`rc2-NEWMAN`) exige `lab_brand_not_granted`, una marca válida sin `cart:laboratory:write` en el binding de Leo. El positivo autorizado de laboratorio es LF-L06b (L06/S07).
7. Los cuerpos de las 69 + 48 solicitudes están clasificados en `RC2-INVENTARIO-CUERPOS.md`. Los INVALIDO_DELIBERADO (A07, B22, R07a, R07a2, LF-L13g y LF-L13x) no se corrigen, porque el rechazo es el objeto de la prueba. LF-L17a requiere `leo_negative_writes_authorized=1`.

## Criterio de salida

Cada caso debe tener código HTTP contractual, efecto funcional, lectura posterior, evidencia sanitizada, responsable y dependencia. Un 404/403/409/500 no acredita un positivo. Sin fases A y B ejecutadas, el estado es `NO GO` para certificación RC2.
