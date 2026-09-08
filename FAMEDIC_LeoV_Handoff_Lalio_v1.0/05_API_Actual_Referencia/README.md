# Guías y Postman — API Akubica Famedic

Esta carpeta contiene la **documentación final para pruebas de integración** de la API Akubica (`/api/v1`).

## Base URL staging

```text
https://staging.famedic.com.mx/api/v1
```

## Orden recomendado

1. Leer [`akubica-checklist-endpoints.md`](akubica-checklist-endpoints.md) — checklist de endpoints disponibles.
2. Leer [`akubica-flujo-pruebas-staging.md`](akubica-flujo-pruebas-staging.md) — guía paso a paso para QA.
3. Consultar [`akubica-api-consumption-guide.md`](akubica-api-consumption-guide.md) — referencia completa de endpoints.
4. Importar la colección Postman: [`postman/Famedic-Akubica-API-v1.postman_collection.json`](postman/Famedic-Akubica-API-v1.postman_collection.json).
5. Importar el environment: [`postman/Famedic-Akubica-Staging.postman_environment.json`](postman/Famedic-Akubica-Staging.postman_environment.json).
6. **Ejecutar primero** la carpeta `01 - Registro nuevo usuario`.
7. Después probar `02 - Login usuario existente` y continuar con el flujo numerado.

## Contenido de la carpeta

| Archivo | Propósito |
| ------- | --------- |
| `README.md` | Este índice |
| `akubica-checklist-endpoints.md` | Checklist de endpoints disponibles para integración |
| `akubica-flujo-pruebas-staging.md` | Flujo QA paso a paso |
| `akubica-api-consumption-guide.md` | Guía de consumo API (orden de pruebas) |
| `akubica-openapi.yaml` | Especificación OpenAPI 3.0 |
| `akubica-requirements-coverage-matrix.md` | Matriz requisitos vs implementación |
| `postman/` | Colección y environment staging |

## Notas importantes

- **El pago se realiza únicamente en la plataforma Famedic.** La API genera un *payment link*; no cobra ni tokeniza tarjetas.
- **Auth Akubica:** registro e inicio de sesión usan **OTP por correo**, no contraseña del paciente en la API.
- **Farmacia** (`GET /catalog/medications/{id}`) y **cancelación de pedidos** responden **503** (desactivados).
- Los entregables por sprint siguen en `docs/Akubica/akubica-sprint*.md`; esta carpeta es la **entrega consolidada** para Akubica.

## Documentos históricos

Los sprints 0–14 documentan decisiones técnicas por iteración. Para integración, usar **esta carpeta** como fuente principal.
