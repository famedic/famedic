# Plan versionado A4 (functional-window-2-continuity)

Este directorio incorpora una plantilla versionada del plan A4 que en QA
existió sólo como `/tmp/a4-continuity-plan.json` (sha256
`8c74b2d1667d7e0303d732c25d2531996cd734094a9ef06b8a0d47d218648e08`).

El archivo `functional-window-2-continuity-a4-plan.template.json` **no** es
una copia byte a byte de ese `/tmp`. Sólo reconstruye la estructura
evidenciada por la lectura de trazabilidad IT-0061 y por el runner:

- `credential_scenario`: `functional-window-2-continuity` (comando autorizado
  de la corrida A4).
- Caso `A4-L05a`: `GET /api/v1/checkout/prepare?service_line=laboratory&brand=olab`.
- `expected_status`: `200`, acreditado porque la corrida histórica devolvió
  HTTP 200 y falló después por `ASSERTION_HEADER_MISMATCH`.
- `assertions.headers_equals.cache-control`: `private, no-store`. El runner
  compara esas directivas como conjunto, sin depender del orden.

El plan de `/tmp` también tenía las claves `cleanup`, `qa_guards`,
`selection`, `auth`, `assertions.equals` y `assertions.required_paths`,
pero sus valores no quedaron conservados. **No se inventan.** En el
próximo precheck hay que reconciliar esta plantilla con el sha256
`8c74b2d1…8e08` (o con una copia íntegra sanitizada de ese plan) antes de
cualquier corrida nueva.

La plantilla no es un plan QA ejecutable hasta esa reconciliación. El FAIL
histórico de A4 se conserva y no se declara PASS.
