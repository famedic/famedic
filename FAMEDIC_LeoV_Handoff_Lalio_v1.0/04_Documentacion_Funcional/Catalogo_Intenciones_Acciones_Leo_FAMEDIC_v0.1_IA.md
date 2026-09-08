\---

document\_type: intent\_catalog
document\_name: Catálogo de Intenciones y Acciones de Leo
version: "0.1"
status: working\_draft
language: es-MX
assistant\_identity: "Leo, tu guía virtual FAMEDIC"
secure\_link\_validity: "1 hora"
otp\_channel: SMS
priority\_order:

* emergencia
* seguridad
* cargos
* autenticacion
* resultados\_y\_facturas
* transacciones
* cuenta
* comercial
* promociones
* referidos
global\_rules:
* No inventar datos dinámicos
* No confirmar éxito sin respuesta API
* No interpretar resultados médicos
* No ejecutar cancelaciones
* No ejecutar checkout o pago en la etapa inicial
* Escalar solicitudes institucionales específicas

\---

# Catálogo de Intenciones y Acciones de Leo

**Versión de trabajo 0.1**

## 1\. Propósito

Este documento define las intenciones que Leo debe reconocer, los datos que necesita, las validaciones que debe realizar, las APIs que puede consultar y las condiciones de escalamiento.

Su objetivo es servir como puente entre:

* lenguaje natural del usuario;
* Base Maestra de Conocimiento;
* Reglas de Negocio;
* Manual Funcional;
* Guía Conversacional;
* APIs;
* seguridad;
* soporte humano.

Cada intención debe especificar:

* nombre técnico;
* descripción;
* ejemplos de usuario;
* datos mínimos;
* autenticación;
* verificación adicional;
* confirmación;
* consulta o modificación;
* fuente de verdad;
* respuesta esperada;
* errores;
* escalamiento;
* estado de disponibilidad.

## 2\. Estados de intención

|Estado|Definición|
|-|-|
|Informativa|Se responde con conocimiento estable|
|Consulta API|Requiere datos dinámicos|
|Transaccional|Modifica información o carrito|
|Transaccional en pruebas|Existe técnicamente, pero no está liberada|
|Escalamiento|Requiere atención humana|
|Suspendida|No debe ejecutarse actualmente|
|Futura|Planeada para una etapa posterior|
|Pendiente|Falta definición técnica u operativa|

## 3\. Estructura estándar de una intención

Cada intención deberá representarse con los siguientes campos:

|Campo|Descripción|
|-|-|
|`intent\_id`|Identificador único|
|`intent\_name`|Nombre técnico|
|`category`|Módulo funcional|
|`description`|Objetivo de la intención|
|`user\_examples`|Frases típicas|
|`required\_entities`|Datos obligatorios|
|`optional\_entities`|Datos opcionales|
|`authentication\_level`|Público, sesión o verificación adicional|
|`confirmation\_required`|Sí o no|
|`source`|Base de conocimiento o API|
|`api\_action`|Consulta o modificación|
|`success\_response`|Respuesta esperada|
|`error\_response`|Respuesta ante falla|
|`escalation\_rule`|Cuándo escalar|
|`availability\_status`|Estado funcional|

## 4\. Información general

### INT-GEN-001. Conocer FAMEDIC

**Nombre técnico:** `consultar\_que\_es\_famedic`

**Descripción:** Explica qué es FAMEDIC y qué servicios ofrece.

**Ejemplos:**

* “¿Qué es FAMEDIC?”
* “¿Qué servicios tienen?”
* “¿Cómo funciona?”

**Datos requeridos:** Ninguno.

**Autenticación:** No.

**Fuente:** Base de conocimiento.

**Respuesta:** Descripción general de FAMEDIC, servicios vigentes y aclaración de que Farmacia está temporalmente deshabilitada.

**Estado:** Informativa.

### INT-GEN-002. Diferencia entre FAMEDIC y ODESSA

**Nombre técnico:** `explicar\_relacion\_famedic\_odessa`

**Descripción:** Aclara la relación entre ambas plataformas.

**Ejemplos:**

* “¿FAMEDIC es parte de ODESSA?”
* “¿Es la misma plataforma?”
* “¿Por qué aparece mi caja ODESSA?”

**Datos requeridos:** Ninguno.

**Autenticación:** No.

**Fuente:** Base de conocimiento.

**Respuesta:** Explicar relación comercial y tecnológica, sin tratarlas como la misma plataforma.

**Estado:** Informativa.

### INT-GEN-003. Consultar servicios activos

**Nombre técnico:** `consultar\_servicios\_disponibles`

**Descripción:** Informa qué servicios están disponibles.

**Respuesta esperada:**

* Plan Básico;
* laboratorios;
* cuenta y autogestión;
* resultados;
* facturación;
* pedidos;
* servicios institucionales de forma general.

**Restricción:** Farmacia debe marcarse como suspendida.

**Estado:** Informativa.

## 5\. Registro y acceso

### INT-REG-001. Consultar requisitos de registro

**Nombre técnico:** `consultar\_requisitos\_registro`

**Ejemplos:**

* “¿Qué necesito para registrarme?”
* “¿Qué datos piden?”
* “¿Puedo registrarme con número extranjero?”

**Datos requeridos:** Ninguno.

**Autenticación:** No.

**Fuente:** Base de conocimiento.

**Respuesta:** Explicar campos obligatorios y restricción de teléfonos `+52`.

**Estado:** Informativa.

### INT-REG-002. Iniciar registro

**Nombre técnico:** `iniciar\_registro\_usuario`

**Descripción:** Inicia el formulario seguro de registro.

**Datos requeridos:**

* nombre;
* apellidos;
* correo;
* teléfono;
* fecha de nacimiento;
* sexo;
* aceptaciones legales.

**Dato sensible:** Contraseña mediante componente seguro.

**Autenticación:** No.

**Confirmación:** Sí.

**API:** Crear usuario.

**Validaciones:**

* teléfono mexicano;
* correo válido;
* duplicidad;
* campos obligatorios;
* aceptación legal.

**Respuesta exitosa:** Informar que el registro fue creado y que debe verificarse correo y SMS.

**Errores:**

* teléfono duplicado;
* correo duplicado;
* datos incompletos;
* error de API.

**Escalamiento:** Discrepancia de identidad o cuenta no reconocida.

**Estado:** Transaccional futura u objetivo.

### INT-REG-003. Verificar correo

**Nombre técnico:** `verificar\_correo\_registro`

**Descripción:** Confirma que el usuario accedió a la liga o mecanismo de verificación.

**Autenticación:** No.

**Fuente:** API.

**Estado:** Consulta o acción técnica.

### INT-REG-004. Verificar teléfono por SMS

**Nombre técnico:** `verificar\_telefono\_sms`

**Descripción:** Valida el código enviado por SMS.

**Dato sensible:** OTP.

**Regla:** No solicitarlo en texto abierto fuera del componente seguro.

**Estado:** Transaccional futura.

### INT-ACC-001. Iniciar sesión

**Nombre técnico:** `iniciar\_sesion`

**Datos requeridos:**

* teléfono o correo;
* contraseña en componente seguro.

**Autenticación:** No.

**Fuente:** API.

**Errores:**

* credenciales inválidas;
* sesión bloqueada;
* error técnico.

**Escalamiento:** Error persistente o usuario que no reconoce la cuenta.

**Estado:** Objetivo de Leo.

### INT-ACC-002. Recuperar contraseña

**Nombre técnico:** `recuperar\_contrasena`

**Descripción:** Genera y envía una liga segura.

**Datos requeridos:** Teléfono o correo.

**Autenticación:** No.

**Confirmación:** No.

**Fuente:** API.

**Respuesta:** Confirmar únicamente que la liga fue enviada si la API lo confirma.

**Estado:** Objetivo de Leo.

## 6\. Perfil

### INT-PER-001. Consultar perfil

**Nombre técnico:** `consultar\_perfil`

**Ejemplos:**

* “¿Qué datos tengo registrados?”
* “Muéstrame mi perfil.”

**Autenticación:** Sesión válida.

**Fuente:** API.

**Datos visibles:**

* nombre;
* apellidos;
* fecha de nacimiento;
* sexo;
* correo parcialmente protegido;
* teléfono parcialmente protegido.

**Estado:** Consulta API.

### INT-PER-002. Editar perfil

**Nombre técnico:** `editar\_perfil`

**Datos requeridos:** Campo a modificar y nuevo valor.

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Fuente:** API.

**Pendiente:** Definir revalidación de correo y teléfono.

**Escalamiento:** Discrepancia de identidad o error persistente.

**Estado:** Transaccional objetivo.

## 7\. Familiares

### INT-FAM-001. Consultar familiares

**Nombre técnico:** `consultar\_familiares`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Respuesta:** Lista de familiares activos con datos permitidos.

**Estado:** Consulta API.

### INT-FAM-002. Agregar familiar

**Nombre técnico:** `agregar\_familiar`

**Datos requeridos:**

* nombre;
* apellidos;
* parentesco;
* fecha de nacimiento;
* sexo.

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Validaciones:**

* grupo familiar;
* edad;
* campos obligatorios;
* no mezclar esquemas.

**Errores:**

* edad no elegible;
* parentesco incompatible;
* datos incompletos.

**Escalamiento:** Caso no contemplado o elegibilidad discutida.

**Estado:** Transaccional objetivo.

### INT-FAM-003. Editar familiar

**Nombre técnico:** `editar\_familiar`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Fuente:** API.

**Estado:** Transaccional objetivo.

### INT-FAM-004. Eliminar familiar

**Nombre técnico:** `eliminar\_familiar`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Respuesta obligatoria:** Aclarar que el historial no se elimina.

**Estado:** Transaccional objetivo.

## 8\. Pacientes frecuentes

### INT-PAC-001. Consultar pacientes

**Nombre técnico:** `consultar\_pacientes\_frecuentes`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-PAC-002. Agregar paciente

**Nombre técnico:** `agregar\_paciente\_frecuente`

**Datos requeridos:**

* nombre;
* apellidos;
* teléfono;
* fecha de nacimiento;
* sexo.

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

### INT-PAC-003. Editar paciente

**Nombre técnico:** `editar\_paciente\_frecuente`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

### INT-PAC-004. Eliminar paciente

**Nombre técnico:** `eliminar\_paciente\_frecuente`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Respuesta:** Informar que pedidos, resultados y facturas históricas permanecen.

**Estado:** Transaccional objetivo.

## 9\. Direcciones

### INT-DIR-001. Consultar direcciones

**Nombre técnico:** `consultar\_direcciones`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-DIR-002. Agregar dirección

**Nombre técnico:** `agregar\_direccion`

**Datos requeridos:**

* calle;
* número;
* colonia;
* estado;
* ciudad o municipio;
* código postal.

**Dato opcional:** Referencias.

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Regla:** Guardar una dirección no confirma cobertura.

**Estado:** Transaccional objetivo.

### INT-DIR-003. Editar dirección

**Nombre técnico:** `editar\_direccion`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

### INT-DIR-004. Eliminar dirección

**Nombre técnico:** `eliminar\_direccion`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

## 10\. Planes médicos

### INT-MED-001. Consultar Plan Básico

**Nombre técnico:** `consultar\_plan\_basico`

**Autenticación:** No.

**Fuente:** Base de conocimiento.

**Respuesta:**

* $300 MXN;
* IVA incluido;
* pago único;
* 12 meses;
* telemedicina ilimitada 24/7;
* psicología;
* nutrición;
* orientación legal;
* cobertura familiar.

**Estado:** Informativa.

### INT-MED-002. Consultar plan activo

**Nombre técnico:** `consultar\_plan\_activo`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Respuesta:** Tipo de plan, vigencia y condición individual o patrocinada.

**Estado:** Consulta API.

### INT-MED-003. Consultar cobertura familiar

**Nombre técnico:** `consultar\_cobertura\_familiar`

**Autenticación:** No.

**Fuente:** Base de conocimiento.

**Estado:** Informativa.

### INT-MED-004. Consultar Plan Intermedio o Completo

**Nombre técnico:** `consultar\_plan\_institucional`

**Autenticación:** No.

**Respuesta:** Información general y aclaración de que son institucionales.

**Escalamiento:** Solicitud específica de contratación.

**Estado:** Informativa + escalamiento.

### INT-MED-005. Consultar sustitución por patrocinio

**Nombre técnico:** `consultar\_sustitucion\_plan\_patronal`

**Autenticación:** Sesión válida si se consulta caso personal.

**Fuente:** Base + API.

**Respuesta:** Explicar sustitución y reembolso proporcional.

**Estado:** Consulta híbrida.

## 11\. Laboratorios

### INT-LAB-001. Buscar estudio

**Nombre técnico:** `buscar\_estudio\_laboratorio`

**Datos requeridos:**

* marca;
* nombre del estudio.

**Datos opcionales:**

* estado;
* ciudad;
* colonia.

**Autenticación:** No.

**Fuente:** API.

**Respuesta:** Coincidencias por nombre, alias o equivalencia configurada.

**Error:** No encontrado.

**Escalamiento:** Si no se identifica tras búsqueda razonable.

**Estado:** Consulta API.

### INT-LAB-002. Consultar precio de estudio

**Nombre técnico:** `consultar\_precio\_estudio`

**Datos requeridos:**

* marca;
* estudio;
* ubicación cuando aplique.

**Autenticación:** No.

**Fuente:** API.

**Respuesta:** Precio actualizado y descuento.

**Regla:** No usar precios históricos.

**Estado:** Consulta API.

### INT-LAB-003. Consultar preparación

**Nombre técnico:** `consultar\_preparacion\_estudio`

**Datos requeridos:** Estudio y marca.

**Fuente:** API.

**Estado:** Consulta API.

### INT-LAB-004. Consultar si requiere cita

**Nombre técnico:** `consultar\_requiere\_cita`

**Datos requeridos:** Estudio y marca.

**Fuente:** API.

**Respuesta:** Sí o no, según dato oficial.

**Estado:** Consulta API.

### INT-LAB-005. Consultar cobertura

**Nombre técnico:** `consultar\_cobertura\_laboratorio`

**Datos requeridos:**

* estado;
* ciudad;
* colonia.

**Fuente:** API.

**Estado:** Consulta API.

### INT-LAB-006. Consultar sucursales

**Nombre técnico:** `consultar\_sucursales`

**Datos requeridos:**

* marca;
* ubicación.

**Fuente:** API.

**Estado:** Consulta API.

### INT-LAB-007. Estudio no encontrado

**Nombre técnico:** `manejar\_estudio\_no\_encontrado`

**Descripción:** Intención secundaria que se activa cuando no hay coincidencia.

**Acción:**

1. intentar alias;
2. intentar nombre común;
3. no inventar equivalencia;
4. canalizar.

**Estado:** Regla de excepción.

## 12\. Carrito

### INT-CAR-001. Consultar carrito

**Nombre técnico:** `consultar\_carrito`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Respuesta:** Estudios, paciente, precios y total.

**Estado:** Consulta API.

### INT-CAR-002. Agregar estudio al carrito

**Nombre técnico:** `agregar\_estudio\_carrito`

**Datos requeridos:**

* estudio;
* marca;
* paciente;
* cantidad si aplica.

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Fuente:** API.

**Respuesta:** Confirmar solo si la API responde con éxito.

**Estado:** Transaccional objetivo.

### INT-CAR-003. Eliminar estudio del carrito

**Nombre técnico:** `eliminar\_estudio\_carrito`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

### INT-CAR-004. Modificar carrito

**Nombre técnico:** `modificar\_carrito`

**Autenticación:** Sesión válida.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

### INT-CAR-005. Detectar estudio con cita

**Nombre técnico:** `detectar\_requisito\_cita\_carrito`

**Fuente:** API.

**Respuesta:** Informar si el checkout requerirá concierge.

**Estado:** Consulta API.

## 13\. Checkout y pago

### INT-CHK-001. Explicar checkout

**Nombre técnico:** `explicar\_checkout`

**Autenticación:** No.

**Fuente:** Base de conocimiento.

**Estado:** Informativa.

### INT-CHK-002. Iniciar checkout

**Nombre técnico:** `iniciar\_checkout`

**Autenticación:** Sesión válida.

**Estado:** No habilitada para Leo en etapa inicial.

### INT-PAG-001. Consultar métodos de pago

**Nombre técnico:** `consultar\_metodos\_pago`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Respuesta:** Métodos disponibles.

**Estado:** Consulta API.

### INT-PAG-002. Consultar saldo Ahorro a la Vista

**Nombre técnico:** `consultar\_saldo\_ahorro\_vista`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Respuesta:** Saldo disponible utilizable, si la política permite mostrarlo.

**Regla:** No sumar otros plazos.

**Estado:** Consulta API.

### INT-PAG-003. Validar pago ODESSA

**Nombre técnico:** `validar\_pago\_odessa`

**Validaciones:**

* cuenta vinculada;
* Ahorro a la Vista habilitado;
* saldo suficiente.

**Estado:** Consulta previa al pago.

### INT-PAG-004. Ejecutar pago

**Nombre técnico:** `ejecutar\_pago`

**Estado:** Fuera de alcance inicial de Leo.

## 14\. Pedidos

### INT-PED-001. Consultar pedidos

**Nombre técnico:** `consultar\_pedidos`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-PED-002. Consultar detalle de pedido

**Nombre técnico:** `consultar\_detalle\_pedido`

**Datos requeridos:** Folio o selección de pedido.

**Autenticación:** Sesión válida.

**Respuesta:** Paciente, estudios, importe, estado, método de pago, resultados, factura y cronología.

**Estado:** Consulta API.

### INT-PED-003. Consultar pedido histórico de Farmacia

**Nombre técnico:** `consultar\_pedido\_farmacia\_historico`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-PED-004. Solicitar cancelación

**Nombre técnico:** `solicitar\_cancelacion\_pedido`

**Autenticación:** Sesión válida.

**Acción:** Explicar condiciones y escalar.

**Leo no ejecuta la cancelación.**

**Estado:** Escalamiento.

## 15\. Resultados

### INT-RES-001. Consultar disponibilidad de resultados

**Nombre técnico:** `consultar\_disponibilidad\_resultados`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-RES-002. Abrir resultados

**Nombre técnico:** `abrir\_resultados`

**Autenticación:** Verificación adicional.

**Flujo:**

1. validar resultado;
2. generar liga segura;
3. enviar código SMS;
4. validar código;
5. permitir apertura.

**Vigencia:** Una hora.

**Estado:** Objetivo de Leo.

### INT-RES-003. Descargar resultados PDF

**Nombre técnico:** `descargar\_resultados\_pdf`

**Autenticación:** Verificación adicional.

**Fuente:** API.

**Regla:** No enviar como adjunto directo por WhatsApp.

**Estado:** Objetivo de Leo.

### INT-RES-004. Interpretar resultados

**Nombre técnico:** `interpretar\_resultados`

**Estado:** Prohibida.

**Respuesta:** Leo puede facilitar acceso, pero no interpretar ni diagnosticar.

### INT-RES-005. Resultado no cargado

**Nombre técnico:** `reportar\_resultado\_no\_cargado`

**Autenticación:** Sesión válida.

**Acción:** Consultar estado y escalar.

**Estado:** Escalamiento.

## 16\. Facturación

### INT-FAC-001. Consultar factura

**Nombre técnico:** `consultar\_factura`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-FAC-002. Solicitar factura

**Nombre técnico:** `solicitar\_factura`

**Datos requeridos:**

* pedido;
* perfil fiscal;
* uso de CFDI.

**Autenticación:** Verificación adicional.

**Confirmación:** Sí.

**Validación:** Dentro del mismo mes calendario.

**Estado:** Transaccional objetivo.

### INT-FAC-003. Abrir factura

**Nombre técnico:** `abrir\_factura`

**Autenticación:** Verificación adicional.

**Respuesta:** Liga segura.

**Estado:** Objetivo de Leo.

### INT-FAC-004. Descargar factura

**Nombre técnico:** `descargar\_factura`

**Autenticación:** Verificación adicional.

**Estado:** Objetivo de Leo.

### INT-FAC-005. Factura retrasada

**Nombre técnico:** `reportar\_factura\_retrasada`

**Acción:** Escalar.

**Estado:** Escalamiento.

## 17\. Perfiles fiscales

### INT-FIS-001. Consultar perfiles fiscales

**Nombre técnico:** `consultar\_perfiles\_fiscales`

**Autenticación:** Verificación adicional.

**Fuente:** API.

**Estado:** Consulta API.

### INT-FIS-002. Crear perfil fiscal

**Nombre técnico:** `crear\_perfil\_fiscal`

**Autenticación:** Verificación adicional.

**Confirmación:** Sí.

**Regla:** Constancia en componente seguro.

**Estado:** Transaccional objetivo.

### INT-FIS-003. Editar perfil fiscal

**Nombre técnico:** `editar\_perfil\_fiscal`

**Autenticación:** Verificación adicional.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

### INT-FIS-004. Eliminar perfil fiscal

**Nombre técnico:** `eliminar\_perfil\_fiscal`

**Autenticación:** Verificación adicional.

**Confirmación:** Sí.

**Estado:** Transaccional objetivo.

## 18\. Farmacia

### INT-FAR-001. Consultar Farmacia

**Nombre técnico:** `consultar\_estado\_farmacia`

**Respuesta:** Informar suspensión temporal.

**Estado:** Informativa.

### INT-FAR-002. Buscar medicamento

**Nombre técnico:** `buscar\_medicamento`

**Estado:** Suspendida.

**Respuesta:** No disponible.

### INT-FAR-003. Comprar medicamento

**Nombre técnico:** `comprar\_medicamento`

**Estado:** Suspendida.

### INT-FAR-004. Consultar pedido histórico

**Nombre técnico:** `consultar\_pedido\_farmacia`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-FAR-005. Registrar interés en reactivación

**Nombre técnico:** `registrar\_interes\_farmacia`

**Autenticación:** Puede requerir cuenta o consentimiento.

**Confirmación:** Sí.

**Estado:** Pendiente técnico y jurídico.

## 19\. Servicios institucionales

### INT-B2B-001. Consultar servicios institucionales

**Nombre técnico:** `consultar\_servicios\_institucionales`

**Autenticación:** No.

**Fuente:** Base de conocimiento.

**Respuesta:** Información general.

**Estado:** Informativa.

### INT-B2B-002. Solicitar cotización institucional

**Nombre técnico:** `solicitar\_contacto\_b2b`

**Datos sugeridos:**

* nombre;
* empresa;
* teléfono;
* correo;
* ubicación;
* servicio;
* colaboradores aproximados.

**Acción:** Canalizar con ejecutivo.

**Estado:** Escalamiento.

## 20\. Promociones y referidos

### INT-PRO-001. Consultar promociones

**Nombre técnico:** `consultar\_promociones`

**Fuente:** API o configuración.

**Estado:** Consulta API.

### INT-PRO-002. Recomendar Plan Básico

**Nombre técnico:** `recomendar\_plan\_basico`

**Condiciones:**

* no tiene plan;
* contexto adecuado;
* necesidad principal resuelta;
* no más de una recomendación.

**Estado:** Regla comercial.

### INT-REF-001. Consultar enlace de referido

**Nombre técnico:** `consultar\_enlace\_referido`

**Autenticación:** Sesión válida.

**Fuente:** API.

**Estado:** Consulta API.

### INT-REF-002. Consultar historial de referidos

**Nombre técnico:** `consultar\_historial\_referidos`

**Autenticación:** Sesión válida.

**Estado:** Consulta API.

## 21\. Soporte y escalamiento

### INT-SOP-001. Solicitar atención humana

**Nombre técnico:** `solicitar\_atencion\_humana`

**Autenticación:** Según caso.

**Acción:** Transferir contexto.

**Estado:** Escalamiento.

### INT-SOP-002. Reportar cargo desconocido

**Nombre técnico:** `reportar\_cargo\_desconocido`

**Autenticación:** Sesión válida o verificación.

**Acción:** Escalar inmediatamente.

**Estado:** Escalamiento prioritario.

### INT-SOP-003. Reportar cargo duplicado

**Nombre técnico:** `reportar\_cargo\_duplicado`

**Acción:** Escalar.

**Estado:** Escalamiento prioritario.

### INT-SOP-004. Reportar acceso no autorizado

**Nombre técnico:** `reportar\_acceso\_no\_autorizado`

**Acción:** Escalar con prioridad alta.

**Estado:** Escalamiento crítico.

### INT-SOP-005. Reportar error técnico

**Nombre técnico:** `reportar\_error\_api`

**Acción:**

* informar falla;
* conservar contexto;
* no fingir éxito;
* escalar si persiste.

**Estado:** Escalamiento según severidad.

## 22\. Emergencias y seguridad médica

### INT-EME-001. Detectar posible urgencia

**Nombre técnico:** `detectar\_emergencia\_medica`

**Ejemplos:**

* “No puedo respirar.”
* “Tengo dolor intenso en el pecho.”
* “Me desmayé.”
* “Estoy sangrando mucho.”

**Acción:**

* recomendar atención inmediata;
* indicar servicios de emergencia o urgencias;
* no diagnosticar;
* no promover;
* no retrasar.

**Estado:** Regla de seguridad crítica.

## 23\. Privacidad

### INT-PRI-001. Consultar Aviso de Privacidad

**Nombre técnico:** `consultar\_aviso\_privacidad`

**Fuente:** Documento jurídico aprobado.

**Estado:** Informativa.

### INT-PRI-002. Consultar derechos ARCO

**Nombre técnico:** `consultar\_derechos\_arco`

**Respuesta:** Explicar de forma general y canalizar a `contacto@famedic.com.mx`.

**Estado:** Informativa + canalización.

### INT-PRI-003. Solicitar eliminación de cuenta

**Nombre técnico:** `solicitar\_eliminacion\_cuenta`

**Acción:** Canalizar.

**Leo no ejecuta la eliminación.**

**Estado:** Escalamiento.

## 24\. Manejo de ambigüedad

Cuando una frase pueda corresponder a varias intenciones, Leo debe pedir precisión.

Ejemplo:

Usuario:

> Quiero cancelar.

Leo debe preguntar:

> ¿Quieres cancelar un pedido, eliminar un dato de tu cuenta o dejar de recibir comunicaciones?

No debe asumir la intención.

## 25\. Entidades principales

|Entidad|Descripción|
|-|-|
|`user\_id`|Identificador de usuario|
|`phone`|Teléfono con prefijo +52|
|`email`|Correo electrónico|
|`patient\_id`|Identificador de paciente|
|`family\_member\_id`|Identificador de familiar|
|`study\_id`|Identificador de estudio|
|`study\_name`|Nombre del estudio|
|`lab\_brand`|Marca o laboratorio|
|`state`|Estado|
|`city`|Ciudad o municipio|
|`colony`|Colonia|
|`branch\_id`|Sucursal|
|`cart\_id`|Carrito|
|`order\_id`|Pedido|
|`folio`|Folio visible|
|`result\_id`|Resultado|
|`invoice\_id`|Factura|
|`fiscal\_profile\_id`|Perfil fiscal|
|`payment\_method\_id`|Método de pago|
|`odessa\_account\_id`|Cuenta ODESSA|
|`sight\_savings\_balance`|Saldo disponible en Ahorro a la Vista|
|`otp`|Código de verificación|
|`secure\_link`|Liga segura|
|`consent\_version`|Versión aceptada|
|`channel`|Canal de interacción|

## 26\. Niveles de autenticación

|Nivel|Descripción|Ejemplos|
|-|-|-|
|Público|No requiere sesión|Información general, precios públicos|
|Sesión|Usuario autenticado|Perfil, pedidos, familiares|
|Verificación adicional|Código u otra validación|Resultados, facturas, perfiles fiscales|
|Componente seguro|Captura fuera del chat|Contraseña, tarjeta, constancia, OTP|

## 27\. Reglas de prioridad

Cuando varias intenciones compitan, aplicar este orden:

1. emergencia;
2. seguridad o acceso no autorizado;
3. cargo desconocido o duplicado;
4. autenticación;
5. resultado o factura faltante;
6. operación transaccional;
7. consulta de cuenta;
8. consulta comercial;
9. promoción;
10. referidos.

## 28\. Reglas de contexto

Leo debe conservar durante la conversación, cuando sea seguro:

* usuario identificado;
* paciente seleccionado;
* marca;
* estudio;
* ubicación;
* pedido;
* carrito;
* acción pendiente;
* confirmación recibida;
* error previo;
* escalamiento iniciado.

No debe conservar como memoria conversacional:

* contraseña;
* OTP;
* tarjeta completa;
* CVV;
* resultado clínico completo;
* constancia fiscal completa;
* credenciales bancarias.

## 29\. Respuesta ante falla de API

### Consulta

> En este momento no puedo consultar la información actualizada. Prefiero no mostrarte un dato que pueda estar desactualizado.

### Modificación

> La operación no se completó. No realicé ningún cambio.

### Error persistente

> El problema continúa y requiere revisión de atención. Conservaré el contexto para canalizarlo.

## 30\. Matriz resumida de intenciones

|Categoría|Intenciones principales|Tipo|
|-|-|-|
|General|FAMEDIC, ODESSA, servicios|Informativa|
|Registro|requisitos, alta, verificación|Informativa/transaccional|
|Acceso|login, recuperación|Transaccional|
|Perfil|consultar, editar|API/transaccional|
|Familiares|consultar, agregar, editar, eliminar|API/transaccional|
|Pacientes|consultar, agregar, editar, eliminar|API/transaccional|
|Direcciones|consultar, agregar, editar, eliminar|API/transaccional|
|Planes|Básico, plan activo, patrocinado|Informativa/API|
|Laboratorios|buscar, cotizar, preparar, cobertura|API|
|Carrito|consultar, agregar, eliminar, modificar|API/transaccional|
|Checkout|explicar, iniciar|Informativa/futura|
|Pagos|métodos, ODESSA, saldo|API|
|Pedidos|consultar, detalle, cancelación|API/escalamiento|
|Resultados|disponibilidad, abrir, descargar|API/verificación|
|Facturas|consultar, solicitar, descargar|API/verificación|
|Fiscal|perfiles, constancia|API/transaccional|
|Farmacia|suspensión, histórico, interés|Informativa/API|
|B2B|información, contacto|Informativa/escalamiento|
|Promociones|consultar, recomendar|API/regla|
|Referidos|enlace, historial|API|
|Soporte|cargos, errores, humano|Escalamiento|
|Emergencias|urgencia|Seguridad|
|Privacidad|aviso, ARCO, eliminación|Informativa/escalamiento|

## 31\. Pendientes técnicos

* nombres definitivos de endpoints;
* métodos HTTP;
* payloads;
* códigos de respuesta;
* reglas de idempotencia;
* control de reintentos;
* expiración de sesión;
* expiración de OTP;
* máximo de intentos;
* accesos permitidos por liga;
* disponibilidad por canal;
* capacidades liberadas al lanzamiento;
* políticas de caché;
* trazabilidad;
* manejo de consentimientos.

## 32\. Pendientes funcionales

* flujo final de registro embebido;
* verificación de cambios de correo y teléfono;
* visualización de saldo ODESSA;
* alta de métodos de pago mediante Leo;
* alcance de perfiles fiscales;
* registro de interés en Farmacia;
* checkout futuro;
* pago futuro;
* comunicación proactiva;
* manejo de conversaciones simultáneas.

## 33\. Pendientes de seguridad y jurídicos

* consentimiento de primer uso;
* memoria conversacional;
* conservación de conversaciones;
* tratamiento de datos de terceros;
* menores;
* WhatsApp;
* PayPal;
* advertencia de seguridad del dispositivo;
* automatización;
* evidencia de aceptación;
* política de enlaces seguros.

Este catálogo será la base para construir la siguiente pieza: la **Matriz de Capacidades, APIs y Permisos**, donde cada intención quedará vinculada con endpoint, autenticación, confirmación, sensibilidad, respuesta y estado de liberación.

## Apéndice IA. Etiquetas de recuperación

```yaml
retrieval\_tags:
  - general
  - registro
  - acceso
  - perfil
  - familiares
  - pacientes
  - direcciones
  - planes\_medicos
  - laboratorios
  - carrito
  - checkout
  - pagos
  - odessa
  - pedidos
  - resultados
  - facturacion
  - fiscal
  - farmacia
  - b2b
  - promociones
  - referidos
  - soporte
  - emergencias
  - privacidad
  - ambiguedad
  - entidades
  - autenticacion
  - contexto
  - errores\_api
  - pendientes
```

