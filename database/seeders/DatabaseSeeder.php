<?php

namespace Database\Seeders;

use App\Actions\GeneratePhoneNumberAction;
use App\Models\Address;
use App\Models\Administrator;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Documentation;
use App\Models\LaboratoryConcierge;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // $user = User::factory()
        //     ->withVerifiedPhone(app(GeneratePhoneNumberAction::class)('MX'))
        //     ->withVerifiedEmail()
        //     ->has(
        //         Customer::factory()
        //             ->withRegularAccount()
        //             ->has(
        //                 Contact::factory()->count(2)
        //             )
        //             ->has(
        //                 Address::factory()->count(2)
        //             )
        //     )->has(
        //         Administrator::factory()->has(
        //             LaboratoryConcierge::factory()
        //         )->withRole('Administrador')
        //     )
        //     ->create([
        //         'email' => 'test@example.com',
        //     ]);

        // Customer::factory()->withFamilyAccount($user->customer)->create();


        Documentation::create(['privacy_policy' => "**Grupo Famedic, S.A. de C.V.** (en lo sucesivo **“FAMEDIC”**), sus subsidiarias y/o filiales con domicilio en Calle José Clemente Orozco número 335, Despacho 202, Colonia Valle Oriente, C. P. 66269, San Pedro Garza García, Nuevo León, México, con portal de internet [https://www.famedic.com.mx/](https://www.famedic.com.mx/) (en lo sucesivo la **“Plataforma”**), es responsable del tratamiento y protección de sus Datos.

## ¿Para qué fines utilizaremos sus Datos Personales?

Los Datos Personales Sensibles y/o no Sensibles que recabemos de Usted, los utilizaremos principalmente para las siguientes finalidades:

1. **Identificación.**  
2. **Ofrecimiento de productos y/o servicios.**  
3. **Atención y soporte técnico.**  
4. **Mejoramiento de productos y/o servicios.**  
5. **Fines estadísticos.**

De manera adicional, utilizaremos su información personal para las siguientes finalidades secundarias o accesorias que no son necesarias para el servicio solicitado, pero que nos permite y facilita brindarle una mejor atención:

- Para fines de notificaciones sobre: soporte, operaciones, entrega de resultados, y/o aviso de nuevos servicios.  
- Estrategias de mercadotecnia y/o publicidad.  
- Promociones, concursos y/o actividades de salud.  
- Avisos de cobro.

En caso de que no desee que sus Datos Personales sean tratados para estos fines adicionales, desde este momento Usted nos puede comunicar lo anterior, o en su caso tiene un plazo de 5 (cinco) días hábiles para manifestarnos vía correo electrónico [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx) su negativa para el tratamiento o aprovechamiento de sus Datos Personales para las finalidades secundarias.

La negativa para el uso de sus Datos Personales para estas finalidades adicionales no será motivo para que le sean negados los productos y/o servicios que solicita o contrata a través de nuestra Plataforma.

## ¿Cómo recabamos sus Datos Personales?

**FAMEDIC** recopila sus Datos Personales a través de las interacciones con los productos y/o servicios que se encuentran en la **Plataforma**, tales como:

1. Que Usted personalmente nos proporcione sus datos.  
2. Por recopilación con la interacción de los productos y/o servicios.  
3. Correo Electrónico.  
4. Vía Telefónica.  
5. A través de un tercero mediante transferencia consentida.  
6. De una fuente de acceso público.

## ¿Qué Datos Personales utilizaremos para esos fines?

Para llevar a cabo las finalidades descritas en el presente Aviso, utilizaremos los siguientes:

- **Datos de Identificación.**  
- **Datos de Contacto.**

## ¿Con quién compartimos su información personal y para qué fines?

Sus Datos Personales pueden ser transferidos y tratados dentro y fuera del país, por personas físicas o morales distintas a **FAMEDIC**. En ese sentido, su información puede ser compartida con:

| **Destinatario de los Datos Personales**                      | **Finalidad**                                                    | **Se requiere de consentimiento** |
| ------------------------------------------------------------- | ---------------------------------------------------------------- | --------------------------------- |
| Sociedades subsidiarias o afiliadas a **FAMEDIC**             | Para la correcta y mejor prestación de servicios y/o productos.  | No es necesario                   |
| Instituciones bancarias                                       | Para la correcta y mejor prestación de servicios y/o productos.  | No es necesario                   |
| Proveedores, Distribuidores y/o Empresas terceras             | Para la correcta y mejor prestación de servicios y/o productos.  | No es necesario                   |
| Cualquier tipo de Autoridad (Federal, Estatal, o Municipal)   | Ejercicio de derechos, cumplimiento de resoluciones judiciales.  | No es necesario                   |

**FAMEDIC** no compartirá o transferirá sus Datos Personales a terceros salvo los casos anteriores.

## ¿Cómo puede Acceder, Rectificar o Cancelar sus Datos Personales u Oponerse a su uso?

Usted o su representante legal tiene derecho a:

1. **Acceso**: Conocer los Datos Personales que tenemos de Usted y los detalles del tratamiento.  
2. **Rectificación**: Solicitar la corrección en caso de ser inexactos, incompletos y/o desactualizados.  
3. **Cancelación**: Pedir su eliminación de nuestra base de datos si resultan innecesarios para las finalidades justificadas.  
4. **Oposición**: Oponerse al tratamiento de los mismos para fines específicos.

Para ejercer estos derechos (**Derechos ARCO**), deberá enviar la solicitud al correo: [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx).

## ¿Cómo puede revocar su consentimiento para el uso de sus Datos Personales?

Puede revocar el consentimiento otorgado para el tratamiento de sus Datos Personales, considerando que algunas obligaciones legales podrían impedir atender la solicitud de inmediato.

Para revocar su consentimiento, deberá presentar su solicitud a través del correo: [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx).

## ¿Qué son y de qué forma usamos los Cookies web o cualquier tecnología de rastreo?

Las **“cookies”** son archivos de texto que se almacenan en su equipo al navegar por la web.  
Las **“web beacons”** son imágenes utilizadas para monitorear su comportamiento en páginas o correos electrónicos.

Usamos estas tecnologías para recolectar información como:

- País de origen.  
- Tipo de navegador y sistema operativo.  
- Páginas visitadas y vínculos seguidos.  
- Dirección IP.

Puede deshabilitar estas tecnologías desde la configuración de su navegador.

## ¿Cómo puede conocer cualquier cambio de este Aviso de Privacidad?

El Aviso de Privacidad puede modificarse o actualizarse debido a:

- Requerimientos legales.  
- Nuevos productos y servicios.  
- Cambios en políticas de protección de datos.

Las modificaciones estarán disponibles en [https://www.famedic.com.mx/](https://www.famedic.com.mx/).

**Fecha de última actualización: 18 / diciembre / 2023.**  
**Fecha de última revisión: 01 / diciembre / 2023.**", 'terms_of_service' => "**Grupo Famedic, S.A. de C.V.** (en lo sucesivo **“FAMEDIC”**), sus subsidiarias y/o filiales con domicilio en Calle José Clemente Orozco número 335, Despacho 202, Colonia Valle Oriente, C. P. 66269, San Pedro Garza García, Nuevo León, México, con portal de internet [https://www.famedic.com.mx/](https://www.famedic.com.mx/) (en lo sucesivo la **“Plataforma”**), es responsable del tratamiento y protección de sus Datos Personales Sensibles y/o no Sensibles, y al respecto nos permitimos informar lo siguiente:

## ¿Para qué fines utilizaremos sus Datos Personales?

Los Datos Personales Sensibles y/o no Sensibles que recabemos de Usted, los utilizaremos principalmente para las siguientes finalidades:

1. **Identificación**  
2. **Ofrecimiento de productos y/o servicios**  
3. **Atención y soporte técnico**  
4. **Mejoramiento de productos y/o servicios**  
5. **Fines estadísticos**

De manera adicional, utilizaremos su información personal para las siguientes finalidades secundarias o accesorias que no son necesarias para el servicio solicitado, pero que nos permite y facilita brindarle una mejor atención:

- Para fines de notificaciones sobre soporte, operaciones, entrega de resultados y/o aviso de nuevos servicios.  
- Estrategias de mercadotecnia y/o publicidad.  
- Promociones, concursos y/o actividades de salud.  
- Avisos de cobro.

En caso de que no desee que sus Datos Personales sean tratados para estos fines adicionales, desde este momento Usted nos puede comunicar lo anterior, o en su caso tiene un plazo de 5 (cinco) días hábiles para manifestarnos vía correo electrónico [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx) su negativa para el tratamiento o aprovechamiento de sus Datos Personales para las finalidades secundarias.

La negativa para el uso de sus Datos Personales para estas finalidades adicionales no será motivo para que le sean negados los productos y/o servicios que solicita o contrata a través de nuestra Plataforma.

## ¿Cómo recabamos sus Datos Personales?

**FAMEDIC** recopila sus Datos Personales a través de las interacciones con los productos y/o servicios que se encuentran en la Plataforma, mismas que se indican a continuación:

1. Que Usted personalmente nos proporcione sus datos  
2. Por recopilación con la interacción de los productos y/o servicios  
3. Correo Electrónico  
4. Vía Telefónica  
5. A través de un tercero mediante transferencia consentida  
6. De una fuente de acceso público

## ¿Qué Datos Personales utilizaremos para esos fines?

Para llevar a cabo las finalidades descritas en el presente Aviso, utilizaremos los siguientes:

- **Datos de Identificación**  
- **Datos de Contacto**

## ¿Con quién compartimos su información personal y para qué fines?

Le informamos que sus Datos Personales pueden ser transferidos y tratados dentro y fuera del país, por personas físicas o morales distintas a **FAMEDIC**. En ese sentido, su información puede ser compartida con las siguientes personas, empresas, organizaciones o autoridades distintas a nosotros, para los siguientes fines:

| **Destinatario de los Datos Personales**                                                         | **Finalidad**                                                                                                                                                              | **¿Se requiere de consentimiento?** |
| ------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| Sociedades subsidiarias o afiliadas a FAMEDIC                                                    | Para la correcta y mejor prestación de servicios y/o productos a través de terceros en la Plataforma.                                                                      | No es necesario                       |
| Instituciones bancarias                                                                          | Para la correcta y mejor prestación de servicios y/o productos a través de terceros en la Plataforma.                                                                      | No es necesario                       |
| Proveedores, Distribuidores y/o Empresas terceras nacionales y/o extranjeras que tengan celebrados convenios con FAMEDIC | Para la correcta y mejor prestación de servicios y/o productos a través de terceros en la Plataforma.                                                                      | No es necesario                       |
| Cualquier tipo de Autoridad, tanto de ámbito Federal, Estatal y/o Municipal.                     | 1. Cuando requieran de información para el ejercicio de un derecho o una acción. 2. Para dar cumplimiento a resoluciones judiciales y/o administrativas.                   | No es necesario                       |

Salvo los casos anteriores, **FAMEDIC** no compartirá o transferirá sus Datos Personales a terceros, por lo que es nuestra responsabilidad salvaguardar y velar por el correcto uso y/o manejo de los Datos Personales Sensibles y/o no Sensibles que nos sean proporcionados.

En caso que **FAMEDIC** solicite algún dato considerado como sensible a fin de cumplir con su objeto, deberá para su tratamiento obtener su consentimiento expreso.

Asimismo, hacemos de su conocimiento que para cualquier transferencia a terceros distintos a los referidos en el presente Aviso de Privacidad, en términos de la ley requerimos su consentimiento. Si Usted no manifiesta su negativa para dichas transferencias, entenderemos que nos lo ha otorgado. Para manifestar su negativa, a partir de este momento nos puede comunicar su solicitud al siguiente correo electrónico: [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx)

### Consentimiento del USUARIO

Para efectos de este apartado, se define **USUARIO** y/o **USUARIOS** como cualquier persona física que cuente con una cuenta y contraseña de la Plataforma **FAMEDIC** y que tenga contratado los servicios que ofrece **FAMEDIC** dentro de la misma.

Los USUARIOS de **FAMEDIC** que utilicen y tengan acceso a la Plataforma de **FAMEDIC**, autorizan que sus datos personales sensibles derivados de los resultados médicos/clínicos que podrán realizarse con los prestadores de servicios con los cuales **FAMEDIC** tiene celebrados contratos y/o convenios, sean visualizados a través de la Plataforma única y exclusivamente por los USUARIOS propietarios de dichos resultados.

Los USUARIOS reconocen que al ser los resultados cargados en la Plataforma de **FAMEDIC**, son materia de tratamiento de datos personales sensibles, y el USUARIO será responsable del buen uso y tratamiento de dicha información derivada de estos resultados, en el entendido que **FAMEDIC** tendrá únicamente a su resguardo dicha información para el USUARIO.

**FAMEDIC** será responsable del resguardo de la documentación cargada en su Plataforma y de la no divulgación a terceros, sin limitar cualquier acto que contravenga con las disposiciones oficiales de la Ley Federal De Protección De Datos Personales En Posesión De Los Particulares en tema de datos sensibles, garantizando en todo momento el buen uso y tratamiento de dichos datos.

**FAMEDIC** no se obliga ni será responsable de la divulgación, mal tratamiento, difamación, sin limitar cualquier acto que contravenga con las disposiciones oficiales de la Ley Federal De Protección De Datos Personales En Posesión De Los Particulares, que los USUARIOS de su Plataforma compartan por voluntad a terceras personas o hagan mal uso de los datos generales, sensibles o algún otro que se considere propio o de un tercero.

## ¿Cómo puede Acceder, Rectificar o Cancelar sus Datos Personales u Oponerse a su uso?

Usted en lo personal o mediante representante legal tiene derecho a:

1. **Acceso**: Conocer los Datos Personales que tenemos de Usted y los detalles del tratamiento de los mismos.  
2. **Rectificación**: Solicitar la corrección en caso de ser inexactos, incompletos y/o desactualizados.  
3. **Cancelación**: Pedir su cancelación de nuestro registro o base de datos cuando considere que resulten excesivos o innecesarios para las finalidades que justificaron su obtención.  
4. **Oposición**: Oponerse al tratamiento de los mismos para fines específicos.

Los anteriores derechos (Acceso, Rectificación, Cancelación y Oposición) se conocen como **Derechos ARCO**.

Para el ejercicio de sus Derechos ARCO, Usted deberá enviar la solicitud de Derechos ARCO al siguiente correo electrónico: [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx)

Ponemos a su disposición el siguiente medio para descargar la **Solicitud Única Para Ejercer Derechos ARCO**.

En caso de ser necesario, podrá presentar la solicitud de Derechos ARCO en el domicilio indicado al principio del presente Aviso.

Para mayor información, favor de comunicarse con el Departamento de Datos Personales a través del correo electrónico antes mencionado.

## ¿Cómo puede revocar su consentimiento para el uso de sus Datos Personales?

Usted puede revocar el consentimiento que, en su caso, nos haya otorgado para el tratamiento de sus Datos Personales. Sin embargo, es importante que tenga en cuenta que no en todos los casos podremos atender su solicitud o concluir el uso de forma inmediata, ya que es posible que por alguna obligación legal requiramos seguir tratando sus Datos Personales. Asimismo, Usted deberá considerar que para ciertos fines, la revocación de su consentimiento implicará la conclusión de su relación con nosotros y la prestación del servicio y/o producto de un tercero a través de la Plataforma.

Para revocar su consentimiento deberá presentar su solicitud en los términos contenidos en los **Derechos ARCO** del presente Aviso de Privacidad, por lo que **FAMEDIC** responderá cualquier solicitud de revocación de consentimiento en un plazo máximo de 20 (veinte) días calendario o el máximo permitido por la Ley, y **FAMEDIC** hará efectiva la revocación dentro de los 10 (diez) días calendario siguientes a la fecha de respuesta. Los plazos podrán ser ampliados en los términos que señale la respectiva legislación aplicable.

Si Usted requiere mayor información sobre el procedimiento y requisitos para la revocación del consentimiento, podrá ponerse en contacto con nuestro Departamento de Datos Personales en la siguiente dirección electrónica: [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx)

## ¿Cómo puede limitar el uso o divulgación de sus Datos Personales?

Si es su deseo limitar el uso o divulgación de sus Datos Personales, a partir de este momento nos puede comunicar su solicitud al siguiente correo electrónico: [contacto@famedic.com.mx](mailto:contacto@famedic.com.mx)

## ¿Qué son y de qué forma usamos los Cookies web o cualquier tecnología de rastreo?

Las **“cookies”** son archivos de texto que se descargan automáticamente y se almacenan en el disco duro del equipo de cómputo del usuario al navegar en una página de Internet específica, que permiten recordar al servidor de Internet algunos datos sobre este usuario, entre ellos, sus preferencias para la visualización de las páginas en ese servidor, nombre y contraseña.

Por su parte, las **“web beacons”** son imágenes insertadas en una página de Internet o correo electrónico que pueden ser utilizadas para monitorear el comportamiento de un visitante, como almacenar información sobre la dirección IP del usuario, duración del tiempo de interacción en dicha página y el tipo de navegador utilizado, entre otros.

Le informamos que utilizamos “cookies” y “web beacons” para obtener información de Usted, como el país de origen, su tipo de navegador y sistema operativo, páginas de Internet que visita, vínculos que sigue, dirección IP y el sitio que visitó antes de entrar al nuestro.

Estas “cookies” y otras tecnologías pueden ser deshabilitadas. Para conocer cómo hacerlo, puede consultar la sección de ayuda del navegador de su preferencia (Internet Explorer, Firefox Mozilla, Opera, Chrome, entre otros).

## ¿Cómo puede conocer cualquier cambio de este Aviso de Privacidad?

El presente Aviso de Privacidad puede sufrir en cualquier momento modificaciones o actualizaciones por motivo de la atención de nuevos requerimientos legales, nuevos productos y servicios, cambio en políticas de protección de datos o por otras causas. Estas modificaciones estarán disponibles en nuestra página de internet [https://www.famedic.com.mx/](https://www.famedic.com.mx/).

**La fecha de la última actualización al presente aviso de privacidad: 10 / octubre / 2024.**  
**La fecha de la última revisión al presente aviso de privacidad: 10 / octubre / 2024.**

**Contacto:**  
[contacto@famedic.com.mx](mailto:contacto@famedic.com.mx)  
[https://www.famedic.com.mx/](https://www.famedic.com.mx/)"]);
    }
}
