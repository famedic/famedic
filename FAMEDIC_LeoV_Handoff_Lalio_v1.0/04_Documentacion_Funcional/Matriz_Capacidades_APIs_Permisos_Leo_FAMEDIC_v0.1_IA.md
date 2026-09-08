\---

document\_type: capability\_api\_permission\_matrix
document\_name: Matriz de Capacidades, APIs y Permisos de Leo
version: "0.1"
status: working\_draft
language: es-MX
assistant\_identity: "Leo, tu guía virtual FAMEDIC"
release\_rule: "Solo ejecutar capacidades con estado Habilitada en producción"
secure\_link\_validity: "1 hora"
otp\_channel: SMS
global\_api\_rules:

* Confirmar éxito solo con respuesta positiva de API
* No usar datos históricos ante falla
* No reintentar modificaciones sin idempotencia
* Registrar trazabilidad
* Aplicar minimización y enmascaramiento de datos

\---

# Matriz de Capacidades, APIs y Permisos de Leo

**Versión de trabajo 0.1**

## 1\. Propósito

Este documento define qué capacidades podrá tener Leo, qué APIs necesita, qué nivel de autenticación aplica, cuándo debe solicitar confirmación, qué datos puede mostrar y en qué estado se encuentra cada función.

Su objetivo es servir como referencia para:

* producto;
* desarrollo;
* seguridad;
* operaciones;
* atención;
* jurídico;
* pruebas;
* liberación por etapas.

La matriz debe evitar que una capacidad técnica se confunda con una función disponible para el usuario.

## 2\. Principio de liberación

Cada capacidad debe distinguir entre:

* **capacidad diseñada**;
* **API disponible**;
* **integración terminada**;
* **pruebas completadas**;
* **función liberada al usuario**.

Una API existente no autoriza automáticamente a Leo a utilizarla.

Leo solo deberá ejecutar capacidades cuyo estado sea:

> \*\*Habilitada en producción\*\*

## 3\. Estados de capacidad

|Estado|Definición|
|-|-|
|Informativa|Respuesta basada en conocimiento estable|
|Consulta habilitada|Puede consultar datos por API|
|Transacción en desarrollo|Diseño o API en construcción|
|Transacción en pruebas|Integración lista, pero no liberada|
|Habilitada en producción|Disponible para usuarios|
|Escalamiento|Debe canalizarse a atención humana|
|Fuera de alcance|No se implementará en la etapa actual|
|Suspendida|Existía, pero no debe utilizarse|
|Pendiente|Falta definición|

## 4\. Niveles de acceso

### Nivel 0. Público

No requiere sesión.

Permite:

* información general;
* búsqueda pública de estudios;
* consulta de precios públicos;
* cobertura;
* planes;
* requisitos;
* orientación.

### Nivel 1. Sesión autenticada

Requiere usuario identificado y sesión válida.

Permite:

* perfil;
* pedidos;
* familiares;
* pacientes;
* direcciones;
* carrito;
* métodos de pago visibles;
* plan activo;
* historial.

### Nivel 2. Verificación adicional

Requiere código enviado por SMS.

Aplica a:

* resultados;
* facturas;
* perfiles fiscales;
* documentos sensibles;
* otras acciones de riesgo elevado.

### Nivel 3. Componente seguro

Captura fuera de la conversación.

Aplica a:

* contraseña;
* OTP;
* tarjeta;
* CVV;
* Constancia de Situación Fiscal;
* documentos sensibles;
* recuperación de acceso.

## 5\. Tipos de acción

|Tipo|Descripción|
|-|-|
|READ|Consulta información|
|CREATE|Crea un registro|
|UPDATE|Modifica información|
|DELETE|Elimina un registro activo|
|VALIDATE|Valida identidad, saldo o condición|
|GENERATE|Genera liga, código o documento|
|ESCALATE|Envía el caso a atención|
|NONE|No requiere API|

## 6\. Reglas generales de API

### API-001. Confirmación de éxito

Leo solo debe informar que una operación se completó cuando la API confirme éxito.

### API-002. Falla de consulta

Si falla una consulta:

* no utilizar datos históricos;
* no inventar;
* no asumir;
* informar indisponibilidad;
* escalar cuando el dato sea necesario para continuar.

### API-003. Falla de modificación

Si falla una modificación:

* informar que no se realizó;
* no afirmar cambios parciales sin evidencia;
* conservar el contexto;
* revisar idempotencia antes de reintentar.

### API-004. Idempotencia

Las operaciones transaccionales deben incluir un identificador único para evitar duplicados.

Aplica especialmente a:

* registro;
* alta de familiares;
* alta de pacientes;
* carrito;
* solicitud de factura;
* generación de liga;
* pago futuro.

### API-005. Trazabilidad

Toda operación debe registrar:

* usuario;
* canal;
* intención;
* fecha y hora;
* endpoint;
* operación;
* confirmación;
* resultado;
* código de respuesta;
* identificador generado;
* error;
* escalamiento.

### API-006. Datos mínimos

Leo solo debe enviar a la API los datos necesarios para completar la acción.

### API-007. Enmascaramiento

La API o capa de presentación debe devolver datos sensibles de forma protegida cuando corresponda.

## 7\. Matriz general de capacidades

|ID|Capacidad|Acción|Acceso|Confirmación|API|Sensibilidad|Estado inicial|
|-|-|-|-|-|-|-|-|
|CAP-GEN-001|Explicar qué es FAMEDIC|NONE|Público|No|No|Baja|Informativa|
|CAP-GEN-002|Explicar relación con ODESSA|NONE|Público|No|No|Baja|Informativa|
|CAP-GEN-003|Consultar servicios activos|READ|Público|No|Config/API|Baja|Consulta habilitada|
|CAP-REG-001|Consultar requisitos de registro|NONE|Público|No|No|Baja|Informativa|
|CAP-REG-002|Iniciar registro|CREATE|Público + seguro|Sí|Sí|Alta|En desarrollo|
|CAP-REG-003|Validar duplicidad|VALIDATE|Público|No|Sí|Media|En desarrollo|
|CAP-REG-004|Verificar correo|VALIDATE|Público|No|Sí|Media|En desarrollo|
|CAP-REG-005|Verificar teléfono por SMS|VALIDATE|Componente seguro|No|Sí|Alta|En desarrollo|
|CAP-ACC-001|Iniciar sesión|VALIDATE|Componente seguro|No|Sí|Alta|En desarrollo|
|CAP-ACC-002|Recuperar contraseña|GENERATE|Público|No|Sí|Alta|En desarrollo|
|CAP-PER-001|Consultar perfil|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-PER-002|Editar perfil|UPDATE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-FAM-001|Consultar familiares|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-FAM-002|Agregar familiar|CREATE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-FAM-003|Editar familiar|UPDATE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-FAM-004|Eliminar familiar|DELETE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-PAC-001|Consultar pacientes|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-PAC-002|Agregar paciente|CREATE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-PAC-003|Editar paciente|UPDATE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-PAC-004|Eliminar paciente|DELETE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-DIR-001|Consultar direcciones|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-DIR-002|Agregar dirección|CREATE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-DIR-003|Editar dirección|UPDATE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-DIR-004|Eliminar dirección|DELETE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-MED-001|Consultar Plan Básico|NONE|Público|No|No|Baja|Informativa|
|CAP-MED-002|Consultar plan activo|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-MED-003|Consultar beneficios del plan|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-MED-004|Contratar Plan Básico|CREATE|Sesión|Sí|Sí|Alta|Pendiente|
|CAP-MED-005|Consultar sustitución por patrocinio|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-LAB-001|Buscar estudio|READ|Público|No|Sí|Baja|Objetivo|
|CAP-LAB-002|Consultar precio|READ|Público|No|Sí|Baja|Objetivo|
|CAP-LAB-003|Consultar preparación|READ|Público|No|Sí|Baja|Objetivo|
|CAP-LAB-004|Consultar requisito de cita|READ|Público|No|Sí|Baja|Objetivo|
|CAP-LAB-005|Consultar cobertura|READ|Público|No|Sí|Baja|Objetivo|
|CAP-LAB-006|Consultar sucursales|READ|Público|No|Sí|Baja|Objetivo|
|CAP-CAR-001|Consultar carrito|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-CAR-002|Agregar estudio al carrito|CREATE|Sesión|Sí|Sí|Media|Objetivo|
|CAP-CAR-003|Eliminar estudio del carrito|DELETE|Sesión|Sí|Sí|Media|Objetivo|
|CAP-CAR-004|Modificar carrito|UPDATE|Sesión|Sí|Sí|Media|Objetivo|
|CAP-CHK-001|Explicar checkout|NONE|Público|No|No|Baja|Informativa|
|CAP-CHK-002|Iniciar checkout|CREATE|Sesión|Sí|Sí|Alta|Fuera de alcance inicial|
|CAP-PAG-001|Consultar métodos de pago|READ|Sesión|No|Sí|Alta|Objetivo|
|CAP-PAG-002|Consultar saldo Ahorro a la Vista|READ|Sesión|No|Sí|Alta|Objetivo|
|CAP-PAG-003|Validar pago ODESSA|VALIDATE|Sesión|No|Sí|Alta|Objetivo|
|CAP-PAG-004|Agregar tarjeta|CREATE|Seguro|Sí|Sí|Crítica|Pendiente|
|CAP-PAG-005|Eliminar tarjeta|DELETE|Sesión|Sí|Sí|Alta|Objetivo|
|CAP-PAG-006|Ejecutar pago|CREATE|Sesión + seguro|Sí|Sí|Crítica|Fuera de alcance inicial|
|CAP-PED-001|Consultar pedidos|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-PED-002|Consultar detalle|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-PED-003|Consultar pedido histórico de Farmacia|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-PED-004|Cancelar pedido|UPDATE|Sesión|Sí|Sí|Alta|Escalamiento|
|CAP-RES-001|Consultar disponibilidad de resultados|READ|Sesión|No|Sí|Alta|Objetivo|
|CAP-RES-002|Generar liga de resultados|GENERATE|Verificación adicional|No|Sí|Crítica|Objetivo|
|CAP-RES-003|Descargar resultado PDF|READ|Verificación adicional|No|Sí|Crítica|Objetivo|
|CAP-RES-004|Interpretar resultados|NONE|N/A|No|No|Crítica|Prohibida|
|CAP-FAC-001|Consultar factura|READ|Sesión|No|Sí|Alta|Objetivo|
|CAP-FAC-002|Solicitar factura|CREATE|Verificación adicional|Sí|Sí|Alta|Objetivo|
|CAP-FAC-003|Generar liga de factura|GENERATE|Verificación adicional|No|Sí|Alta|Objetivo|
|CAP-FAC-004|Descargar factura|READ|Verificación adicional|No|Sí|Alta|Objetivo|
|CAP-FIS-001|Consultar perfiles fiscales|READ|Verificación adicional|No|Sí|Alta|Objetivo|
|CAP-FIS-002|Crear perfil fiscal|CREATE|Verificación adicional + seguro|Sí|Sí|Crítica|Objetivo|
|CAP-FIS-003|Editar perfil fiscal|UPDATE|Verificación adicional|Sí|Sí|Crítica|Objetivo|
|CAP-FIS-004|Eliminar perfil fiscal|DELETE|Verificación adicional|Sí|Sí|Crítica|Objetivo|
|CAP-FAR-001|Consultar estado de Farmacia|NONE|Público|No|No|Baja|Informativa|
|CAP-FAR-002|Buscar medicamento|READ|Público|No|Sí|Media|Suspendida|
|CAP-FAR-003|Comprar medicamento|CREATE|Sesión|Sí|Sí|Alta|Suspendida|
|CAP-FAR-004|Consultar histórico de Farmacia|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-FAR-005|Registrar interés|CREATE|Cuenta/consentimiento|Sí|Sí|Media|Pendiente|
|CAP-B2B-001|Informar servicios institucionales|NONE|Público|No|No|Baja|Informativa|
|CAP-B2B-002|Capturar contacto institucional|CREATE|Público|Sí|Sí/CRM|Media|Pendiente|
|CAP-B2B-003|Cotizar servicios institucionales|NONE|N/A|No|No|Media|Escalamiento|
|CAP-PRO-001|Consultar promociones|READ|Según caso|No|Sí/config|Baja|Objetivo|
|CAP-PRO-002|Recomendar servicio|NONE|Según caso|No|No|Media|Regla comercial|
|CAP-REF-001|Consultar enlace de referido|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-REF-002|Consultar historial de referidos|READ|Sesión|No|Sí|Media|Objetivo|
|CAP-SOP-001|Solicitar humano|ESCALATE|Según caso|No|Atención|Media|Habilitada|
|CAP-SOP-002|Reportar cargo desconocido|ESCALATE|Sesión/verificación|No|Sí + atención|Crítica|Habilitada|
|CAP-SOP-003|Reportar cargo duplicado|ESCALATE|Sesión/verificación|No|Sí + atención|Crítica|Habilitada|
|CAP-SOP-004|Reportar acceso no autorizado|ESCALATE|Según caso|No|Atención|Crítica|Habilitada|
|CAP-SOP-005|Reportar error técnico|ESCALATE|Según caso|No|Sí|Media|Habilitada|
|CAP-PRI-001|Consultar Aviso de Privacidad|NONE|Público|No|No|Baja|Informativa|
|CAP-PRI-002|Consultar derechos ARCO|NONE|Público|No|No|Baja|Informativa|
|CAP-PRI-003|Eliminar cuenta|DELETE|Verificación adicional|Sí|Sí|Crítica|Escalamiento|

## 8\. Matriz de permisos por dominio

### 8.1 Registro y acceso

|Capacidad|Leo puede leer|Leo puede modificar|Requiere seguro|Requiere confirmación|
|-|-:|-:|-:|-:|
|Requisitos|Sí|No|No|No|
|Registro|Sí|Sí|Sí|Sí|
|Verificación de correo|Sí|Sí|Sí|No|
|Verificación SMS|No directamente|Sí|Sí|No|
|Inicio de sesión|No credenciales|Sí|Sí|No|
|Recuperación|Sí|Sí|Sí|No|

### 8.2 Perfil

|Capacidad|Consulta|Modificación|Confirmación|Verificación adicional|
|-|-:|-:|-:|-:|
|Nombre|Sí|Sí|Sí|No|
|Apellidos|Sí|Sí|Sí|No|
|Fecha de nacimiento|Sí|Sí|Sí|Según política|
|Sexo|Sí|Sí|Sí|No|
|Correo|Parcial|Sí|Sí|Pendiente|
|Teléfono|Parcial|Sí|Sí|Pendiente|
|Contraseña|No visible|Sí|Sí|Componente seguro|

### 8.3 Familiares y pacientes

|Acción|Sesión|Confirmación|Regla especial|
|-|-:|-:|-|
|Consultar|Sí|No|Datos permitidos|
|Crear|Sí|Sí|Validar campos|
|Editar|Sí|Sí|Validar campos|
|Eliminar|Sí|Sí|No borra historial|

### 8.4 Laboratorios

|Acción|Público|Sesión|Confirmación|Fuente|
|-|-:|-:|-:|-|
|Buscar estudio|Sí|No necesaria|No|API|
|Consultar precio|Sí|No necesaria|No|API|
|Consultar preparación|Sí|No necesaria|No|API|
|Consultar cita|Sí|No necesaria|No|API|
|Consultar cobertura|Sí|No necesaria|No|API|
|Agregar al carrito|No|Sí|Sí|API|
|Checkout|No|Sí|Sí|Fuera de alcance inicial|
|Pago|No|Sí|Sí|Fuera de alcance inicial|

### 8.5 Resultados

|Acción|Sesión|Código SMS|Liga segura|Vigencia|
|-|-:|-:|-:|-:|
|Consultar existencia|Sí|No|No|N/A|
|Abrir|Sí|Sí|Sí|1 hora|
|Descargar PDF|Sí|Sí|Sí|1 hora|
|Interpretar|No permitido|N/A|N/A|N/A|

### 8.6 Facturación

|Acción|Sesión|Verificación|Confirmación|Componente seguro|
|-|-:|-:|-:|-:|
|Consultar estado|Sí|Según dato|No|No|
|Solicitar|Sí|Sí|Sí|No|
|Abrir|Sí|Sí|No|Liga|
|Descargar|Sí|Sí|No|Liga|
|Crear perfil fiscal|Sí|Sí|Sí|Sí|
|Editar perfil fiscal|Sí|Sí|Sí|Según dato|
|Eliminar perfil fiscal|Sí|Sí|Sí|No|

## 9\. APIs requeridas por módulo

### 9.1 Identidad

Endpoints conceptuales requeridos:

* detectar usuario por teléfono;
* detectar usuario por correo;
* validar sesión;
* autenticar;
* cerrar sesión;
* recuperar contraseña;
* verificar correo;
* verificar SMS;
* consultar versión de consentimiento.

### 9.2 Usuarios

* consultar perfil;
* actualizar perfil;
* consultar estado de cuenta;
* consultar actividad;
* consultar plan;
* consultar relación ODESSA.

### 9.3 Familiares

* listar;
* crear;
* editar;
* eliminar;
* validar parentesco;
* validar edad;
* consultar número de usuario.

### 9.4 Pacientes frecuentes

* listar;
* crear;
* editar;
* eliminar;
* consultar historial relacionado.

### 9.5 Direcciones

* listar;
* crear;
* editar;
* eliminar;
* validar formato;
* consultar cobertura de servicios.

### 9.6 Laboratorios

* consultar marcas;
* buscar estudios;
* consultar alias;
* consultar descripción;
* consultar preparación;
* consultar precio;
* consultar descuento;
* consultar requisito de cita;
* consultar cobertura;
* consultar sucursales;
* consultar disponibilidad.

### 9.7 Carrito

* consultar carrito activo;
* crear carrito;
* agregar estudio;
* eliminar estudio;
* modificar estudio;
* asignar paciente;
* asignar dirección;
* asignar método de pago;
* detectar requisito de cita;
* calcular total.

### 9.8 Pagos

* listar métodos;
* consultar tarjeta tokenizada;
* eliminar tarjeta;
* iniciar alta segura de tarjeta;
* consultar cuenta ODESSA;
* consultar Ahorro a la Vista;
* validar saldo;
* validar elegibilidad;
* ejecutar cargo futuro;
* consultar estado de pago.

### 9.9 Pedidos

* listar pedidos;
* consultar detalle;
* consultar cronología;
* consultar vigencia;
* consultar elegibilidad de cancelación;
* consultar pedido histórico de Farmacia;
* exportar historial.

### 9.10 Resultados

* listar resultados;
* consultar disponibilidad;
* consultar estado;
* generar liga segura;
* generar OTP;
* validar OTP;
* descargar PDF;
* registrar apertura;
* registrar descarga.

### 9.11 Facturación

* consultar factura;
* consultar plazo;
* listar perfiles fiscales;
* crear perfil;
* editar perfil;
* eliminar perfil;
* cargar Constancia en entorno seguro;
* extraer datos;
* solicitar factura;
* consultar estado;
* generar liga;
* descargar;
* reenviar.

### 9.12 Promociones

* consultar promociones vigentes;
* validar elegibilidad;
* registrar exposición;
* registrar rechazo;
* registrar conversión;
* consultar carrito abandonado.

### 9.13 Soporte

* crear registro de escalamiento;
* transferir contexto;
* adjuntar error técnico;
* registrar horario;
* consultar canal disponible.

No debe presentarse al usuario como sistema de tickets mientras no exista formalmente.

## 10\. Contratos de respuesta mínimos

### 10.1 Consulta exitosa

Toda API de consulta debería devolver:

* `success`;
* `timestamp`;
* `data`;
* `source\_version`;
* `request\_id`.

### 10.2 Modificación exitosa

Debe devolver:

* `success`;
* `operation\_id`;
* `affected\_resource`;
* `resource\_id`;
* `updated\_at`;
* `request\_id`.

### 10.3 Error

Debe devolver:

* `success: false`;
* `error\_code`;
* `user\_safe\_message`;
* `technical\_message`;
* `retryable`;
* `escalation\_required`;
* `request\_id`.

### 10.4 Información sensible

La respuesta debe excluir:

* contraseña;
* CVV;
* tarjeta completa;
* token utilizable;
* OTP;
* documento completo;
* resultado clínico completo en texto.

## 11\. Códigos de error funcionales sugeridos

|Código|Significado|Acción de Leo|
|-|-|-|
|AUTH\_REQUIRED|Requiere sesión|Solicitar autenticación|
|OTP\_REQUIRED|Requiere código|Iniciar verificación|
|SESSION\_EXPIRED|Sesión vencida|Reautenticar|
|ACCOUNT\_NOT\_FOUND|Cuenta no localizada|Registro o recuperación|
|DUPLICATE\_ACCOUNT|Cuenta duplicada|Recuperación|
|INVALID\_PHONE\_COUNTRY|Teléfono no permitido|Informar restricción +52|
|VALIDATION\_ERROR|Datos inválidos|Solicitar corrección|
|STUDY\_NOT\_FOUND|Estudio no localizado|Probar alias y canalizar|
|COVERAGE\_NOT\_FOUND|Sin cobertura|Informar resultado real|
|APPOINTMENT\_REQUIRED|Requiere cita|Explicar checkout|
|APPOINTMENT\_PENDING|Cita no cargada|No permitir pago|
|INSUFFICIENT\_SIGHT\_SAVINGS|Saldo insuficiente|Ofrecer otro método|
|ODESSA\_NOT\_LINKED|Cuenta no vinculada|Explicar vinculación|
|ORDER\_EXPIRED|Orden vencida|Informar y escalar si aplica|
|ORDER\_NOT\_CANCELLABLE|No elegible para cancelar|Explicar condiciones|
|RESULT\_NOT\_AVAILABLE|Resultado no disponible|Informar o escalar|
|RESULT\_LINK\_EXPIRED|Liga vencida|Generar nueva validación|
|INVOICE\_OUT\_OF\_PERIOD|Fuera de plazo|Informar y escalar|
|INVOICE\_PENDING|Factura pendiente|Escalar|
|PAYMENT\_FAILED|Pago fallido|No afirmar cargo exitoso|
|API\_TIMEOUT|Tiempo agotado|Reintentar según política|
|SERVICE\_UNAVAILABLE|Servicio no disponible|Informar y escalar|
|PHARMACY\_SUSPENDED|Farmacia suspendida|Informar suspensión|

## 12\. Reintentos

### Consultas

Puede permitirse un reintento automático cuando:

* el error sea temporal;
* no exista riesgo de duplicidad;
* la latencia sea aceptable.

### Modificaciones

No debe reintentarse automáticamente sin idempotencia.

### Pagos futuros

Nunca deben reintentarse sin:

* llave idempotente;
* consulta de estado;
* prevención de doble cargo.

## 13\. Seguridad por sensibilidad

|Nivel|Ejemplos|Tratamiento|
|-|-|-|
|Baja|Servicios, planes, precios públicos|Consulta pública|
|Media|Perfil básico, pedidos, pacientes|Sesión|
|Alta|Pagos, facturas, saldo, datos fiscales|Sesión y protección|
|Crítica|Resultados, OTP, tarjetas, documentos|Verificación y componente seguro|

## 14\. Datos que Leo puede mostrar

### Público

* servicios;
* precios públicos;
* cobertura;
* preparación;
* requisitos;
* planes.

### Con sesión

* nombre del paciente;
* folio;
* fecha;
* importe;
* estado;
* método de pago enmascarado;
* correo y teléfono enmascarados;
* plan activo.

### Con verificación adicional

* acceso a resultados;
* factura;
* perfiles fiscales;
* documentos protegidos.

## 15\. Datos que Leo no debe mostrar

* contraseña;
* OTP después de uso;
* CVV;
* tarjeta completa;
* token;
* credenciales bancarias;
* Constancia completa;
* identificación oficial;
* resultado clínico completo en conversación;
* información técnica interna;
* datos de terceros sin necesidad.

## 16\. Auditoría

Cada operación debe registrar:

* `conversation\_id`;
* `user\_id`;
* `channel`;
* `intent\_id`;
* `capability\_id`;
* `authentication\_level`;
* `confirmation\_timestamp`;
* `api\_request\_id`;
* `resource\_id`;
* `result`;
* `error\_code`;
* `escalation`;
* `created\_at`.

No debe almacenarse:

* contraseña;
* OTP;
* CVV;
* tarjeta completa;
* contenido clínico completo.

## 17\. Matriz de liberación propuesta

### Fase 1. Informativa y consultas públicas

* información general;
* Plan Básico;
* búsqueda de estudios;
* precios;
* cobertura;
* preparación;
* citas;
* Farmacia suspendida;
* B2B general;
* privacidad.

### Fase 2. Cuenta y consultas personales

* detección de usuario;
* autenticación;
* perfil;
* plan activo;
* pedidos;
* pacientes;
* familiares;
* direcciones;
* métodos de pago visibles.

### Fase 3. Información sensible

* disponibilidad de resultados;
* ligas seguras;
* descarga PDF;
* facturas;
* perfiles fiscales;
* OTP;
* auditoría avanzada.

### Fase 4. Transacciones de bajo riesgo

* agregar familiares;
* agregar pacientes;
* direcciones;
* carrito;
* eliminar métodos;
* solicitar factura.

### Fase 5. Transacciones de mayor riesgo

* checkout;
* pago;
* contratación;
* altas de tarjeta;
* cancelaciones automatizadas.

La Fase 5 queda fuera del alcance inicial.

## 18\. Criterios de liberación

Una capacidad solo podrá liberarse cuando cumpla:

* API estable;
* contrato documentado;
* autenticación validada;
* autorización validada;
* idempotencia;
* auditoría;
* mensajes de error;
* pruebas funcionales;
* pruebas de seguridad;
* pruebas de privacidad;
* fallback;
* escalamiento;
* aprobación de producto;
* aprobación operativa;
* aprobación jurídica cuando corresponda.

## 19\. Pruebas mínimas por capacidad

Cada capacidad deberá probar:

* caso exitoso;
* dato faltante;
* dato inválido;
* usuario no autenticado;
* sesión vencida;
* falta de permiso;
* error de API;
* timeout;
* respuesta inconsistente;
* reintento;
* operación duplicada;
* escalamiento;
* protección de datos;
* respuesta conversacional.

## 20\. Pendientes técnicos

* endpoints definitivos;
* ambientes;
* autenticación entre servicios;
* formato de tokens;
* expiración de sesión;
* tiempo de OTP;
* intentos permitidos;
* idempotencia;
* webhooks;
* colas;
* logs;
* monitoreo;
* trazabilidad;
* políticas de caché;
* versionado;
* rate limiting;
* circuit breaker;
* auditoría.

## 21\. Pendientes funcionales

* alcance inicial de registro;
* contratación del Plan Básico;
* visualización de saldo;
* alta de tarjeta;
* checkout;
* pago;
* cancelación;
* registro de interés en Farmacia;
* creación de leads B2B;
* comunicación proactiva;
* carrito abandonado;
* exportación de historial.

## 22\. Pendientes de seguridad y jurídico

* consentimiento de primer uso;
* memoria conversacional;
* política de retención;
* WhatsApp;
* terceros;
* menores;
* seguridad de enlaces;
* protección de OTP;
* evidencia de consentimiento;
* PayPal;
* automatización;
* perfilamiento comercial;
* eliminación de cuenta;
* derechos ARCO.

## 23\. Prioridad recomendada de implementación

1. autenticación y sesión;
2. consulta de usuario;
3. catálogo de laboratorios;
4. precios y cobertura;
5. pedidos;
6. resultados;
7. facturas;
8. familiares y pacientes;
9. direcciones;
10. carrito;
11. métodos de pago;
12. promociones;
13. soporte;
14. transacciones avanzadas.

## 24\. Resultado esperado

Esta matriz debe permitir que el equipo técnico responda, para cada función:

* ¿Leo puede hacerlo?
* ¿Está liberado?
* ¿Qué API necesita?
* ¿Qué permiso requiere?
* ¿Qué datos puede mostrar?
* ¿Necesita confirmación?
* ¿Necesita OTP?
* ¿Qué sucede si falla?
* ¿Cuándo debe escalar?
* ¿Qué debe registrarse?

## Apéndice IA. Etiquetas de recuperación

```yaml
retrieval\_tags:
  - capacidades
  - permisos
  - autenticacion
  - verificacion
  - componentes\_seguros
  - APIs
  - idempotencia
  - trazabilidad
  - registro
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
  - privacidad
  - codigos\_error
  - reintentos
  - seguridad
  - auditoria
  - liberacion
  - pruebas
  - pendientes
```

