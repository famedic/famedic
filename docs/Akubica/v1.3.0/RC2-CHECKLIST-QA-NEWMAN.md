# Checklist de preparación QA y Newman RC2

## Antes de la ventana

- [ ] Rubén confirma backup restaurable de la base QA.
- [ ] Rubén confirma permisos mínimos y `log_bin_trust_function_creators=1` durante la migración.
- [ ] Se registra el SHA exacto que será desplegado.
- [ ] Seguridad aprueba secretos HMAC, máquina, OTP y Bearer mediante gestor seguro.
- [ ] Akúbica entrega fixtures QA autorizados para estudios, membresías y usuario Leo.
- [ ] Se confirma que B02 tiene catálogo autorizado de paquetes y precios.
- [ ] Se confirma el mecanismo de continuidad B24 hacia FAMEDIC.

## Fase A

1. Importar `Famedic-Akubica-v1.3.0-rc2-90.postman_collection.json` y `Famedic-Akubica-v1.3.0-rc2-synthetic.environment.json`.
2. Sustituir solo valores autorizados en el gestor de secretos; no guardar tokens en archivos.
3. Ejecutar ATIX 202/401/409/422, OTP 202/200/422 y ciclo de vida Bearer.
4. Guardar JSON y JUnit del mismo build; sanitizar antes de compartir.

## Fase B

1. Ejecutar catálogo, precios, Plan Básico y estado de membresía.
2. Ejecutar altas, duplicados, retiros, concurrencia, read-back y aislamiento de carritos.
3. Ejecutar checkout prepare y negativos de Leo para pago, payment link, cupones y saldo legacy.
4. Verificar que no se activa membresía antes de pago confirmado.
5. Ejecutar solo los cuatro casos R30 desde el documento first-party separado, nunca como capacidad de Leo.

## Criterio de salida

Cada caso debe tener código HTTP contractual, efecto funcional, lectura posterior, evidencia sanitizada, responsable y dependencia. Un 404/403/409/500 no acredita un positivo. Sin fases A y B ejecutadas, el estado es `NO GO` para certificación RC2.
