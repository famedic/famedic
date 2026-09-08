\---

document\_type: knowledge\_base
document\_name: Base Maestra de Conocimiento FAMEDIC
version: "0.1"
status: working\_draft
language: es-MX
assistant\_identity: "Leo, tu guía virtual FAMEDIC"
retrieval\_priority:

* API para datos dinámicos
* Base de conocimiento para reglas estables
* Documentos jurídicos aprobados para privacidad y términos
global\_constraints:
* No inventar datos dinámicos
* No usar valores históricos como respaldo ante falla de API
* No confirmar acciones sin respuesta exitosa de API
* No interpretar resultados médicos
* Usar componentes seguros para información sensible
* Escalar los casos definidos como obligatorios

\---

# Base Maestra de Conocimiento FAMEDIC

**Versión de trabajo 0.1**

## 1\. Propósito del documento

Esta Base Maestra concentra la información estable que Leo, la plataforma FAMEDIC y los equipos de atención deben utilizar para:

* explicar qué es FAMEDIC;
* orientar a usuarios;
* describir servicios y procesos;
* aplicar reglas de negocio;
* identificar restricciones;
* determinar cuándo consultar una API;
* reconocer cuándo debe escalarse un caso.

No sustituye:

* al catálogo dinámico de estudios;
* a las APIs de usuarios, pedidos, resultados o facturas;
* al Aviso de Privacidad;
* a los Términos y Condiciones;
* a los procedimientos internos de atención;
* a la asesoría médica, legal o fiscal profesional.

## 2\. Identidad de FAMEDIC

### 2.1 ¿Qué es FAMEDIC?

FAMEDIC es una plataforma de servicios de salud que permite a sus usuarios acceder a soluciones médicas y de bienestar de manera accesible.

Su oferta incluye actualmente:

* planes de atención médica;
* estudios de laboratorio;
* administración de pacientes;
* consulta de pedidos;
* acceso a resultados;
* facturación;
* servicios institucionales para empresas.

El servicio de Farmacia en Línea forma parte del ecosistema histórico de FAMEDIC, pero se encuentra temporalmente deshabilitado.

### 2.2 Relación con ODESSA

FAMEDIC mantiene una relación comercial y tecnológica con ODESSA, pero no debe explicarse como si ambas fueran exactamente la misma plataforma.

Los usuarios elegibles pueden vincular su cuenta de caja de ahorro ODESSA con FAMEDIC.

La vinculación puede permitir utilizar el saldo disponible de **Ahorro a la Vista** como método de pago.

### 2.3 Diferencia entre cuenta y plan

Una **Cuenta FAMEDIC** permite al usuario:

* registrarse;
* consultar servicios;
* guardar información;
* administrar pacientes;
* realizar compras;
* consultar historial.

Un **Plan Médico** es un servicio contratado que otorga coberturas específicas durante un periodo determinado.

No debe usarse “membresía” como sinónimo general de cuenta cuando pueda confundirse con un plan pagado.

## 3\. Fuentes oficiales de información

### 3.1 Base de conocimiento

La Base de Conocimiento es la fuente para:

* conceptos;
* beneficios generales;
* reglas de negocio;
* procesos;
* políticas;
* restricciones;
* criterios conversacionales;
* límites de Leo.

### 3.2 Información dinámica por API

Debe consultarse mediante API:

* catálogo de estudios;
* nombres y equivalencias;
* descripciones;
* preparación;
* requisito de cita;
* precios;
* descuentos;
* laboratorios;
* sucursales;
* cobertura;
* disponibilidad;
* promociones;
* datos de cuenta;
* pedidos;
* resultados;
* facturas;
* métodos de pago disponibles;
* saldo de Ahorro a la Vista.

### 3.3 Falla de API

Cuando no sea posible consultar información dinámica:

* no se deben inventar datos;
* no se deben utilizar precios históricos;
* no se deben reutilizar valores almacenados sin validar;
* debe informarse que la consulta no está disponible temporalmente;
* debe ofrecerse orientación general o canalización.

## 4\. Tipos y estados de usuario

### 4.1 Visitante

Puede:

* navegar por el portal;
* conocer servicios;
* buscar estudios;
* revisar precios públicos;
* consultar beneficios;
* obtener información general.

No puede:

* agregar productos o estudios al carrito;
* administrar datos personales;
* consultar pedidos;
* consultar resultados;
* consultar facturas;
* completar operaciones.

### 4.2 Usuario registrado

Cuenta con un perfil FAMEDIC y puede iniciar sesión para realizar operaciones habilitadas.

### 4.3 Usuario autenticado

Es un usuario registrado con una sesión válida.

### 4.4 Usuario con plan médico activo

Cuenta con un Plan Básico individual o con un plan médico patrocinado.

### 4.5 Usuario con cuenta ODESSA vinculada

Tiene una relación activa con una caja ODESSA habilitada para FAMEDIC.

La vinculación no significa necesariamente que tenga saldo disponible para pagar.

### 4.6 Usuario patrocinado

Recibe un plan médico contratado por una empresa o institución.

### 4.7 Usuario sin actividad

Usuario registrado que nunca ha utilizado un servicio.

### 4.8 Usuario sin actividad reciente

Usuario que lleva más de seis meses sin compras o actividad relevante.

Estos últimos dos estados son de uso interno para personalización y reactivación. No deben presentarse como etiquetas visibles al usuario.

## 5\. Registro y acceso

### 5.1 Datos de registro

El registro requiere:

* nombre;
* apellido paterno;
* apellido materno;
* correo electrónico;
* teléfono celular;
* fecha de nacimiento;
* sexo;
* contraseña;
* aceptación del Aviso de Privacidad;
* aceptación de los Términos y Condiciones.

### 5.2 Teléfono

Actualmente solo se aceptan números telefónicos de México con código `+52`.

### 5.3 Verificación

El registro requiere:

* confirmación de correo electrónico;
* código de verificación enviado por SMS.

No se envían códigos de registro por WhatsApp.

### 5.4 Duplicados

No se permiten cuentas duplicadas con el mismo:

* teléfono;
* correo electrónico.

Cuando ya existe una cuenta, el usuario debe ser dirigido a:

* inicio de sesión;
* recuperación de contraseña.

### 5.5 Registro mediante Leo

Leo podrá iniciar el flujo de registro mediante un componente o formulario seguro.

Nunca deberá solicitar una contraseña directamente en la conversación.

### 5.6 Recuperación de acceso

La recuperación debe realizarse mediante un enlace seguro.

### 5.7 Expiración de sesión

Las sesiones expiran después de un periodo de inactividad.

**Pendiente técnico:** definir duración exacta y reglas de reautenticación.

## 6\. Perfil y autogestión

### 6.1 Datos editables

El usuario puede administrar:

* nombre;
* apellido paterno;
* apellido materno;
* fecha de nacimiento;
* sexo;
* correo electrónico;
* teléfono celular;
* contraseña.

**Pendiente técnico:** confirmar si cambiar correo o teléfono requiere una nueva verificación.

### 6.2 Familiares

El usuario puede:

* agregar;
* editar;
* eliminar familiares.

Datos obligatorios:

* nombre;
* apellido paterno;
* apellido materno;
* parentesco;
* fecha de nacimiento;
* sexo.

Eliminar a un familiar no elimina su historial previo.

### 6.3 Pacientes frecuentes

El usuario puede registrar personas para quienes realiza compras de laboratorio, aunque no formen parte de su grupo familiar.

Datos requeridos:

* nombre;
* apellido paterno;
* apellido materno;
* teléfono de contacto;
* fecha de nacimiento;
* sexo.

Los pacientes frecuentes pueden agregarse, editarse o eliminarse.

Eliminar un paciente no elimina órdenes o resultados históricos.

### 6.4 Direcciones

Campos disponibles:

* calle;
* número;
* colonia;
* referencias adicionales, opcionales;
* estado;
* ciudad o municipio;
* código postal.

Crear una dirección no confirma automáticamente la cobertura de un servicio.

## 7\. Planes médicos

### 7.1 Plan Básico

Es el único plan médico disponible directamente para usuarios individuales.

#### Precio

* $300 MXN;
* IVA incluido;
* pago único;
* vigencia de 12 meses.

#### Beneficios

* telemedicina ilimitada, disponible 24/7;
* orientación psicológica;
* orientación nutricional;
* orientación legal;
* cobertura familiar conforme a las reglas establecidas.

### 7.2 Plan Intermedio

Disponible exclusivamente mediante contratación institucional.

Incluye los beneficios del Plan Básico y además:

* médico a domicilio: hasta tres eventos por familia al año;
* ambulancia: un evento por familia al año;
* check-up: un evento para el titular al año.

### 7.3 Plan Completo

Disponible exclusivamente mediante contratación institucional.

Incluye:

* beneficios del Plan Básico;
* médico a domicilio: hasta tres eventos por familia al año;
* ambulancia: un evento por familia al año;
* reembolso de medicamentos de hasta $350 por evento;
* máximo tres eventos de reembolso por familia al año.

El Plan Completo no incluye check-up.

### 7.4 Terminología

La denominación oficial es:

* Plan Básico;
* Plan Intermedio;
* Plan Completo.

“Premium” es una denominación anterior y no debe utilizarse como nombre vigente.

### 7.5 Prueba gratuita

La prueba gratuita de 30 días se encuentra deshabilitada.

No debe ofrecerse ni presentarse como beneficio vigente.

### 7.6 Cobertura familiar

El usuario puede elegir uno de estos grupos:

#### Grupo A

* titular;
* cónyuge;
* hijos menores de 24 años, sin límite de cantidad.

#### Grupo B

* titular soltero;
* padre;
* madre.

Los grupos no pueden combinarse.

### 7.7 Identificación de beneficiarios

Cada titular y cada familiar recibe un número de usuario propio.

Cada beneficiario puede solicitar atención utilizando su propio número.

### 7.8 Solicitud de servicios

El usuario llama al número correspondiente a su plan.

El conmutador:

1. valida al beneficiario;
2. identifica el servicio;
3. canaliza al profesional correspondiente;
4. envía un enlace de video cuando aplica telemedicina.

Las orientaciones psicológica, nutricional y legal se gestionan a través del mismo conmutador.

### 7.9 Sustitución por plan patrocinado

Cuando un usuario con Plan Básico individual recibe posteriormente un plan patrocinado:

* el plan patrocinado sustituye al individual;
* se calcula la parte no utilizada;
* se realiza el reembolso proporcional;
* el reembolso se envía al método de pago original.

## 8\. Laboratorios

### 8.1 Servicio

FAMEDIC permite consultar y adquirir estudios de laboratorio con precios preferenciales.

Los descuentos se comunicarán como:

> \*\*Descuentos desde el 20% hasta más del 50% sobre precios regulares.\*\*

El precio exacto debe obtenerse mediante API.

### 8.2 Laboratorios disponibles

La cobertura puede incluir actualmente:

* Swiss;
* OLAB;
* Jenner;
* Azteca;
* LIACSA.

Moreira no forma parte de la cobertura vigente.

Las marcas disponibles deben confirmarse siempre por API.

### 8.3 Cobertura general conocida

Como referencia operativa:

* Nuevo León: Swiss;
* Ciudad de México: OLAB, Jenner y Azteca;
* Estado de México: OLAB, Jenner y Azteca;
* Querétaro: OLAB;
* Chihuahua: LIACSA.

Esta información no sustituye la consulta dinámica por:

* estado;
* ciudad;
* colonia.

### 8.4 Búsqueda de estudios

El flujo esperado es:

1. seleccionar laboratorio o marca;
2. buscar el estudio;
3. revisar descripción, preparación y precio;
4. agregarlo al carrito cuando el usuario esté registrado.

La búsqueda podrá reconocer:

* nombres clínicos;
* nombres comunes;
* alias;
* equivalencias configuradas.

Si un estudio no aparece después de una búsqueda razonable:

* no se debe inventar una equivalencia;
* se debe canalizar al WhatsApp de atención.

### 8.5 Estudios sin cita

Flujo general:

1. seleccionar marca;
2. seleccionar estudio;
3. agregar al carrito;
4. elegir paciente;
5. elegir dirección;
6. elegir método de pago;
7. confirmar compra;
8. realizar pago;
9. generar orden.

Cuando un estudio no requiere cita, el usuario puede acudir a cualquier sucursal disponible de la marca seleccionada, sujeto a la cobertura vigente.

### 8.6 Estudios con cita

Cuando al menos un estudio requiere cita:

1. el carrito identifica el requisito;
2. se selecciona paciente;
3. se selecciona dirección;
4. se selecciona método de pago;
5. el usuario inicia el proceso de checkout;
6. se comunica con concierge o solicita recibir una llamada;
7. concierge presenta opciones de sucursal, fecha y hora;
8. el usuario elige;
9. concierge registra la cita;
10. se habilita la confirmación de compra;
11. el usuario confirma y paga;
12. se genera la orden.

No debe realizarse el cargo antes de que concierge registre la cita.

### 8.7 Restricción telefónica

No se generan:

* órdenes por teléfono;
* citas independientes por teléfono.

La operación debe originarse dentro de FAMEDIC.

El teléfono de concierge solo debe mostrarse dentro del checkout de una operación que requiere cita.

Leo no debe proporcionar ese número fuera de dicho proceso.

### 8.8 Vigencia de la orden

Las órdenes pagadas de laboratorio tienen una vigencia de 30 días naturales.

### 8.9 Cancelación

Una orden puede cancelarse únicamente cuando:

* el estudio no se ha realizado;
* la orden no se ha facturado;
* continúa dentro de los 30 días de vigencia.

La solicitud de cancelación debe escalarse a atención.

### 8.10 Reembolso

El reembolso se realiza:

* al método de pago original;
* en un plazo de hasta 15 días hábiles.

### 8.11 Presentación en sucursal

El usuario debe presentar:

* orden o folio;
* instrucciones requeridas;
* receta médica cuando aplique;
* identificación únicamente cuando la sucursal o el estudio la requieran.

No debe indicarse que la identificación es obligatoria en todos los casos.

### 8.12 Resultados

Cuando el laboratorio carga resultados:

* el usuario recibe una notificación por correo;
* el resultado se carga en FAMEDIC;
* puede descargarse en PDF.

Cuando Leo tenga habilitada esta capacidad:

1. verificará que el resultado esté disponible;
2. generará una liga segura;
3. enviará un código por SMS;
4. el usuario abrirá la liga con el código;
5. podrá visualizar y descargar el PDF.

La liga tendrá una vigencia de una hora.

Leo nunca deberá:

* enviar el resultado como archivo adjunto directo en WhatsApp;
* interpretar valores;
* emitir diagnósticos;
* sugerir tratamientos;
* sustituir la consulta con un profesional de salud.

### 8.13 Tiempos de entrega

FAMEDIC no cuenta actualmente con una tabla consolidada de tiempos de entrega por estudio.

La sucursal debe informar el plazo correspondiente.

Leo no debe estimar ni inventar tiempos.

## 9\. Métodos de pago

### 9.1 Métodos vigentes

Pueden incluir:

* tarjeta de crédito;
* tarjeta de débito;
* PayPal;
* saldo disponible en Ahorro a la Vista de una cuenta ODESSA vinculada.

La disponibilidad exacta debe confirmarse por API.

### 9.2 Ahorro a la Vista

El cargo solo puede realizarse contra el saldo disponible en **Ahorro a la Vista**.

No puede utilizarse dinero colocado en:

* ahorro a un mes;
* ahorro de diciembre;
* plazos específicos;
* otros fondos o productos de ahorro.

Tener una cuenta ODESSA con fondos no significa que el usuario pueda pagar con ellos.

Antes del cargo, la API debe validar:

* cuenta vinculada;
* Ahorro a la Vista habilitado;
* saldo disponible;
* saldo suficiente.

### 9.3 Pago en sucursal

El pago en sucursal se encuentra previsto como una función futura.

No debe ofrecerse como opción activa.

### 9.4 Tarjetas

FAMEDIC no debe almacenar ni mostrar:

* número completo;
* CVV;
* credenciales bancarias.

Solo conserva el token proporcionado por el procesador.

Cuando se muestre una tarjeta, únicamente deben utilizarse:

* marca;
* últimos cuatro dígitos.

## 10\. Pedidos e historial

El usuario puede consultar pedidos y movimientos asociados.

La información puede incluir:

* paciente;
* estudios;
* laboratorio;
* fecha;
* folio;
* importe;
* método de pago;
* estado de la orden;
* estado de muestra;
* estado de resultado;
* estado de factura;
* instrucciones;
* cronología de eventos.

El historial visible puede exportarse en formato CSV cuando la plataforma lo permita.

Con una sesión válida, Leo puede mostrar:

* nombre completo del paciente;
* fecha;
* importe;
* folio;
* estado.

## 11\. Facturación

### 11.1 Plazo

La factura debe solicitarse dentro del mismo mes calendario en que se realizó la compra.

La plataforma puede calcular y mostrar el tiempo restante.

### 11.2 Perfiles fiscales

El usuario puede:

* consultar;
* crear;
* editar;
* eliminar perfiles fiscales.

Puede cargar una Constancia de Situación Fiscal en el entorno seguro de la plataforma.

La extracción de información ocurre en la plataforma, no en la conversación con Leo.

### 11.3 Leo y las facturas

Cuando la capacidad esté habilitada, Leo podrá:

* consultar si existe una factura;
* consultar su estado;
* solicitar una factura;
* elegir un perfil fiscal;
* elegir uso de CFDI;
* generar acceso seguro;
* facilitar su descarga o reenvío.

Para abrir o descargar una factura deberá utilizarse:

* liga segura;
* verificación adicional.

### 11.4 Incidencias

Debe escalarse:

* factura retrasada;
* factura no disponible;
* datos fiscales incorrectos;
* factura que no puede descargarse;
* discrepancia en importe o pedido.

## 12\. Farmacia

### 12.1 Estado

El servicio de Farmacia en Línea está temporalmente deshabilitado.

FAMEDIC trabaja en una alternativa para reactivarlo, sin fecha comprometida.

### 12.2 Restricciones

Mientras permanezca deshabilitado, Leo no debe:

* buscar medicamentos;
* mostrar catálogo;
* mostrar precios;
* confirmar inventario;
* agregar medicamentos al carrito;
* iniciar compras;
* recomendar sustitutos farmacológicos.

### 12.3 Pedidos históricos

Leo podrá consultar y orientar sobre:

* órdenes históricas;
* estatus;
* entrega;
* devolución;
* factura.

Las incidencias deben canalizarse al WhatsApp de atención FAMEDIC.

No deben compartirse los canales anteriores de Vitau como vía principal.

### 12.4 Registro de interés

Puede registrarse que un usuario desea recibir información cuando el servicio sea reactivado, sujeto a las reglas de comunicación y consentimiento aplicables.

### 12.5 Alternativas

Cuando corresponda, Leo puede sugerir:

* telemedicina;
* Plan Básico;
* estudios de laboratorio.

No debe recomendar medicamentos o tratamientos.

## 13\. Servicios institucionales

Leo está enfocado principalmente en usuarios individuales.

Puede proporcionar información general sobre:

* servicios de salud para empresas;
* ferias de salud;
* inplants;
* estudios laborales;
* check-ups ejecutivos;
* planes médicos patrocinados.

No debe:

* cotizar;
* construir propuestas;
* negociar;
* confirmar precios;
* comprometer cobertura;
* confirmar fechas o logística;
* cerrar una contratación.

Toda solicitud institucional debe canalizarse con un ejecutivo.

Datos mínimos sugeridos:

* nombre;
* empresa;
* teléfono;
* correo;
* ciudad o estado;
* servicio de interés;
* número aproximado de colaboradores, cuando se conozca.

## 14\. Leo, tu guía virtual FAMEDIC

### 14.1 Identidad

La identidad oficial es:

> \*\*Leo, tu guía virtual FAMEDIC\*\*

### 14.2 Función

Leo puede:

* orientar;
* responder preguntas;
* consultar información mediante APIs;
* apoyar en algunas gestiones;
* preparar acciones;
* solicitar confirmación;
* canalizar a atención humana.

### 14.3 Límites

Leo no debe presentarse como:

* médico;
* profesional clínico;
* abogado;
* asesor fiscal;
* operador humano;
* sistema con capacidad ilimitada.

### 14.4 Veracidad operativa

Leo solo puede confirmar que una acción fue realizada cuando la API haya respondido exitosamente.

Nunca debe afirmar:

* “ya quedó”;
* “ya se registró”;
* “ya se canceló”;
* “ya se agregó”;
* “ya se envió”;

sin confirmación técnica.

### 14.5 Confirmación de cambios

Antes de cualquier modificación debe:

1. resumir la acción;
2. mostrar los datos relevantes;
3. solicitar confirmación expresa;
4. ejecutar la operación;
5. informar el resultado real.

Aplica a:

* familiares;
* pacientes;
* direcciones;
* métodos de pago;
* registro;
* carrito.

## 15\. Seguridad y privacidad

### 15.1 Información que no debe solicitarse en texto abierto

* contraseña;
* número completo de tarjeta;
* CVV;
* credenciales bancarias;
* tokens;
* códigos internos;
* identificaciones completas;
* constancias fiscales completas;
* resultados clínicos completos;
* documentos sensibles.

### 15.2 Componentes seguros

Deben utilizarse para:

* contraseña;
* código de verificación;
* alta de tarjeta;
* recuperación de acceso;
* constancia fiscal;
* resultados;
* facturas;
* documentos sensibles.

### 15.3 Resultados y facturas

Requieren verificación adicional.

Los enlaces seguros tendrán una vigencia de una hora.

### 15.4 Memoria conversacional

Las conversaciones podrán almacenarse para:

* continuidad;
* personalización;
* seguimiento;
* auditoría;
* mejora de la experiencia.

Actualmente no se utilizarán para entrenamiento de modelos.

### 15.5 Evidencia

Debe registrarse, cuando corresponda:

* versión del Aviso de Privacidad;
* versión de Términos;
* fecha y hora;
* canal;
* teléfono;
* IP cuando aplique;
* acción confirmada.

## 16\. Soporte y escalamiento

### 16.1 Canal

El canal principal es el WhatsApp de atención FAMEDIC.

### 16.2 Horario

* lunes a viernes: 8:00 a 18:00;
* sábados: 8:00 a 12:00.

Fuera de horario:

* se registra el motivo;
* se conserva el contexto;
* se informa que será atendido en el siguiente horario disponible.

### 16.3 Casos de escalamiento obligatorio

* cargo desconocido;
* cargo duplicado;
* sospecha de acceso no autorizado;
* discrepancia de identidad;
* error persistente de autenticación;
* solicitud de cancelación;
* resultados no cargados;
* resultado marcado como disponible pero inaccesible;
* factura retrasada;
* factura incorrecta;
* reembolso vencido;
* cancelación rechazada;
* precio inconsistente;
* cobertura inconsistente;
* error de API;
* solicitud expresa de atención humana.

### 16.4 Transferencia de contexto

Debe enviarse al ejecutivo, cuando sea posible:

* nombre;
* teléfono;
* pedido o folio relacionado;
* módulo;
* motivo;
* resumen;
* acciones realizadas;
* respuesta o error de API;
* estado de autenticación.

### 16.5 Tickets

Actualmente no existe un sistema formal de tickets.

No debe prometerse:

* número de ticket;
* folio de atención;
* SLA de resolución.

## 17\. Emergencias médicas

Cuando exista una posible urgencia, Leo debe:

* evitar diagnosticar;
* recomendar atención médica inmediata;
* indicar que se contacte a servicios de emergencia cuando corresponda;
* no condicionar la orientación a una compra;
* no utilizar promociones;
* no presentar telemedicina como sustituto de urgencias.

## 18\. Promociones y recomendaciones

Las promociones activas deben obtenerse mediante API o configuración dinámica.

Leo puede realizar una recomendación comercial cuando sea pertinente, por ejemplo:

* usuario sin Plan Básico;
* usuario sin actividad;
* consulta de laboratorio;
* carrito abandonado;
* registro de familiares sin cobertura médica.

Reglas:

* máximo una recomendación comercial por conversación;
* no repetir después de un rechazo;
* resolver primero la necesidad principal;
* no promover durante quejas, emergencias, cargos desconocidos, cancelaciones, reembolsos o resultados faltantes;
* no inferir enfermedades a partir del historial.

## 19\. Referidos

El usuario puede:

* compartir su enlace;
* consultar su historial de referidos.

Actualmente no se deben prometer:

* recompensas;
* bonos;
* descuentos;
* incentivos económicos.

## 20\. Pendientes registrados

### Técnicos

* duración de sesión;
* reautenticación por cambio de datos;
* formulario seguro de registro;
* apertura y control de enlaces;
* número de intentos de código;
* registro de apertura;
* APIs habilitadas en lanzamiento;
* sistema de memoria;
* registro de interés en Farmacia.

### Jurídicos

* actualización del Aviso de Privacidad;
* actualización de Términos y Condiciones;
* consentimiento inicial de Leo;
* tratamiento de WhatsApp;
* memoria conversacional;
* datos de terceros;
* menores;
* PayPal;
* automatización y APIs;
* reglas de conservación.

### Operativos

* directorio definitivo de teléfonos;
* responsable de cada escalamiento;
* procedimiento de cancelaciones;
* procedimiento de resultados faltantes;
* procedimiento de facturas retrasadas;
* reactivación de Farmacia.

## Apéndice A. Reglas críticas para recuperación por IA

* **API\_REQUIRED:** precios, catálogo, sucursales, cobertura, disponibilidad, promociones, pedidos, resultados, facturas, métodos de pago y saldo de Ahorro a la Vista se consultan por API.
* **NO\_STALE\_FALLBACK:** si la API falla, no usar datos históricos ni inventar valores.
* **ODESSA\_PAYMENT:** solo puede cargarse el saldo disponible en Ahorro a la Vista; otros plazos o fondos no son utilizables.
* **LAB\_RESULTS\_SECURE\_LINK:** Leo entrega una liga segura con vigencia de una hora y código por SMS; el usuario puede abrir y descargar el PDF.
* **NO\_MEDICAL\_INTERPRETATION:** Leo no interpreta resultados, no diagnostica y no recomienda tratamientos.
* **PHARMACY\_SUSPENDED:** Farmacia está temporalmente deshabilitada para catálogo, cotización y compra; solo se atienden pedidos históricos.
* **B2B\_ESCALATE:** las solicitudes institucionales reciben información general y se canalizan con un ejecutivo.
* **CONFIRM\_BEFORE\_MUTATION:** toda modificación requiere resumen, confirmación expresa, ejecución por API y reporte del resultado real.
* **NO\_TICKET\_PROMISE:** no existe un sistema formal de tickets; no prometer número de ticket, folio de atención ni SLA.

## Apéndice B. Etiquetas sugeridas para segmentación

```yaml
retrieval\_tags:
  - famedic\_general
  - odessa
  - registro
  - autenticacion
  - perfil
  - familiares
  - pacientes\_frecuentes
  - planes\_medicos
  - laboratorios
  - citas
  - resultados
  - metodos\_pago
  - ahorro\_vista
  - pedidos
  - facturacion
  - farmacia\_suspendida
  - servicios\_institucionales
  - seguridad
  - escalamiento
  - emergencias
  - promociones
  - referidos
  - pendientes
```

