# Fase 2A Diseño funcional y preparación de implementación

Fecha: 2026-11-05. **Diseño preparado para revisión; D01–D10 pendientes de aprobación. Fase 2B no iniciada.** Se documentó el comportamiento local de Laravel y una propuesta completa de selección médica opaca, sin modificaciones funcionales ni cambios al OpenAPI validado.

## Entregables para revisar

| Entregable solicitado | Documento |
| --- | --- |
| Diagnóstico real de ownership, elegibilidad, montos, consumo, concurrencia, idempotencia y exposición legacy | [Diagnóstico actual](FASE-2A-DIAGNOSTICO-ACTUAL.md) |
| D01–D10 con reglas actual/propuesta, impactos, riesgos, pruebas y responsables por rol | [Matriz para aprobación](FASE-2A-DECISIONES-D01-D10.md) |
| Requests/responses, DTO, estados, TTL, ownership, errores, concurrencia y compatibilidad de las tres rutas | [Diseño técnico API opaca](FASE-2A-DISENO-API-OPACA.md) |
| Consentimiento verificable, aislamiento de Leo y prohibición de payment links | [Consentimiento e identidad](FASE-2A-CONSENTIMIENTO-IDENTIDAD-LEO.md) |
| Incrementos, archivos esperados, migraciones propuestas, aceptación y bloqueos | [Plan Fase 2B](FASE-2A-PLAN-2B-Y-BLOQUEOS.md) |
| Integridad contractual y de código, fuentes y comprobaciones realizadas | [Evidencia de Fase 2A](FASE-2A-EVIDENCIA.json) |

## Recomendación para decisión

Un beneficio médico por carrito; saldo hasta el subtotal una vez elegido y confirmado; preview sin reserva/gasto; referencias opacas ligadas al usuario, consumidor y contexto; confirmación independiente en FAMEDIC. TTL propuestos: opción/snapshot cinco minutos, consentimiento dos minutos, recibo idempotente 24 horas. Referencias estables requieren recuperación cifrada además del hash de búsqueda, excepción explícita para aprobación D05. No se proponen importes parciales elegidos por Leo ni acumulación de fuentes.

El diagnóstico confirma que aplicar/retirar el beneficio médico solo cambia selección y totales. El consumo genérico pone el remanente en cero y no debe copiarse para un débito médico parcial. No se acreditó un finalizador de compra médica conectado con M2C. Se requiere decidir el canje/reverso antes de afirmar un flujo de compra completo.

La ruta de payment links no aplica hoy una prohibición específica para Leo. Se propone identidad de consumidor verificada en emisión y petición, PAT vinculado al canal y denegación de pagos antes de cualquier replay/cache/readiness. Leo debe recibir 403 incluso con wildcard o cabeceras falsas; P01–P12 especifican aserciones de cero efectos y un positivo nativo separado. Son pruebas propuestas, no ejecutadas ni PASS.

Las rutas legacy se conservan para consumidores autorizados. Leo no debe usarlas para eludir consentimiento o leer IDs/códigos internos. La migración de identidad y allowlist puede afectar el acceso previo de Leo y requiere acuerdo de Integraciones; no se oculta como cambio compatible automático.

## Verificación de alcance

Contrato RC1.1: SHA-256 `523a566bab22fffb4c0ad8fbb29d79cc4823e99769494b1fd135f6639094f9a4`, 67 operaciones, sin cambios. Se conserva el resultado integral de Fase 1C.2: PASS, cero errores y 84 regresiones históricas (80 Python + 4 Node). No se repitieron como si probaran las nuevas rutas: estas no existen.

La evidencia de esta fase compara hashes de app, routes, config, database, tests, scripts y documentación previa, comprueba el contrato y el estado HEAD, verifica ejemplos JSON documentales y ejecuta git diff --check. El alcance de escritura es seis documentos de diseño, un JSON de evidencia y excepciones exactas de .gitignore. No se modificaron Laravel, tests funcionales, contratos históricos ni el candidato. No se ejecutaron proveedores, staging, producción o migraciones; sin commit, push, deploy o ZIP.

## Revisión pendiente

Se requiere evidencia expresa para aprobar cada decisión, con responsable nominal y condiciones. Bloquean especialmente: identidad/canal y emisión segura de tokens; canal de confirmación/callback; consumo médico parcial y reversos; cobertura de todos los escritores legacy por versionado; límites monetarios/rate limits/retención; inventario de consumidores y aprobación de baseline 61.

Fase 0 administrativa permanece **ABIERTA**. La nueva rotación HMAC sandbox y limpieza de caché son hechos reportados por el usuario, sin verificación de esta fase. Siguen pendientes el rechazo de la clave anterior y revocación/verificación del PAT comprometido. No se usaron esas credenciales.

La entrega queda detenida para revisión y aprobación. No autoriza liberación, implementación, habilitación de rutas ni inicio automático de Fase 2B.
