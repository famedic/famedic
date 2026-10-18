# Preparación de rama para staging

Rama del proyecto original: `feature/indicaciones-ia-v3-mejoras`.
Integra monitor, P2, contrato GDA/P0, correo único durable y utilidades QA exclusivamente locales.
Los informes de bloques A/B y GDA/P0 conservan su contexto y fecha; no describen por sí solos el estado operativo actual.

## Configuración inicial del destino

```ini
LOCAL_CHECKOUT_QA_ENABLED=false
LAB_CONFIRMATION_COORDINATOR_ENABLED=false
LAB_CONFIRMATION_COORDINATOR_RELEASE_READY=false
LAB_CONFIRMATION_COORDINATOR_ACTIVATED_AT=
```

No copiar el `.env` local ni su fecha de cohorte a staging. Los comandos `local-qa:*` no corresponden a staging.
Si staging ya tiene una frontera persistida, revisar pendientes y pausar sin borrar la frontera ni recrear intenciones.

## Migraciones y despliegue posterior

Las tres migraciones incluidas deben revisarse contra el esquema real del destino antes de desplegar.
La migración GDA se niega intencionalmente si `gda_status` existe: no autoriza ejecutar su DDL ni marcarla como aplicada sin comprobar columna, índice, nulabilidad y valores.
El baseline importado se reconcilió únicamente en la base local; esa modificación del historial NO viaja con el commit.
Mantener respaldo verificable y rollout apagado durante la revisión. No se ejecutó despliegue ni migración de staging.

## Evidencia disponible

- Integración A/B/GDA: 454 casos PHP distintos y 6,299 aserciones; JS 109 PASS y 1 SKIP de navegador; build cliente y SSR PASS al integrar esos bloques.
- QA y ajuste de navegación: 76 PHP PASS y 582 aserciones.
- Supervisor de workers: 22 PHP PASS y 75 aserciones.
- Estos grupos se solapan y NO deben sumarse como casos distintos.
- Prueba manual local observada: compra con resumen incorporado en la confirmación; redactor QA simulado con motor determinístico y validador reales. No certifica latencia o calidad de OpenAI real.
- Al preparar este commit Docker estaba detenido; no se repitieron PHP ni build y no se modificó Docker para ejecutarlos.

El push no autoriza deploy, activación de coordinador, envío de correo ni conexión a proveedores.

Validación repetida al preparar Git: JavaScript 109 PASS, 0 FAIL, 1 SKIP por Chrome no disponible en el runtime. Diff check y escaneo de artefactos/credenciales del índice correctos. Logs, respaldo SQL, `.env` y lock de QA excluidos.
