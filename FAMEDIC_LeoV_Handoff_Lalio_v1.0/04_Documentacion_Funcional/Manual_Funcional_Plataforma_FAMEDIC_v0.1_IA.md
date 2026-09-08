# Manual Funcional de la Plataforma FAMEDIC

**Versión de trabajo 0.1**

## 1\. Propósito

Este manual describe las funciones disponibles en la plataforma FAMEDIC desde la perspectiva del usuario.

Su objetivo es documentar:

* qué puede hacer cada tipo de usuario;
* qué datos se requieren;
* qué validaciones aplica la plataforma;
* qué funciones están activas;
* qué funciones están suspendidas;
* qué funciones están previstas para una etapa futura;
* qué acciones podrá apoyar Leo;
* cuándo debe intervenir atención humana.

Este documento no sustituye:

* la Base Maestra de Conocimiento;
* las Reglas de Negocio y Excepciones;
* la documentación técnica de APIs;
* los Términos y Condiciones;
* el Aviso de Privacidad;
* los procedimientos internos de soporte.

## 2\. Estados funcionales

Cada función debe identificarse con uno de los siguientes estados:

|Estado|Definición|
|-|-|
|Activa|Disponible actualmente para usuarios en producción|
|Activa con autenticación|Requiere sesión iniciada|
|Activa con verificación adicional|Requiere código u otra validación|
|Objetivo de Leo|Función prevista para ser operada o asistida por Leo|
|En pruebas|Disponible técnicamente, pero no liberada al público|
|Próximamente|Planeada, todavía no activa|
|Suspendida|Existía, pero actualmente no está disponible|
|Escalamiento|Requiere atención humana|
|Pendiente de definición|Falta decisión técnica, jurídica u operativa|

## 3\. Navegación pública

### 3.1 Acceso sin registro

Un visitante puede navegar por el portal sin crear una cuenta.

Puede:

* conocer FAMEDIC;
* revisar servicios;
* consultar información de planes;
* explorar estudios de laboratorio;
* revisar precios públicos;
* consultar descuentos;
* conocer beneficios;
* revisar información general de cobertura;
* conocer el proceso de registro;
* consultar información institucional general.

No puede:

* agregar estudios al carrito;
* iniciar una compra;
* administrar pacientes;
* administrar familiares;
* guardar direcciones;
* guardar métodos de pago;
* consultar pedidos;
* consultar resultados;
* consultar facturas.

**Estado:** Activa.

### 3.2 Navegación asistida por Leo

Leo podrá orientar al visitante sobre:

* servicios disponibles;
* diferencias entre cuenta y plan;
* requisitos de registro;
* Plan Básico;
* estudios de laboratorio;
* cobertura general;
* métodos de pago;
* servicios temporalmente no disponibles.

Cuando la información sea dinámica, Leo deberá consultarla por API.

**Estado:** Objetivo de Leo.

## 4\. Registro de usuario

### 4.1 Inicio del registro

El usuario puede iniciar su registro desde la plataforma.

Datos obligatorios:

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

Actualmente solo se aceptan teléfonos de México con prefijo `+52`.

**Estado:** Activa.

### 4.2 Validaciones

La plataforma debe validar:

* que el correo no esté registrado;
* que el teléfono no esté registrado;
* formato válido de correo;
* formato válido de teléfono;
* contraseña conforme a reglas técnicas;
* aceptación de documentos legales;
* integridad de campos obligatorios.

### 4.3 Verificación

El registro requiere:

* confirmación de correo electrónico;
* código enviado por SMS.

No se envían códigos de registro por WhatsApp.

### 4.4 Registro desde Leo

Leo podrá iniciar el proceso de registro mediante un formulario o componente seguro.

Leo no deberá solicitar la contraseña en texto abierto.

Antes de enviar el registro, deberá mostrar un resumen de los datos no sensibles y pedir confirmación.

**Estado:** Objetivo de Leo.

### 4.5 Cuenta existente

Si el correo o teléfono ya están registrados:

* no debe generarse una cuenta nueva;
* debe dirigirse al usuario al inicio de sesión;
* debe ofrecerse recuperación de acceso.

**Estado:** Activa.

## 5\. Inicio de sesión

### 5.1 Acceso

El usuario inicia sesión con sus credenciales.

La plataforma debe validar:

* identidad;
* contraseña;
* estado de la cuenta;
* vigencia de sesión.

**Estado:** Activa.

### 5.2 Inicio de sesión asistido por Leo

Leo podrá iniciar el proceso de autenticación.

Para operaciones sensibles podrá requerir código de verificación.

Leo no debe recibir la contraseña directamente en la conversación.

**Estado:** Objetivo de Leo.

### 5.3 Expiración de sesión

La sesión expira después de un periodo de inactividad.

Cuando expire:

* el usuario debe volver a autenticarse;
* las operaciones sensibles deben detenerse;
* no debe mostrarse información personal hasta recuperar la sesión.

**Estado:** Activa.

**Pendiente:** Definir duración exacta.

## 6\. Recuperación de acceso

### 6.1 Solicitud

El usuario puede solicitar recuperación de contraseña.

El sistema debe:

1. validar la cuenta;
2. enviar una liga segura;
3. permitir definir una nueva contraseña;
4. confirmar la actualización.

### 6.2 Recuperación mediante Leo

Leo podrá enviar la liga segura de recuperación.

No debe:

* pedir la contraseña anterior;
* pedir la nueva contraseña en el chat;
* generar una contraseña visible.

**Estado:** Objetivo de Leo.

## 7\. Perfil del usuario

### 7.1 Consulta

El usuario puede consultar:

* nombre;
* apellidos;
* fecha de nacimiento;
* sexo;
* correo;
* teléfono.

**Estado:** Activa con autenticación.

### 7.2 Edición

Puede modificar:

* nombre;
* apellido paterno;
* apellido materno;
* fecha de nacimiento;
* sexo;
* correo electrónico;
* teléfono celular;
* contraseña.

### 7.3 Edición mediante Leo

Leo podrá iniciar la modificación de datos.

Flujo:

1. identificar el dato;
2. validar formato;
3. mostrar resumen;
4. pedir confirmación;
5. ejecutar por API;
6. informar resultado.

**Estado:** Objetivo de Leo.

**Pendiente:** Definir si cambios de correo y teléfono requieren nueva verificación.

## 8\. Familiares

### 8.1 Consulta

El usuario puede consultar sus familiares registrados.

Puede visualizar:

* nombre;
* parentesco;
* fecha de nacimiento;
* sexo;
* número de usuario cuando aplique.

**Estado:** Activa con autenticación.

### 8.2 Alta

Datos obligatorios:

* nombre;
* apellido paterno;
* apellido materno;
* parentesco;
* fecha de nacimiento;
* sexo.

La plataforma debe validar:

* edad;
* compatibilidad del grupo familiar;
* campos obligatorios.

### 8.3 Reglas de grupo familiar

Se permite uno de los siguientes esquemas:

#### Esquema A

* titular;
* cónyuge;
* hijos menores de 24 años.

#### Esquema B

* titular soltero;
* padre;
* madre.

No se pueden mezclar.

### 8.4 Edición

El usuario puede editar los datos permitidos de un familiar.

### 8.5 Eliminación

El usuario puede eliminar un familiar.

La eliminación:

* lo retira de la administración activa;
* no borra historial;
* no elimina pedidos;
* no elimina resultados anteriores.

### 8.6 Gestión mediante Leo

Leo podrá:

* consultar;
* agregar;
* editar;
* eliminar familiares.

Toda modificación requiere confirmación expresa.

**Estado:** Objetivo de Leo.

## 9\. Pacientes frecuentes

### 9.1 Función

El usuario puede registrar personas para quienes realiza compras de laboratorio.

No es necesario que sean familiares.

### 9.2 Datos obligatorios

* nombre;
* apellido paterno;
* apellido materno;
* teléfono de contacto;
* fecha de nacimiento;
* sexo.

### 9.3 Acciones disponibles

* consultar;
* agregar;
* editar;
* eliminar.

Eliminar un paciente no borra:

* pedidos;
* resultados;
* facturas;
* movimientos históricos.

### 9.4 Gestión mediante Leo

Leo podrá realizar las acciones anteriores mediante API y confirmación expresa.

**Estado:** Objetivo de Leo.

## 10\. Direcciones

### 10.1 Datos

La plataforma permite registrar:

* calle;
* número;
* colonia;
* referencias adicionales;
* estado;
* ciudad o municipio;
* código postal.

### 10.2 Acciones

* consultar;
* agregar;
* editar;
* eliminar.

### 10.3 Cobertura

Guardar una dirección no confirma que exista cobertura.

La cobertura debe validarse al consultar un servicio o sucursal.

### 10.4 Gestión mediante Leo

Leo podrá administrar direcciones con confirmación previa.

**Estado:** Objetivo de Leo.

## 11\. Métodos de pago

### 11.1 Métodos disponibles

Pueden incluir:

* tarjeta de crédito;
* tarjeta de débito;
* PayPal;
* saldo disponible en Ahorro a la Vista de una cuenta ODESSA vinculada.

La disponibilidad debe confirmarse por API.

### 11.2 Tarjetas

La plataforma puede permitir:

* consultar tarjetas guardadas;
* agregar tarjeta;
* eliminar tarjeta.

Solo deben mostrarse:

* marca;
* últimos cuatro dígitos.

No deben mostrarse:

* número completo;
* CVV;
* credenciales bancarias.

### 11.3 PayPal

Puede seleccionarse cuando esté habilitado en el flujo correspondiente.

### 11.4 Ahorro a la Vista ODESSA

El usuario puede pagar únicamente con saldo disponible en **Ahorro a la Vista**.

No pueden utilizarse fondos en:

* ahorro a un mes;
* ahorro de diciembre;
* otros plazos;
* otros productos de ahorro.

Antes del cargo, la plataforma debe validar:

* cuenta vinculada;
* producto habilitado;
* saldo disponible;
* saldo suficiente.

### 11.5 Eliminación de tarjeta

Si el usuario elimina una tarjeta:

* se retira de inmediato;
* deja de estar disponible para el carrito;
* no debe poder seleccionarse nuevamente.

### 11.6 Gestión mediante Leo

Leo podrá:

* consultar métodos visibles;
* iniciar alta o eliminación;
* mostrar marca y últimos cuatro dígitos;
* pedir confirmación;
* utilizar componentes seguros.

Leo no debe recibir datos completos de tarjeta en conversación.

**Estado:** Objetivo de Leo.

### 11.7 Pago en sucursal

Está previsto para una etapa futura.

No debe mostrarse como método activo.

**Estado:** Próximamente.

## 12\. Planes médicos

### 12.1 Consulta pública

El usuario puede consultar información del Plan Básico.

Incluye:

* precio de $300 MXN;
* IVA incluido;
* pago único;
* vigencia de 12 meses;
* telemedicina ilimitada 24/7;
* orientación psicológica;
* orientación nutricional;
* orientación legal;
* cobertura familiar.

**Estado:** Activa.

### 12.2 Contratación individual

Solo puede contratarse directamente el Plan Básico.

Planes Intermedio y Completo:

* son institucionales;
* no deben mostrarse como opciones de contratación individual.

### 12.3 Plan patrocinado

Cuando la empresa registra al colaborador en el padrón:

* la plataforma puede reconocer la elegibilidad;
* el usuario se registra;
* se vincula el plan correspondiente.

### 12.4 Sustitución de plan individual

Si el usuario ya tiene Plan Básico y recibe un plan patrocinado:

* el patrocinado sustituye al individual;
* se calcula el periodo no utilizado;
* se devuelve la parte proporcional al método original.

### 12.5 Consulta mediante Leo

Leo podrá:

* explicar beneficios;
* consultar si existe plan activo;
* distinguir plan individual y patrocinado;
* orientar sobre uso del servicio.

**Estado:** Objetivo de Leo.

## 13\. Solicitud de atención médica

### 13.1 Acceso

El beneficiario debe llamar al número correspondiente a su plan.

### 13.2 Validación

El conmutador valida:

* número de usuario;
* identidad;
* plan;
* servicio solicitado.

### 13.3 Telemedicina

Cuando aplica:

* se genera una videollamada;
* se envía un enlace al beneficiario.

### 13.4 Orientaciones

La misma línea puede canalizar a:

* psicología;
* nutrición;
* orientación legal.

### 13.5 Emergencias

La plataforma y Leo no deben presentar telemedicina como sustituto de atención de urgencia.

## 14\. Laboratorios: consulta de catálogo

### 14.1 Selección de marca

El usuario debe seleccionar primero la marca o laboratorio.

### 14.2 Búsqueda de estudio

Después puede buscar por:

* nombre clínico;
* nombre común;
* alias;
* equivalencias configuradas.

### 14.3 Información mostrada

Puede incluir:

* nombre;
* descripción;
* preparación;
* precio;
* descuento;
* requerimiento de cita;
* cobertura;
* disponibilidad.

Toda esta información debe obtenerse por API.

### 14.4 Estudio no encontrado

Cuando no aparezca:

* no se debe inventar una equivalencia;
* debe ofrecerse ayuda por WhatsApp de atención.

### 14.5 Consulta mediante Leo

Leo podrá:

* buscar;
* filtrar;
* explicar preparación;
* indicar si requiere cita;
* cotizar;
* consultar cobertura;
* consultar sucursales.

**Estado:** Objetivo de Leo.

## 15\. Carrito de laboratorio

### 15.1 Requisito de registro

Un visitante puede consultar precios, pero debe registrarse para agregar estudios.

### 15.2 Acciones disponibles

* agregar estudio;
* eliminar estudio;
* modificar selección;
* consultar total;
* identificar estudios con cita.

### 15.3 Gestión mediante Leo

Leo podrá:

* agregar;
* eliminar;
* modificar estudios.

Toda modificación requiere:

1. resumen;
2. confirmación;
3. ejecución por API;
4. respuesta real.

**Estado:** Objetivo de Leo.

## 16\. Compra de estudios sin cita

### 16.1 Flujo

1. seleccionar marca;
2. seleccionar estudios;
3. agregar al carrito;
4. elegir paciente;
5. elegir dirección;
6. elegir método de pago;
7. revisar resumen;
8. confirmar;
9. pagar;
10. generar orden.

### 16.2 Sucursal

Cuando no se requiere cita, el usuario puede acudir a una sucursal disponible de la marca seleccionada.

Debe verificarse cobertura y disponibilidad.

### 16.3 Leo

Leo podrá preparar el carrito y orientar.

El checkout y pago no estarán habilitados para Leo en la primera etapa.

**Estado de Leo:** Fuera de alcance inicial.

## 17\. Compra de estudios con cita

### 17.1 Detección

El carrito identifica si uno o más estudios requieren cita.

### 17.2 Flujo

1. seleccionar paciente;
2. seleccionar dirección;
3. seleccionar método de pago;
4. iniciar checkout;
5. contactar concierge o solicitar llamada;
6. recibir opciones de sucursal, fecha y hora;
7. elegir una opción;
8. concierge registra la cita;
9. se habilita la confirmación;
10. el usuario confirma;
11. paga;
12. se genera la orden.

### 17.3 Restricciones

No debe:

* cobrarse antes de registrar la cita;
* generarse orden por teléfono;
* generarse cita independiente por teléfono;
* compartirse el número de concierge fuera del checkout.

### 17.4 Leo

Leo no debe proporcionar el teléfono de concierge si no existe un proceso de checkout activo.

**Estado:** Activa en plataforma; limitada para Leo.

## 18\. Pedidos

### 18.1 Consulta

El usuario puede consultar:

* paciente;
* estudios;
* marca;
* fecha;
* folio;
* importe;
* método de pago;
* estado;
* resultado;
* factura;
* cronología.

### 18.2 Pedido de laboratorio

Puede mostrar:

* estado de orden;
* estado de muestra;
* estado de resultado;
* instrucciones;
* vigencia.

### 18.3 Pedido histórico de Farmacia

Puede consultarse aunque el servicio esté suspendido.

### 18.4 Consulta mediante Leo

Leo podrá consultar pedidos por API.

Puede mostrar con sesión válida:

* nombre del paciente;
* fecha;
* folio;
* importe;
* estado.

**Estado:** Objetivo de Leo.

## 19\. Vigencia y cancelación de órdenes

### 19.1 Vigencia

Las órdenes pagadas de laboratorio tienen vigencia de 30 días naturales.

### 19.2 Condiciones de cancelación

Solo puede cancelarse cuando:

* el estudio no se realizó;
* no se facturó;
* no han transcurrido más de 30 días.

### 19.3 Solicitud

La cancelación debe canalizarse a atención humana.

Leo puede explicar las condiciones, pero no debe confirmar la cancelación.

**Estado:** Escalamiento.

### 19.4 Reembolso

Se realiza:

* al método de pago original;
* en hasta 15 días hábiles.

## 20\. Resultados de laboratorio

### 20.1 Carga de resultados

Cuando el laboratorio carga el resultado:

* se envía notificación por correo;
* se carga en FAMEDIC;
* puede descargarse en PDF.

### 20.2 Consulta desde la plataforma

El usuario puede ingresar a su cuenta y consultar resultados disponibles.

### 20.3 Consulta mediante Leo

Leo podrá:

1. verificar disponibilidad;
2. generar liga segura;
3. enviar código por SMS;
4. permitir apertura;
5. permitir descarga en PDF.

La liga tendrá vigencia de una hora.

### 20.4 Restricciones

Leo no debe:

* enviar el PDF como adjunto directo en WhatsApp;
* interpretar resultados;
* diagnosticar;
* recomendar tratamientos;
* explicar anomalías clínicas.

### 20.5 Incidencias

Debe escalarse cuando:

* el resultado no aparece;
* fue marcado como disponible, pero no abre;
* el PDF está incompleto;
* existe discrepancia entre laboratorio y plataforma.

**Estado:** Objetivo de Leo con verificación adicional.

## 21\. Facturación

### 21.1 Solicitud

La factura debe solicitarse dentro del mismo mes calendario de la compra.

### 21.2 Perfiles fiscales

El usuario puede:

* consultar;
* crear;
* editar;
* eliminar perfiles fiscales.

### 21.3 Constancia de Situación Fiscal

Puede cargarse en un entorno seguro.

La plataforma extrae la información.

Leo no debe procesar el documento directamente en la conversación.

### 21.4 Solicitud asistida por Leo

Leo podrá:

* consultar estado;
* elegir pedido;
* elegir perfil fiscal;
* elegir uso de CFDI;
* mostrar resumen;
* pedir confirmación;
* iniciar solicitud.

### 21.5 Descarga

La factura debe abrirse mediante:

* liga segura;
* verificación adicional.

### 21.6 Incidencias

Debe escalarse:

* factura retrasada;
* factura no generada;
* datos incorrectos;
* imposibilidad de descarga;
* discrepancia de importe.

**Estado:** Objetivo de Leo con verificación adicional.

## 22\. Referidos

### 22.1 Funciones

El usuario puede:

* compartir enlace;
* consultar historial.

### 22.2 Restricciones

No deben prometerse:

* recompensas;
* bonos;
* descuentos;
* premios;
* incentivos económicos.

**Estado:** Activa.

## 23\. Farmacia

### 23.1 Estado

Farmacia en Línea está temporalmente deshabilitada.

### 23.2 Funciones no disponibles

No se permite:

* buscar medicamentos;
* revisar catálogo;
* revisar precios;
* confirmar existencia;
* agregar productos;
* comprar.

### 23.3 Funciones históricas disponibles

Se puede consultar:

* pedido histórico;
* estatus;
* entrega;
* devolución;
* factura.

### 23.4 Soporte

Las incidencias se canalizan por WhatsApp de atención FAMEDIC.

No deben utilizarse como canal principal los contactos históricos de Vitau.

### 23.5 Leo

Leo podrá:

* informar la suspensión;
* consultar pedidos históricos;
* registrar interés en reactivación;
* sugerir telemedicina, Plan Básico o laboratorios.

No podrá recomendar medicamentos.

**Estado:** Suspendida, con consulta histórica.

## 24\. Servicios institucionales

### 24.1 Información general

La plataforma o Leo pueden brindar información general sobre:

* ferias de salud;
* inplants;
* estudios laborales;
* check-ups ejecutivos;
* planes patrocinados.

### 24.2 Restricciones

No se debe:

* cotizar;
* diseñar propuesta;
* negociar;
* comprometer cobertura;
* confirmar logística;
* cerrar contratación.

### 24.3 Canalización

Toda solicitud específica se canaliza a un ejecutivo.

Datos sugeridos:

* nombre;
* empresa;
* teléfono;
* correo;
* ubicación;
* servicio de interés;
* número aproximado de colaboradores.

**Estado:** Información general y escalamiento.

## 25\. Soporte

### 25.1 Canal principal

WhatsApp de atención FAMEDIC.

### 25.2 Horario

* lunes a viernes: 8:00 a 18:00;
* sábados: 8:00 a 12:00.

### 25.3 Fuera de horario

El sistema debe:

* registrar el motivo;
* conservar el contexto;
* informar que se atenderá en el siguiente horario disponible.

### 25.4 Escalamiento obligatorio

* cargo desconocido;
* cargo duplicado;
* discrepancia de identidad;
* acceso no autorizado;
* error persistente de autenticación;
* cancelación;
* resultado faltante;
* resultado inaccesible;
* factura retrasada;
* factura incorrecta;
* reembolso vencido;
* precio inconsistente;
* cobertura inconsistente;
* error persistente de API;
* solicitud de humano.

### 25.5 Tickets

No existe un sistema formal de tickets.

No debe prometerse:

* número de ticket;
* folio de atención;
* SLA;
* tiempo exacto de resolución.

## 26\. Memoria y personalización de Leo

### 26.1 Uso

Las conversaciones podrán almacenarse para:

* continuidad;
* personalización;
* seguimiento;
* auditoría;
* mejora de experiencia.

### 26.2 Entrenamiento

No se utilizarán actualmente para entrenamiento de modelos.

### 26.3 Consentimiento

La actualización de términos y consentimiento de primer uso de Leo queda pendiente de redacción jurídica.

## 27\. Funciones futuras

### 27.1 Próximamente

* pago en sucursal;
* mayores capacidades transaccionales de Leo;
* posible registro completo desde WhatsApp;
* mayor automatización de soporte;
* reactivación de Farmacia.

### 27.2 Pendientes técnicos

* duración exacta de sesión;
* reglas de reautenticación;
* verificación de cambios de teléfono y correo;
* control de intentos de código;
* registro de apertura de enlaces;
* cantidad de accesos por enlace;
* APIs habilitadas al lanzamiento;
* formulario seguro de registro;
* registro de interés en Farmacia.

### 27.3 Pendientes jurídicos

* Aviso de Privacidad actualizado;
* Términos y Condiciones actualizados;
* consentimiento para Leo;
* memoria conversacional;
* tratamiento por WhatsApp;
* datos de terceros;
* menores;
* PayPal;
* automatización y APIs;
* conservación de conversaciones.

### 27.4 Pendientes operativos

* directorio definitivo de teléfonos;
* responsables por escalamiento;
* procedimiento de cancelaciones;
* procedimiento de resultados faltantes;
* procedimiento de facturas retrasadas;
* mensajes oficiales de error;
* reactivación de Farmacia.

## 28\. Matriz resumida de funciones

|Módulo|Función|Plataforma|Leo|
|-|-|-|-|
|Público|Consultar servicios|Activa|Informativa|
|Registro|Crear cuenta|Activa|Objetivo|
|Acceso|Iniciar sesión|Activa|Objetivo|
|Perfil|Consultar y editar|Activa|Objetivo|
|Familiares|Alta, edición, baja|Activa|Objetivo|
|Pacientes|Alta, edición, baja|Activa|Objetivo|
|Direcciones|Administración|Activa|Objetivo|
|Pagos|Consultar métodos|Activa|Objetivo|
|Pagos|Checkout y pago|Activa|No inicial|
|Plan Médico|Consultar y contratar Básico|Activa|Orientación|
|Laboratorio|Buscar y cotizar|Activa|Objetivo|
|Laboratorio|Modificar carrito|Activa|Objetivo|
|Laboratorio|Checkout|Activa|No inicial|
|Pedidos|Consultar|Activa|Objetivo|
|Resultados|Consultar y descargar|Activa|Objetivo con código|
|Facturas|Consultar y descargar|Activa|Objetivo con código|
|Cancelaciones|Solicitar|Atención|Escalamiento|
|Referidos|Compartir y consultar|Activa|Informativa|
|Farmacia|Nueva compra|Suspendida|No disponible|
|Farmacia|Pedido histórico|Activa|Objetivo|
|B2B|Información general|Activa|Informativa|
|B2B|Cotización|Ejecutivo|Escalamiento|
|Soporte|Atención humana|Activa|Canalización|



