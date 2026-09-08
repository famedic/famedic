\---

document\_type: historical\_suspended\_deprecated\_content
document\_name: Contenido Histórico, Suspendido y No Vigente FAMEDIC
version: "0.1"
status: working\_draft
language: es-MX
retrieval\_policy:
precedence:
- production\_api
- current\_configuration
- latest\_approved\_documentation
- current\_master\_knowledge\_base
- historical\_context\_only
prohibited\_as\_primary\_answer:
- historical
- suspended
- deprecated
- legacy
- not\_public
- audit\_only
- support\_history\_only
- do\_not\_answer\_as\_current
global\_rules:

* No presentar contenido histórico como vigente
* No comprometer fechas de reactivación
* Usar respuesta pública aprobada
* Mantener trazabilidad y responsable

\---

# Contenido Histórico, Suspendido y No Vigente FAMEDIC

**Versión de trabajo 0.1**

## 1\. Propósito

Este documento identifica contenidos, servicios, términos, materiales y flujos que:

* existieron anteriormente;
* se encuentran suspendidos;
* ya no deben comunicarse como vigentes;
* se conservan únicamente para consulta histórica, auditoría, soporte o migración;
* requieren validación antes de volver a utilizarse.

Su objetivo es evitar que Leo, la plataforma, el equipo de atención o los sistemas de recuperación documental presenten información desactualizada como si estuviera activa.

Este documento funciona como una lista de exclusión y control de vigencia para:

* base de conocimiento;
* búsquedas semánticas;
* respuestas de Leo;
* materiales comerciales;
* atención humana;
* pruebas;
* migraciones;
* documentación jurídica;
* contenidos históricos de pedidos.

## 2\. Principio general

Todo contenido clasificado como histórico, suspendido o no vigente debe incluir un estado explícito.

Los estados permitidos son:

|Estado|Definición|
|-|-|
|Histórico|Existió y se conserva como antecedente|
|Suspendido|Servicio o función temporalmente no disponible|
|Descontinuado|Ya no forma parte de la oferta|
|Sustituido|Fue reemplazado por otra denominación, flujo o servicio|
|No liberado|Existe técnicamente, pero nunca fue habilitado públicamente|
|En revisión|No debe usarse hasta ser validado|
|Solo auditoría|Se conserva únicamente para trazabilidad|
|Solo soporte histórico|Puede utilizarse para pedidos o incidencias anteriores|
|Prohibido en respuesta pública|No debe aparecer en respuestas a usuarios|
|Pendiente de reactivación|Podría regresar, sin fecha comprometida|

## 3\. Reglas de uso

### HIS-001. No presentar como vigente

Leo no debe presentar contenido histórico o suspendido como:

* servicio disponible;
* promoción vigente;
* beneficio actual;
* flujo activo;
* método de pago disponible;
* canal operativo;
* política actual;
* plan comercializable.

### HIS-002. Prioridad de fuentes

Ante conflicto entre:

* documentación histórica;
* capturas antiguas;
* archivos CSV;
* manuales previos;
* documentos vigentes;
* APIs de producción;

prevalece:

1. API de producción;
2. configuración vigente;
3. documentación aprobada más reciente;
4. Base Maestra vigente;
5. contenido histórico solo para contexto.

### HIS-003. Etiquetado obligatorio

Todo contenido histórico debe incluir, como mínimo:

* nombre del contenido;
* estado;
* fecha aproximada o periodo;
* motivo;
* reemplazo vigente;
* uso permitido;
* uso prohibido;
* responsable de revisión;
* fuente original.

### HIS-004. Respuesta pública

Cuando un usuario pregunte por una función histórica:

* responder con el estado actual;
* evitar explicar detalles innecesarios del flujo anterior;
* no ofrecer fechas de regreso no confirmadas;
* indicar alternativa vigente cuando exista.

### HIS-005. Búsqueda semántica

Los materiales históricos pueden conservarse en la base documental, pero deben etiquetarse para impedir su recuperación como respuesta principal.

Etiquetas sugeridas:

* `historical`;
* `suspended`;
* `deprecated`;
* `legacy`;
* `not\_public`;
* `audit\_only`;
* `support\_history\_only`;
* `do\_not\_answer\_as\_current`.

## 4\. Farmacia en Línea

### 4.1 Estado actual

**Estado:** Suspendido / pendiente de reactivación.

Farmacia en Línea está temporalmente deshabilitada.

La suspensión está relacionada con restricciones del procesador para operaciones con medicamentos.

FAMEDIC trabaja en una alternativa, pero no existe una fecha pública confirmada de reactivación.

### 4.2 Funciones suspendidas

No están disponibles:

* catálogo de medicamentos;
* búsqueda de productos;
* precios;
* inventario;
* disponibilidad;
* carrito;
* compra;
* pago;
* entrega nueva;
* cotización;
* recomendación de sustitutos.

### 4.3 Funciones históricas permitidas

Sí puede consultarse, cuando exista API o información disponible:

* pedido histórico;
* estatus histórico;
* entrega;
* devolución;
* factura;
* movimiento asociado;
* incidencia previa.

### 4.4 Canales históricos de Vitau

**Estado:** Sustituidos para soporte FAMEDIC.

Los teléfonos, correos o canales históricos de Vitau no deben utilizarse como canal principal de atención para usuarios FAMEDIC.

Las incidencias deben dirigirse al canal de atención FAMEDIC.

### 4.5 Documentación histórica de Farmacia

Los documentos sobre:

* devoluciones;
* tiempos de entrega;
* operación con proveedores;
* políticas de entrega;
* flujos anteriores;

pueden conservarse para:

* auditoría;
* atención de pedidos históricos;
* análisis operativo;
* posible reactivación.

No deben utilizarse para describir una nueva compra vigente.

### 4.6 Respuesta oficial

> El servicio de Farmacia en Línea está temporalmente deshabilitado mientras FAMEDIC trabaja en una alternativa para reactivarlo.

No debe añadirse una fecha estimada.

## 5\. Prueba gratuita de 30 días

### 5.1 Estado

**Estado:** Deshabilitada / histórica.

La prueba gratuita de 30 días ya no se encuentra disponible.

### 5.2 Uso permitido

Puede mencionarse únicamente cuando:

* el usuario pregunta expresamente por ella;
* se revisa un material histórico;
* se analiza una campaña anterior;
* se atiende una discrepancia documental.

### 5.3 Uso prohibido

No debe:

* ofrecerse;
* aparecer en promociones;
* utilizarse como incentivo;
* presentarse como beneficio;
* integrarse en respuestas automáticas;
* mostrarse en comparadores de planes vigentes.

### 5.4 Respuesta oficial

> La prueba gratuita de 30 días ya no se encuentra disponible.

## 6\. Plan “Premium”

### 6.1 Estado

**Estado:** Término histórico sustituido.

“Premium” no es la denominación vigente.

La denominación oficial es:

> \*\*Plan Completo\*\*

### 6.2 Uso permitido

Puede utilizarse únicamente para:

* reconocer búsquedas históricas;
* mapear documentos anteriores;
* responder cuando un usuario use el término antiguo;
* migrar información.

### 6.3 Respuesta recomendada

> El nombre vigente es Plan Completo. “Premium” es una denominación anterior.

### 6.4 Restricción comercial

Aunque el término se corrija, el Plan Completo no debe ofrecerse como contratación individual.

Es un plan institucional.

## 7\. Planes Intermedio y Completo en venta individual

### 7.1 Estado

**Estado:** No disponibles para contratación B2C.

Los planes Intermedio y Completo existen como oferta institucional.

No forman parte de la contratación individual pública.

### 7.2 Uso prohibido

No deben presentarse como:

* opciones de compra directa;
* planes disponibles en portal individual;
* mejoras que el usuario puede contratar por cuenta propia;
* productos B2C.

### 7.3 Uso permitido

Puede ofrecerse:

* información general;
* comparación conceptual;
* canalización comercial institucional.

## 8\. Moreira

### 8.1 Estado

**Estado:** Excluido de la cobertura vigente.

Moreira no forma parte actualmente de la red activa definida para laboratorios.

### 8.2 Uso permitido

Puede conservarse como:

* referencia histórica;
* proveedor anterior;
* dato de migración;
* antecedente contractual.

### 8.3 Uso prohibido

No debe aparecer en:

* cotizaciones;
* disponibilidad;
* cobertura vigente;
* sucursales activas;
* respuestas de precio;
* flujos de compra.

La API vigente debe prevalecer.

## 9\. Pago en sucursal

### 9.1 Estado

**Estado:** Próximamente / no liberado.

El pago en sucursal no está disponible actualmente.

### 9.2 Uso prohibido

No debe presentarse como:

* método de pago vigente;
* alternativa ante rechazo;
* opción en checkout;
* solución para falta de saldo.

### 9.3 Respuesta recomendada

> El pago en sucursal todavía no está disponible.

## 10\. Checkout y pago operados por Leo

### 10.1 Estado

**Estado:** Fuera del alcance inicial.

Leo podrá preparar acciones relacionadas con:

* consulta;
* carrito;
* selección;
* orientación.

No realizará en la primera etapa:

* checkout completo;
* cargo;
* pago;
* contratación final;
* confirmación financiera.

### 10.2 Riesgo de contenido histórico

Materiales de diseño o pruebas pueden mostrar a Leo ejecutando compras completas.

Deben etiquetarse como:

* `future\_capability`;
* `not\_released`;
* `do\_not\_answer\_as\_current`.

## 11\. Registro completo desde WhatsApp

### 11.1 Estado

**Estado:** Objetivo / pendiente de definición final.

Leo podrá iniciar el registro mediante formulario seguro embebido.

No debe asumirse que el registro completo en texto libre por WhatsApp está habilitado.

### 11.2 Restricciones

Nunca deben solicitarse en texto abierto:

* contraseña;
* OTP;
* información sensible;
* documentos;
* tarjetas.

## 12\. Códigos por WhatsApp

### 12.1 Estado

**Estado:** No permitido.

La verificación se realiza por SMS.

No debe afirmarse que el OTP se enviará por WhatsApp.

## 13\. Envío directo de resultados por WhatsApp

### 13.1 Estado

**Estado:** Prohibido.

Los resultados no deben enviarse como PDF adjunto directo en WhatsApp.

### 13.2 Flujo vigente objetivo

* liga segura;
* vigencia de una hora;
* código por SMS;
* apertura;
* descarga PDF.

### 13.3 Contenido histórico

Cualquier material que sugiera:

* adjuntar PDF;
* mostrar el resultado completo en chat;
* enviar capturas;
* compartir valores clínicos;

debe marcarse como no vigente o prohibido.

## 14\. Interpretación de resultados

### 14.1 Estado

**Estado:** Prohibido.

Leo no debe:

* interpretar resultados;
* diagnosticar;
* explicar anomalías;
* indicar gravedad;
* recomendar tratamiento;
* sugerir medicamentos.

### 14.2 Materiales anteriores

Cualquier prompt, FAQ o respuesta anterior que contenga interpretación clínica debe retirarse de uso activo.

Puede conservarse únicamente para:

* auditoría;
* evaluación de riesgo;
* pruebas negativas;
* entrenamiento de filtros internos sin exposición pública.

## 15\. Órdenes y citas por teléfono

### 15.1 Estado

**Estado:** No permitido.

No se generan:

* órdenes por teléfono;
* citas independientes por teléfono.

La transacción debe originarse en la plataforma.

### 15.2 Concierge

El teléfono de concierge solo debe aparecer dentro del checkout cuando exista un estudio que requiere cita.

No debe utilizarse como:

* teléfono general;
* canal público;
* contacto previo a la selección;
* forma independiente de agendar.

## 16\. Directorios telefónicos no consolidados

### 16.1 Estado

**Estado:** En revisión.

Existen números en capturas o materiales previos que no han sido consolidados en un directorio oficial único.

### 16.2 Regla

No deben codificarse ni publicarse nuevos números hasta contar con:

* validación operativa;
* responsable;
* servicio asociado;
* horario;
* vigencia;
* versión aprobada.

### 16.3 Uso permitido

Los números existentes en el portal pueden mantenerse mientras no sean modificados, pero Leo no debe inventar ni trasladar teléfonos desde capturas sin validación.

## 17\. Tiempos de entrega de laboratorio

### 17.1 Estado

**Estado:** No consolidado.

No existe una tabla aprobada y completa de tiempos por estudio y sucursal.

### 17.2 Uso prohibido

Leo no debe:

* estimar;
* generalizar;
* prometer;
* utilizar tiempos de documentos antiguos;
* asumir que todos los laboratorios manejan los mismos plazos.

### 17.3 Respuesta vigente

> El tiempo de entrega depende del estudio y de la sucursal. La sucursal te confirmará el plazo correspondiente.

## 18\. Catálogos estáticos de laboratorios

### 18.1 Estado

**Estado:** Solo auditoría, pruebas o referencia.

Las hojas de cálculo con:

* estudios;
* equivalencias;
* precios;
* sucursales;
* marcas;

no son la fuente de verdad activa.

### 18.2 Uso permitido

* auditoría;
* migración;
* pruebas;
* comparación de APIs;
* validación de cobertura;
* detección de diferencias.

### 18.3 Uso prohibido

No deben usarse para responder:

* precio actual;
* disponibilidad;
* cobertura;
* descuento;
* sucursal;
* requisito de cita;
* preparación vigente.

## 19\. Promociones antiguas

### 19.1 Estado

**Estado:** Histórico salvo validación por API o configuración vigente.

Toda promoción debe obtenerse desde una fuente dinámica vigente.

### 19.2 Materiales que deben etiquetarse

* banners antiguos;
* cupones;
* campañas;
* descuentos temporales;
* precios promocionales;
* fechas de vigencia vencidas;
* beneficios ya cerrados.

### 19.3 Regla

Si una promoción no aparece en la API o configuración vigente, no debe ofrecerse.

## 20\. Documentos jurídicos anteriores

### 20.1 Aviso de Privacidad

**Estado:** Requiere actualización formal antes del lanzamiento de Leo.

La versión revisada el 10 de octubre de 2024 puede utilizarse como antecedente, pero debe evaluarse frente a:

* WhatsApp;
* Leo;
* APIs;
* memoria conversacional;
* datos de terceros;
* menores;
* facturación;
* pagos;
* enlaces seguros;
* proveedores;
* automatización.

### 20.2 Términos y Condiciones

**Estado:** Requiere actualización formal.

La versión revisada el 19 de enero de 2023 debe considerarse histórica hasta su aprobación actualizada.

### 20.3 Uso permitido

* análisis jurídico;
* control de cambios;
* preparación de nueva versión;
* auditoría.

### 20.4 Uso prohibido

No debe afirmarse que cubre automáticamente:

* operación de Leo;
* memoria conversacional;
* PayPal;
* flujos actuales de citas;
* APIs;
* reautenticación;
* automatización;
* tratamiento actual por WhatsApp.

## 21\. Canales y tickets

### 21.1 Sistema formal de tickets

**Estado:** No existente.

Leo no debe prometer:

* número de ticket;
* folio de atención;
* SLA;
* tiempo exacto;
* seguimiento automático formal.

### 21.2 Identificador interno

Puede existir un identificador técnico de conversación o escalamiento.

No debe presentarse como ticket de servicio salvo que el sistema formal sea implementado.

## 22\. Horarios anteriores

### 22.1 Estado

**Estado vigente de referencia:**

* lunes a viernes: 8:00 a 18:00;
* sábados: 8:00 a 12:00.

Cualquier horario distinto encontrado en materiales anteriores debe marcarse como histórico o en revisión.

### 22.2 Festivos

Los horarios de días festivos permanecen pendientes de definición.

No deben inferirse.

## 23\. Recompensas por referidos

### 23.1 Estado

**Estado:** No confirmadas.

El sistema puede permitir:

* compartir enlace;
* consultar historial.

No deben prometerse:

* bonos;
* recompensas;
* premios;
* descuentos;
* comisiones;
* incentivos económicos.

## 24\. Métodos de pago históricos o no confirmados

### 24.1 Regla

Solo deben presentarse métodos confirmados por la plataforma o API.

Actualmente pueden incluir:

* tarjeta de crédito;
* tarjeta de débito;
* PayPal;
* Ahorro a la Vista ODESSA.

### 24.2 No vigentes o no liberados

* pago en sucursal;
* uso de ahorros ODESSA en otros plazos;
* transferencia bancaria manual;
* pago por teléfono;
* cargo sin validación de saldo;
* métodos mostrados en capturas antiguas sin confirmación.

## 25\. Flujos anteriores de citas

### 25.1 Estado

**Estado:** Sustituidos por el flujo actual.

El flujo vigente para estudios con cita requiere:

* carrito;
* paciente;
* dirección;
* método de pago;
* checkout;
* concierge;
* cita registrada;
* confirmación;
* pago;
* orden.

### 25.2 Contenido no vigente

Debe retirarse cualquier instrucción que permita:

* llamar antes de iniciar compra;
* reservar sin carrito;
* pagar antes de registrar cita;
* generar orden antes de cita;
* seleccionar sucursal fuera del flujo definido cuando el estudio requiere cita.

## 26\. Lenguaje comercial anterior

### 26.1 Descuentos

La redacción vigente es:

> Descuentos desde el 20% hasta más del 50% sobre precios regulares.

Cualquier frase como:

* “hasta 50%”;
* “50% garantizado”;
* “todos los estudios con descuento”;
* “precio más bajo asegurado”;

debe revisarse antes de usarse.

### 26.2 Promesas absolutas

Debe eliminarse lenguaje como:

* siempre;
* garantizado;
* inmediato;
* sin restricciones;
* cualquier sucursal;
* cualquier estudio;
* reembolso automático;
* disponible en todo México.

## 27\. Documentación de pruebas y QA

### 27.1 Estado

**Estado:** Solo pruebas.

Las capturas de QA reflejan flujos cercanos a producción, pero:

* no realizan cargos reales;
* pueden mostrar funciones no liberadas;
* pueden contener datos ficticios;
* pueden incluir textos temporales;
* no sustituyen la configuración productiva.

### 27.2 Uso permitido

* validación funcional;
* documentación de flujo;
* pruebas;
* identificación de componentes;
* comparación con producción.

### 27.3 Uso prohibido

No deben usarse como fuente única para:

* precios;
* teléfonos;
* disponibilidad;
* fechas;
* promociones;
* estados de servicio;
* políticas jurídicas.

## 28\. Materiales de entrenamiento anteriores

### 28.1 Estado

**Estado:** Requieren reclasificación.

Preguntas, respuestas y ejemplos anteriores deben revisarse para detectar:

* Farmacia activa;
* prueba gratuita;
* Premium;
* Moreira;
* pago en sucursal;
* citas por teléfono;
* interpretación médica;
* envío directo de resultados;
* tickets;
* tiempos de entrega;
* promociones vencidas;
* teléfonos no validados.

### 28.2 Acciones recomendadas

Cada registro debe clasificarse como:

* vigente;
* vigente con ajuste;
* histórico;
* suspendido;
* prohibido;
* duplicado;
* pendiente de validación.

## 29\. Estructura sugerida para cada registro histórico

```yaml
historical\_record:
  id: HIS-XXX-000
  title: nombre del contenido
  module: modulo
  status: historical|suspended|deprecated|replaced|audit\_only
  effective\_period:
    start: fecha\_o\_desconocida
    end: fecha\_o\_desconocida
  reason: motivo
  current\_replacement: contenido\_vigente
  allowed\_uses:
    - auditoria
    - soporte\_historico
  prohibited\_uses:
    - respuesta\_publica
    - cotizacion
  public\_response: mensaje\_aprobado
  source\_document: referencia
  owner: responsable
  review\_date: fecha
```

## 30\. Matriz resumida

|Contenido|Estado|Respuesta pública|Uso interno|
|-|-|-|-|
|Farmacia nueva compra|Suspendida|Informar suspensión|Histórico y reactivación|
|Pedido histórico de Farmacia|Disponible parcialmente|Consultar o escalar|Soporte histórico|
|Prueba gratuita 30 días|Deshabilitada|No disponible|Histórico|
|“Premium”|Sustituido|Usar Plan Completo|Alias histórico|
|Intermedio/Completo B2C|No disponible|Institucional|Comercial B2B|
|Moreira|Excluido|No mostrar como cobertura|Antecedente|
|Pago en sucursal|Próximamente|No disponible|Planeación|
|Checkout por Leo|No inicial|Leo solo prepara|Roadmap|
|OTP por WhatsApp|No permitido|SMS|Restricción|
|Resultados adjuntos|Prohibido|Liga segura + SMS|Pruebas negativas|
|Interpretación clínica|Prohibida|Consultar profesional|Seguridad|
|Órdenes por teléfono|No permitidas|Usar plataforma|Política|
|Concierge público|Restringido|Solo dentro de checkout|Operación|
|Tiempos de laboratorio|No consolidados|Sucursal confirma|Pendiente|
|Catálogo estático|Solo auditoría|No usar|Pruebas|
|Promociones antiguas|Históricas|Consultar API|Auditoría|
|Aviso de Privacidad anterior|En revisión|Usar versión aprobada|Jurídico|
|Términos anteriores|En revisión|Usar versión aprobada|Jurídico|
|Tickets|No existen|No prometer|ID interno|
|Recompensas por referidos|No confirmadas|No prometer|Pendiente|
|Capturas QA|Solo pruebas|No citar como fuente|Validación|

## 31\. Proceso de retiro de contenido

Cuando se detecte contenido no vigente:

1. identificar el registro;
2. confirmar con responsable;
3. clasificar estado;
4. establecer reemplazo;
5. retirar de respuestas activas;
6. añadir etiqueta histórica;
7. actualizar índices;
8. actualizar prompts y FAQs;
9. actualizar pruebas;
10. documentar fecha de retiro.

## 32\. Proceso de reactivación

Un contenido suspendido solo puede volver a activarse cuando exista:

* aprobación de producto;
* validación operativa;
* integración técnica;
* pruebas;
* actualización jurídica;
* fuente dinámica;
* mensajes aprobados;
* fecha de liberación;
* responsable;
* actualización de la Base Maestra.

Hasta entonces debe conservar el estado de suspendido.

## 33\. Controles recomendados para IA

El sistema de recuperación debe:

* priorizar contenido vigente;
* penalizar contenido histórico;
* excluir registros prohibidos;
* mostrar advertencia interna si la única coincidencia es histórica;
* no combinar una regla histórica con una actual;
* incluir fecha y versión;
* conservar trazabilidad de la fuente utilizada.

## 34\. Pendientes

### Técnicos

* filtros por estado documental;
* metadatos obligatorios;
* control de versiones;
* exclusión de contenido histórico;
* reglas de ranking;
* validación de vigencia;
* monitoreo de respuestas con contenido antiguo.

### Operativos

* inventario total de materiales;
* responsables por módulo;
* fecha de retiro;
* repositorio histórico;
* proceso de aprobación;
* revisión de teléfonos;
* revisión de campañas;
* revisión de materiales de soporte.

### Jurídicos

* definición de versiones vigentes;
* periodo de conservación;
* valor probatorio;
* tratamiento de documentos anteriores;
* registro de aceptación;
* archivo de políticas reemplazadas.

## 35\. Resultado esperado

Este documento debe permitir responder:

* ¿el contenido sigue vigente?;
* ¿puede utilizarlo Leo?;
* ¿puede mostrarse al usuario?;
* ¿para qué puede conservarse?;
* ¿qué lo reemplaza?;
* ¿qué respuesta pública corresponde?;
* ¿quién debe revisarlo?;
* ¿qué riesgo existe si se recupera por error?

## Apéndice IA. Etiquetas de recuperación

```yaml
retrieval\_tags:
  - historical
  - suspended
  - deprecated
  - legacy
  - not\_public
  - audit\_only
  - support\_history\_only
  - do\_not\_answer\_as\_current
  - farmacia
  - prueba\_gratuita
  - premium
  - planes\_b2b
  - moreira
  - pago\_sucursal
  - checkout\_leo
  - registro\_whatsapp
  - otp\_sms
  - resultados
  - interpretacion\_clinica
  - citas
  - directorio
  - tiempos\_laboratorio
  - catalogos\_estaticos
  - promociones
  - documentos\_juridicos
  - tickets
  - referidos
  - metodos\_pago
  - qa
  - entrenamiento
  - retiro
  - reactivacion
```

