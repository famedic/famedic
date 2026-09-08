\---

document\_type: security\_privacy\_permissions\_matrix
document\_name: Matriz de Seguridad, Privacidad y Permisos de Leo
version: "0.1"
status: working\_draft
language: es-MX
assistant\_identity: "Leo, tu guía virtual FAMEDIC"
security\_levels:
S0: public\_information
S1: authenticated\_account
S2: additional\_verification
S3: secure\_component
S4: controlled\_human\_intervention
global\_rules:

* Aplicar mínimo privilegio
* Verificar titularidad del recurso
* No confiar solo en el canal
* No capturar datos sensibles en texto libre
* Fallar de forma segura
* Registrar trazabilidad sin secretos
* No usar conversaciones para entrenamiento en esta etapa

\---

# Matriz de Seguridad, Privacidad y Permisos de Leo

**Versión de trabajo 0.1**

## 1\. Propósito

Este documento define los controles de seguridad, privacidad, autenticación, autorización y manejo de datos aplicables a Leo y a las funciones de FAMEDIC relacionadas con:

* usuarios;
* familiares;
* pacientes frecuentes;
* pedidos;
* resultados;
* facturación;
* pagos;
* perfiles fiscales;
* planes médicos;
* laboratorios;
* atención humana;
* memoria conversacional;
* APIs;
* auditoría.

Su objetivo es asegurar que Leo:

* solo acceda a la información necesaria;
* no muestre datos sin autorización;
* no recopile datos sensibles en texto abierto;
* distinga entre identificación, autenticación y autorización;
* solicite verificación adicional cuando corresponda;
* utilice componentes seguros;
* mantenga trazabilidad;
* reduzca riesgos de fraude, exposición o acceso indebido;
* cumpla con las reglas jurídicas y operativas aprobadas.

## 2\. Principios de seguridad

### SEG-001. Mínimo privilegio

Leo, los servicios y los usuarios internos solo deben contar con los permisos indispensables para realizar su función.

No debe otorgarse acceso global por conveniencia técnica.

### SEG-002. Necesidad de conocer

La información solo debe mostrarse o transferirse cuando sea necesaria para resolver la intención del usuario.

Ejemplo:

Para consultar el estado de un pedido puede mostrarse:

* folio;
* paciente;
* fecha;
* importe;
* estado.

No es necesario mostrar:

* tarjeta completa;
* datos fiscales;
* resultados;
* documentos personales.

### SEG-003. Separación de funciones

Deben separarse, cuando sea posible:

* consulta;
* modificación;
* autorización;
* aprobación;
* pago;
* cancelación;
* auditoría;
* soporte;
* administración.

Una misma función técnica no debe permitir consultar, modificar y aprobar sin controles adicionales.

### SEG-004. Defensa en profundidad

La seguridad no debe depender de un solo control.

Debe combinar:

* autenticación;
* autorización;
* verificación adicional;
* enmascaramiento;
* componentes seguros;
* límites de sesión;
* registro de actividad;
* validaciones de API;
* monitoreo;
* escalamiento.

### SEG-005. No confiar solo en el canal

Recibir un mensaje desde un teléfono asociado no significa que la persona esté autenticada.

El teléfono de WhatsApp puede ayudar a detectar una cuenta, pero no autoriza por sí mismo a:

* consultar datos personales;
* ver pedidos;
* acceder a resultados;
* descargar facturas;
* modificar información;
* realizar acciones sensibles.

### SEG-006. Privacidad desde el diseño

Cada capacidad debe considerar desde su diseño:

* qué datos utiliza;
* por qué los necesita;
* quién puede verlos;
* cuánto tiempo se conservan;
* cómo se protegen;
* cómo se eliminan;
* qué se registra;
* qué no debe almacenarse.

### SEG-007. Falla segura

Si un control de seguridad falla o el sistema no puede verificar permisos:

* negar la acción;
* no mostrar datos;
* no asumir autorización;
* informar de manera segura;
* escalar cuando corresponda.

## 3\. Diferencia entre identificación, autenticación y autorización

|Concepto|Definición|Ejemplo|
|-|-|-|
|Identificación|Determinar qué cuenta podría corresponder al usuario|Detectar cuenta por teléfono|
|Autenticación|Confirmar que la persona controla las credenciales o factor requerido|Inicio de sesión|
|Verificación adicional|Solicitar un factor extra para una acción sensible|Código por SMS|
|Autorización|Validar que el usuario autenticado puede realizar la acción|Acceso a su resultado|
|Confirmación|Obtener consentimiento expreso antes de modificar|“Sí, confirmo”|
|Auditoría|Registrar qué ocurrió, cuándo y por quién|Bitácora de modificación|

Identificar una cuenta no equivale a autenticar.

Autenticar a una persona no implica que pueda acceder a cualquier recurso.

## 4\. Niveles de seguridad

### Nivel S0. Información pública

No requiere sesión.

Incluye:

* información institucional;
* servicios;
* Plan Básico;
* requisitos;
* precios públicos;
* cobertura;
* preparación;
* sucursales;
* promociones públicas vigentes;
* estado general de Farmacia;
* servicios institucionales generales;
* privacidad general.

Riesgo principal:

* información desactualizada;
* contenido no vigente;
* promesas incorrectas.

Control principal:

* fuente vigente;
* API o configuración actual;
* Base Maestra aprobada.

### Nivel S1. Cuenta autenticada

Requiere sesión válida.

Incluye:

* perfil;
* familiares;
* pacientes;
* direcciones;
* pedidos;
* plan activo;
* carrito;
* métodos de pago enmascarados;
* historial;
* referidos.

Riesgo principal:

* acceso indebido a información personal.

Controles:

* sesión;
* autorización por recurso;
* enmascaramiento;
* validación de propietario;
* auditoría.

### Nivel S2. Verificación adicional

Requiere sesión válida y un segundo factor o verificación equivalente.

Incluye:

* resultados;
* facturas;
* perfiles fiscales;
* documentos sensibles;
* cambios de alto riesgo;
* eliminación de cuenta;
* acciones con posible impacto económico o legal.

Control principal:

* código por SMS;
* liga segura;
* verificación contextual;
* auditoría reforzada.

### Nivel S3. Componente seguro

La captura ocurre fuera del texto libre de la conversación.

Incluye:

* contraseña;
* OTP;
* tarjeta;
* CVV;
* Constancia de Situación Fiscal;
* documentos de identidad;
* información bancaria;
* enlaces de recuperación.

Leo puede iniciar el componente, pero no debe recibir ni repetir el dato.

### Nivel S4. Intervención humana controlada

Aplica a:

* fraude;
* acceso no autorizado;
* cancelaciones;
* reembolsos vencidos;
* discrepancias;
* privacidad;
* resultados incorrectos;
* eliminación de cuenta;
* solicitudes ARCO;
* excepciones operativas.

Requiere:

* contexto mínimo;
* datos protegidos;
* trazabilidad;
* personal autorizado;
* registro de acceso.

## 5\. Roles principales

|Rol|Descripción|
|-|-|
|Usuario público|Persona sin sesión|
|Usuario autenticado|Titular con sesión válida|
|Beneficiario o familiar|Persona cubierta, administrada por titular según reglas|
|Paciente frecuente|Persona registrada para estudios, sin ser necesariamente familiar|
|Leo|Guía virtual con permisos limitados|
|Atención|Personal que recibe escalamientos|
|Operaciones|Personal que gestiona pedidos, citas, reembolsos y excepciones|
|Facturación|Personal autorizado para datos fiscales y CFDI|
|Seguridad|Personal responsable de incidentes y accesos indebidos|
|Jurídico y privacidad|Personal responsable de ARCO, consentimiento y cumplimiento|
|Tecnología|Personal técnico con acceso restringido y auditado|
|Administrador|Rol excepcional con privilegios elevados y controles reforzados|
|Auditor|Rol de solo lectura para revisión autorizada|

## 6\. Principio de titularidad del recurso

Cada consulta o acción debe validar que el recurso corresponde al usuario autenticado.

Aplica a:

* perfil;
* familiar;
* paciente;
* dirección;
* carrito;
* pedido;
* resultado;
* factura;
* perfil fiscal;
* método de pago;
* cuenta ODESSA;
* enlace de referido.

No basta con conocer un folio o identificador.

## 7\. Matriz general de permisos de Leo

|Dominio|Leer|Crear|Editar|Eliminar|Verificación adicional|Estado|
|-|-:|-:|-:|-:|-:|-|
|Información pública|Sí|No|No|No|No|Permitido|
|Perfil|Sí|No|Sí|No|Según campo|Objetivo|
|Familiares|Sí|Sí|Sí|Sí|No|Objetivo|
|Pacientes|Sí|Sí|Sí|Sí|No|Objetivo|
|Direcciones|Sí|Sí|Sí|Sí|No|Objetivo|
|Plan activo|Sí|No|No|No|No|Objetivo|
|Laboratorios|Sí|No|No|No|No|Objetivo|
|Carrito|Sí|Sí|Sí|Sí|No|Objetivo|
|Checkout|Parcial|No inicial|No|No|Sí|Fuera de alcance inicial|
|Métodos de pago|Sí, enmascarado|Mediante componente|No|Sí|Según acción|Parcial|
|Pago|No inicial|No inicial|No|No|Sí|Fuera de alcance inicial|
|Pedidos|Sí|No|No|No|No|Objetivo|
|Cancelación|Consultar condiciones|No|No|No|Sí|Escalamiento|
|Resultados|Sí|No|No|No|Sí|Objetivo|
|Facturas|Sí|Sí|No directo|No directo|Sí|Objetivo|
|Perfiles fiscales|Sí|Sí|Sí|Sí|Sí|Objetivo|
|Farmacia nueva|No|No|No|No|No|Suspendida|
|Farmacia histórica|Sí|No|No|No|No|Objetivo|
|Referidos|Sí|No|No|No|No|Objetivo|
|Solicitudes ARCO|Información general|No|No|No|Sí|Escalamiento|
|Eliminación de cuenta|No|No|No|No|Sí|Escalamiento|

## 8\. Permisos por tipo de dato

### 8.1 Datos públicos

Leo puede mostrar:

* nombre de servicio;
* descripción;
* precio público;
* descuento vigente;
* cobertura;
* preparación;
* sucursal;
* requisito de cita;
* beneficios de plan;
* horarios públicos;
* estado general de servicios.

Fuente:

* API;
* configuración;
* documento aprobado.

### 8.2 Datos personales básicos

Incluyen:

* nombre;
* apellidos;
* teléfono;
* correo;
* fecha de nacimiento;
* sexo;
* dirección.

Requieren:

* sesión válida;
* titularidad;
* enmascaramiento cuando corresponda;
* confirmación para modificaciones.

### 8.3 Datos financieros

Incluyen:

* método de pago;
* marca de tarjeta;
* últimos cuatro dígitos;
* saldo Ahorro a la Vista;
* importe;
* reembolso;
* estado de cargo.

Controles:

* sesión;
* enmascaramiento;
* componente seguro para alta;
* no guardar CVV;
* no mostrar tarjeta completa;
* auditoría;
* verificación adicional según riesgo.

### 8.4 Datos fiscales

Incluyen:

* RFC;
* razón social;
* régimen;
* código postal fiscal;
* uso de CFDI;
* Constancia de Situación Fiscal;
* factura.

Controles:

* verificación adicional;
* componente seguro;
* acceso restringido;
* enmascaramiento;
* retención según política;
* trazabilidad reforzada.

### 8.5 Datos de salud

Incluyen:

* estudios;
* resultados;
* documentos clínicos;
* historial relacionado;
* paciente;
* preparación específica;
* fecha de estudio.

Controles:

* sesión;
* verificación adicional para resultados;
* liga segura;
* código SMS;
* acceso limitado;
* no interpretación;
* no exposición en texto abierto;
* auditoría.

### 8.6 Datos de terceros

Incluyen información de:

* familiares;
* pacientes;
* beneficiarios;
* menores;
* empresa;
* contacto institucional.

Reglas:

* usar solo lo necesario;
* validar relación o autorización;
* limitar visibilidad;
* no exponer datos de terceros;
* no conservar en memoria más allá de la necesidad;
* escalar cuando exista disputa de autorización.

## 9\. Datos permitidos, enmascarados y prohibidos

|Dato|Leo puede recibir en chat|Leo puede mostrar|Tratamiento|
|-|-:|-:|-|
|Nombre|Sí|Sí|Con sesión cuando sea personal|
|Teléfono|Sí|Parcial|Enmascarar|
|Correo|Sí|Parcial|Enmascarar|
|Fecha de nacimiento|Sí|Sí, con sesión|Evitar exposición innecesaria|
|Dirección|Sí|Sí, con sesión|Solo titular|
|Folio|Sí|Sí|Con sesión|
|Importe|Sí|Sí|Con sesión|
|Estado de pedido|Sí|Sí|Con sesión|
|Marca de tarjeta|No necesaria|Sí|Enmascarada|
|Últimos 4 dígitos|No necesaria|Sí|Permitido|
|Tarjeta completa|No|No|Componente seguro|
|CVV|No|No|Nunca almacenar|
|Contraseña|No|No|Componente seguro|
|OTP|No en texto libre|No|Componente seguro|
|RFC|Sí, protegido|Parcial|Verificación adicional|
|Constancia fiscal|No|No|Carga segura|
|Resultado PDF|No|No directo|Liga segura|
|Valores clínicos|No recomendado|No|No exponer|
|Token|No|No|Interno|
|Credenciales bancarias|No|No|Prohibido|
|Identificación oficial|No|No|Componente seguro si se autoriza|

## 10\. Enmascaramiento

### 10.1 Teléfono

Formato sugerido:

`\*\*\* \*\*\* 4821`

### 10.2 Correo

Formato sugerido:

`m\*\*\*@dominio.com`

### 10.3 Tarjeta

Formato sugerido:

`Visa terminada en 4821`

### 10.4 RFC

Formato sugerido:

`ABC\*\*\*123`

### 10.5 Cuenta ODESSA

Mostrar solo identificador parcial o estado de vinculación.

### 10.6 Pedidos y resultados

Mostrar únicamente los datos suficientes para que el usuario identifique el recurso.

## 11\. Sesión

### 11.1 Requisitos

La sesión debe:

* tener duración definida;
* expirar por inactividad;
* invalidarse al cerrar sesión;
* registrar canal y dispositivo cuando sea posible;
* limitar reutilización;
* detectar anomalías;
* requerir reautenticación para acciones sensibles.

### 11.2 Expiración

Cuando expire:

* detener consultas personales;
* ocultar datos;
* solicitar reautenticación;
* conservar únicamente contexto no sensible;
* no completar modificaciones pendientes.

### 11.3 Sesiones múltiples

Debe definirse:

* si se permiten;
* cómo se notifican;
* cómo se revocan;
* cómo se detectan accesos inusuales;
* si se requiere cierre global.

### 11.4 Cambio de canal

Pasar de portal a WhatsApp o viceversa no debe transferir automáticamente una sesión sin validación.

## 12\. Código de verificación

### 12.1 Canal

El OTP se enviará por SMS.

No se enviará por WhatsApp.

### 12.2 Uso

Aplica a:

* resultados;
* facturas;
* perfiles fiscales;
* documentos sensibles;
* otras acciones aprobadas.

### 12.3 Reglas

Debe:

* tener vigencia limitada;
* tener número máximo de intentos;
* invalidarse después de uso;
* estar asociado a una sesión;
* estar asociado a una acción;
* no reutilizarse;
* no almacenarse en logs;
* no mostrarse después de validado.

### 12.4 Bloqueo

Después de intentos fallidos:

* suspender temporalmente;
* informar de manera segura;
* evitar indicar cuál parte falló;
* escalar cuando exista riesgo.

## 13\. Ligas seguras

### 13.1 Aplicaciones

* resultados;
* facturas;
* recuperación de contraseña;
* documentos sensibles.

### 13.2 Requisitos

La liga debe:

* ser aleatoria;
* tener vigencia limitada;
* expirar después del plazo;
* asociarse al usuario y recurso;
* permitir revocación;
* registrar apertura;
* registrar descarga;
* no exponer identificadores sensibles;
* usar conexión segura.

### 13.3 Vigencia de resultados y facturas

Vigencia objetivo:

> Una hora.

### 13.4 Reenvío

Si la liga expira:

* generar una nueva validación;
* no reactivar la liga anterior;
* registrar el nuevo intento.

## 14\. Confirmación de acciones

### 14.1 Acciones que requieren confirmación

* registro;
* edición de perfil;
* alta, edición o eliminación de familiares;
* alta, edición o eliminación de pacientes;
* alta, edición o eliminación de direcciones;
* alta o eliminación de método de pago;
* cambios de carrito;
* solicitud de factura;
* cambios fiscales;
* registro de interés;
* otras modificaciones.

### 14.2 Confirmación válida

Debe ser:

* expresa;
* cercana temporalmente a la acción;
* específica;
* asociada a un resumen;
* registrada.

### 14.3 Confirmación inválida

No es válida:

* el silencio;
* una respuesta ambigua;
* un “sí” que corresponde a otra pregunta;
* una confirmación anterior para una acción distinta;
* una confirmación recibida después de cambiar los datos.

## 15\. Autorización por recurso

Antes de consultar o modificar, el sistema debe validar:

* que el recurso existe;
* que pertenece al usuario;
* que el usuario tiene permiso;
* que el estado permite la acción;
* que no existe bloqueo;
* que la sesión es válida;
* que la verificación adicional está vigente si aplica.

Ejemplo:

Conocer un `result\_id` no autoriza a abrir el resultado.

## 16\. Familiares y pacientes

### 16.1 Familiares

El titular puede administrar familiares conforme a las reglas del plan.

Debe validarse:

* parentesco;
* edad;
* grupo familiar;
* titularidad;
* elegibilidad.

### 16.2 Pacientes frecuentes

Pueden no ser familiares.

Debe limitarse el acceso a:

* datos necesarios para la compra;
* pedidos asociados;
* resultados autorizados;
* facturas relacionadas.

### 16.3 Menores

Pendiente de definición jurídica y operativa:

* consentimiento;
* representación;
* acceso a resultados;
* quién puede descargar;
* tratamiento de datos;
* retención;
* ejercicio de derechos.

Hasta contar con reglas aprobadas, los casos ambiguos deben escalarse.

## 17\. Resultados

### 17.1 Acceso

Requiere:

* sesión válida;
* titularidad;
* verificación adicional;
* liga segura;
* OTP por SMS.

### 17.2 Prohibiciones

Leo no debe:

* enviar el PDF en WhatsApp;
* copiar el contenido;
* mostrar valores clínicos completos;
* interpretar;
* resumir hallazgos;
* emitir recomendaciones.

### 17.3 Incidente de privacidad

Si un resultado corresponde a otra persona:

* detener acceso;
* revocar liga;
* registrar incidente;
* escalar con prioridad crítica;
* no compartir el documento;
* preservar evidencia técnica.

## 18\. Facturación y perfiles fiscales

### 18.1 Acceso

Requiere:

* sesión;
* verificación adicional;
* titularidad;
* perfil fiscal autorizado.

### 18.2 Constancia fiscal

Debe cargarse mediante componente seguro.

Leo no debe:

* recibirla en chat;
* copiarla;
* almacenarla en memoria conversacional;
* reenviarla.

### 18.3 Edición

Los cambios deben:

* mostrar resumen;
* solicitar confirmación;
* registrar versión anterior y nueva;
* conservar trazabilidad.

## 19\. Pagos

### 19.1 Tarjetas

La plataforma debe usar tokenización.

Leo solo puede mostrar:

* marca;
* últimos cuatro dígitos;
* estado.

No debe mostrar:

* número completo;
* CVV;
* fecha completa si no es necesaria;
* token.

### 19.2 Alta de tarjeta

Debe realizarse en un componente seguro del proveedor de pagos.

Leo puede iniciar el flujo, pero no capturar los datos.

### 19.3 ODESSA

Debe validarse:

* vinculación;
* producto Ahorro a la Vista;
* saldo disponible;
* elegibilidad;
* titularidad.

No deben mostrarse:

* credenciales;
* fondos en otros plazos como saldo disponible;
* información bancaria innecesaria.

### 19.4 Pagos futuros por Leo

Antes de habilitarse requieren:

* autorización reforzada;
* idempotencia;
* prevención de doble cargo;
* consulta de estado;
* monitoreo de fraude;
* confirmación explícita;
* auditoría financiera;
* aprobación jurídica.

## 20\. Memoria conversacional

### 20.1 Uso permitido

Puede conservarse contexto para:

* continuidad;
* personalización;
* seguimiento;
* auditoría;
* reducción de repeticiones.

### 20.2 Datos que no deben conservarse

* contraseña;
* OTP;
* tarjeta completa;
* CVV;
* tokens;
* credenciales bancarias;
* resultado clínico completo;
* Constancia fiscal completa;
* identificación oficial;
* documentos sensibles.

### 20.3 Memoria de datos personales

Debe definirse:

* finalidad;
* plazo;
* base jurídica;
* consentimiento;
* acceso;
* rectificación;
* eliminación;
* exportación;
* uso para personalización;
* exclusión de entrenamiento.

### 20.4 Entrenamiento

En esta etapa, las conversaciones no deben utilizarse para entrenamiento de modelos.

Cualquier cambio requerirá:

* análisis jurídico;
* consentimiento;
* actualización de documentos;
* controles de anonimización;
* aprobación formal.

## 21\. Integración con WhatsApp

### 21.1 Riesgos

* dispositivo compartido;
* sesión abierta;
* número reciclado;
* acceso de terceros;
* capturas;
* reenvío;
* pérdida del teléfono.

### 21.2 Controles

* no asumir identidad por número;
* solicitar autenticación;
* usar ligas seguras;
* OTP por SMS;
* mensajes enmascarados;
* advertencias cuando corresponda;
* cierre de sesión;
* revocación.

### 21.3 Advertencia pendiente

Debe definirse un mensaje para recordar al usuario que proteja el acceso a su dispositivo.

## 22\. Escalamiento y transferencia humana

### 22.1 Datos permitidos en la transferencia

* identificador protegido;
* motivo;
* módulo;
* pedido o recurso;
* prioridad;
* autenticación;
* acciones realizadas;
* códigos de error;
* request ID;
* resumen.

### 22.2 Datos excluidos

* contraseña;
* OTP;
* CVV;
* tarjeta completa;
* token;
* documentos completos;
* resultados completos;
* credenciales bancarias.

### 22.3 Acceso del personal

El personal debe acceder según:

* rol;
* necesidad;
* módulo;
* nivel de sensibilidad;
* horario;
* autorización;
* registro de actividad.

## 23\. Matriz de roles y permisos internos

|Recurso|Atención|Operaciones|Facturación|Seguridad|Jurídico|Tecnología|
|-|-:|-:|-:|-:|-:|-:|
|Perfil básico|Lectura limitada|Lectura|No|Según incidente|Según solicitud|Acceso técnico restringido|
|Familiares|Lectura limitada|Gestión autorizada|No|No|Según caso|Técnico restringido|
|Pacientes|Lectura limitada|Gestión autorizada|No|Según incidente|Según caso|Técnico restringido|
|Pedidos|Lectura|Gestión|Lectura limitada|Según incidente|Según caso|Técnico restringido|
|Pagos|Estado enmascarado|Estado|No|Incidentes|Según disputa|Técnico restringido|
|Resultados|Estado, no contenido|Estado|No|Incidentes|Según solicitud|Técnico restringido|
|Facturas|Estado|Lectura limitada|Gestión|Incidentes|Según caso|Técnico restringido|
|Perfiles fiscales|No|No|Gestión|Incidentes|Según solicitud|Técnico restringido|
|Conversaciones|Según caso|Según caso|No|Incidentes|Según solicitud|Técnico restringido|
|Logs|No|No|No|Sí|Según solicitud|Sí, restringido|

Los permisos definitivos deben configurarse por rol y ambiente.

## 24\. Ambientes

### Producción

* datos reales;
* acceso restringido;
* auditoría completa;
* controles de cambio;
* monitoreo;
* aprobación previa.

### QA

* preferentemente datos ficticios;
* sin cargos reales;
* acceso limitado;
* no reutilizar datos de producción salvo proceso autorizado;
* no usar capturas como fuente vigente.

### Desarrollo

* sin datos reales;
* servicios simulados;
* secretos protegidos;
* acceso técnico limitado.

## 25\. Logs y auditoría

### 25.1 Registrar

* conversación;
* usuario protegido;
* canal;
* intención;
* capacidad;
* autenticación;
* autorización;
* confirmación;
* endpoint;
* request ID;
* recurso;
* resultado;
* error;
* escalamiento;
* fecha;
* responsable.

### 25.2 No registrar

* contraseña;
* OTP;
* CVV;
* tarjeta completa;
* token utilizable;
* documentos completos;
* resultado clínico completo;
* credenciales bancarias.

### 25.3 Acceso a logs

Debe estar limitado a:

* tecnología autorizada;
* seguridad;
* auditoría;
* jurídico, cuando proceda.

## 26\. Retención y eliminación

Debe definirse por categoría:

* conversaciones;
* logs;
* resultados;
* facturas;
* perfiles fiscales;
* pedidos;
* consentimiento;
* grabaciones;
* documentos;
* escalamientos.

La política debe especificar:

* plazo;
* justificación;
* responsable;
* mecanismo de eliminación;
* bloqueo;
* conservación legal;
* evidencia de destrucción.

## 27\. Consentimiento

### 27.1 Primer uso de Leo

Pendiente definir:

* mensaje;
* aceptación;
* versión;
* fecha;
* canal;
* evidencia;
* rechazo;
* alternativas.

### 27.2 Consentimientos específicos

Pueden requerirse para:

* memoria conversacional;
* comunicación proactiva;
* datos de terceros;
* menores;
* perfilamiento comercial;
* notificaciones;
* reactivación de Farmacia;
* uso de WhatsApp.

## 28\. Incidentes de seguridad

### 28.1 Tipos

* acceso no autorizado;
* cuenta no reconocida;
* datos expuestos;
* resultado equivocado;
* factura de tercero;
* cargo desconocido;
* credencial comprometida;
* enlace compartido;
* error de autorización;
* fuga en logs;
* documento cargado en canal incorrecto.

### 28.2 Respuesta

Ante incidente:

1. detener la acción;
2. limitar exposición;
3. revocar acceso;
4. preservar evidencia;
5. registrar;
6. escalar;
7. notificar según protocolo;
8. corregir;
9. cerrar;
10. documentar aprendizaje.

## 29\. Códigos de seguridad sugeridos

|Código|Significado|Acción|
|-|-|-|
|SECURITY\_AUTH\_REQUIRED|Falta autenticación|Solicitar inicio de sesión|
|SECURITY\_OTP\_REQUIRED|Requiere verificación adicional|Iniciar OTP|
|SECURITY\_FORBIDDEN|No tiene permiso|Negar y no mostrar datos|
|SECURITY\_RESOURCE\_MISMATCH|Recurso no corresponde al usuario|Detener y escalar|
|SECURITY\_SESSION\_EXPIRED|Sesión vencida|Reautenticar|
|SECURITY\_ACCOUNT\_LOCKED|Cuenta bloqueada|Recuperación o atención|
|SECURITY\_TOO\_MANY\_ATTEMPTS|Exceso de intentos|Bloqueo temporal|
|SECURITY\_LINK\_EXPIRED|Liga vencida|Generar nuevo acceso|
|SECURITY\_LINK\_REVOKED|Liga revocada|No permitir acceso|
|SECURITY\_SENSITIVE\_DATA\_IN\_CHAT|Dato sensible detectado|Ocultar, no almacenar y orientar|
|SECURITY\_PRIVACY\_INCIDENT|Posible exposición|Escalar P0|
|SECURITY\_UNAUTHORIZED\_ACTIVITY|Actividad no reconocida|Escalar P0|
|SECURITY\_CONSENT\_REQUIRED|Falta consentimiento|Solicitar aceptación|
|SECURITY\_MINOR\_REVIEW|Caso de menor no definido|Escalar|

## 30\. Pruebas mínimas de seguridad

Cada capacidad debe probar:

* usuario no autenticado;
* sesión vencida;
* recurso de otro usuario;
* rol sin permiso;
* OTP inválido;
* OTP vencido;
* exceso de intentos;
* liga vencida;
* liga revocada;
* URL manipulada;
* identificador modificado;
* acción sin confirmación;
* reintento duplicado;
* datos sensibles en chat;
* datos sensibles en logs;
* respuesta de API con datos excesivos;
* acceso desde canal distinto;
* incidente de tercero;
* revocación de sesión.

## 31\. Criterios de liberación de seguridad

Una función no debe liberarse hasta contar con:

* autenticación;
* autorización;
* validación de titularidad;
* enmascaramiento;
* minimización;
* trazabilidad;
* manejo de errores;
* bloqueo seguro;
* idempotencia si modifica;
* pruebas de seguridad;
* revisión de privacidad;
* revisión jurídica;
* fallback;
* escalamiento;
* monitoreo;
* responsable.

## 32\. Indicadores sugeridos

### Seguridad

* intentos fallidos;
* cuentas bloqueadas;
* accesos no autorizados;
* recursos ajenos solicitados;
* ligas vencidas;
* OTP fallidos;
* incidentes de privacidad;
* datos sensibles detectados en chat;
* datos sensibles detectados en logs.

### Operación

* sesiones expiradas;
* reautenticaciones;
* abandonos por verificación;
* escalamiento por autorización;
* errores por rol;
* tiempos de recuperación.

### Cumplimiento

* consentimientos vigentes;
* solicitudes ARCO;
* eliminaciones;
* retención vencida;
* accesos internos;
* revisiones de privilegios.

## 33\. Revisión periódica de accesos

Debe realizarse revisión periódica de:

* usuarios internos;
* roles;
* privilegios;
* accesos de terceros;
* cuentas inactivas;
* cuentas privilegiadas;
* tokens;
* llaves;
* ambientes;
* logs;
* excepciones.

## 34\. Pendientes técnicos

* duración de sesión;
* política de sesiones múltiples;
* reautenticación;
* vigencia de OTP;
* número de intentos;
* bloqueo;
* revocación de ligas;
* número de aperturas;
* monitoreo;
* alertas;
* gestión de secretos;
* rotación de llaves;
* controles por ambiente;
* anonimización;
* borrado;
* panel de auditoría;
* detección de datos sensibles.

## 35\. Pendientes operativos

* propietarios de datos;
* roles definitivos;
* matriz de accesos internos;
* proceso de alta y baja de personal;
* protocolo de incidentes;
* escalamiento P0;
* revisión periódica;
* manejo de dispositivos compartidos;
* verificación de titulares;
* tratamiento de menores;
* autorización de familiares;
* tratamiento de pacientes frecuentes.

## 36\. Pendientes jurídicos

* consentimiento de primer uso;
* memoria;
* WhatsApp;
* datos de terceros;
* menores;
* PayPal;
* ODESSA;
* retención;
* eliminación;
* ARCO;
* transferencias;
* encargados;
* automatización;
* perfilamiento;
* notificaciones;
* evidencia de aceptación;
* uso de conversaciones;
* enlaces seguros.

## 37\. Matriz resumida

|Situación|Control requerido|
|-|-|
|Información pública|Fuente vigente|
|Perfil|Sesión y titularidad|
|Modificación|Sesión, resumen y confirmación|
|Resultado|Sesión, OTP y liga segura|
|Factura|Sesión, verificación y liga segura|
|Perfil fiscal|Verificación y componente seguro|
|Tarjeta|Componente seguro y tokenización|
|ODESSA|Sesión, vinculación y saldo Ahorro a la Vista|
|Familiar|Sesión, elegibilidad y confirmación|
|Paciente|Sesión, relación y confirmación|
|Recurso ajeno|Negar y escalar|
|Sesión vencida|Reautenticar|
|OTP fallido|Limitar intentos|
|Liga vencida|Generar nuevo acceso|
|Dato sensible en chat|No almacenar y orientar|
|Acceso no autorizado|Detener y escalar P0|
|Incidente de privacidad|Revocar, registrar y escalar|
|Solicitud ARCO|Canalizar|
|Eliminación de cuenta|Verificar y escalar|
|Menor|Aplicar política aprobada o escalar|
|Pago futuro|Autorización reforzada e idempotencia|

## 38\. Resultado esperado

Esta matriz debe permitir responder:

* ¿qué nivel de seguridad aplica?;
* ¿el usuario está identificado, autenticado y autorizado?;
* ¿qué datos puede ver?;
* ¿qué datos deben enmascararse?;
* ¿qué datos deben capturarse en un componente seguro?;
* ¿se requiere OTP?;
* ¿se requiere confirmación?;
* ¿el recurso pertenece al usuario?;
* ¿qué debe registrarse?;
* ¿qué no debe almacenarse?;
* ¿qué rol interno puede acceder?;
* ¿qué sucede ante un incidente?;
* ¿qué pendiente impide liberar la función?

## Apéndice IA. Etiquetas de recuperación

```yaml
retrieval\_tags:
  - seguridad
  - privacidad
  - permisos
  - autenticacion
  - autorizacion
  - titularidad
  - confirmacion
  - minimo\_privilegio
  - niveles\_seguridad
  - roles
  - datos\_personales
  - datos\_financieros
  - datos\_fiscales
  - datos\_salud
  - datos\_terceros
  - enmascaramiento
  - sesion
  - otp
  - ligas\_seguras
  - resultados
  - facturacion
  - pagos
  - odessa
  - memoria\_conversacional
  - whatsapp
  - escalamiento
  - ambientes
  - logs
  - auditoria
  - retencion
  - consentimiento
  - incidentes
  - codigos\_seguridad
  - pruebas
  - liberacion
  - pendientes
```

