import FamedicLayout from "@/Layouts/FamedicLayout";
import { Link } from "@inertiajs/react";

export default function PrivacyPolicy({ name }) {
    return (
        <FamedicLayout title={name}>
            <div className="bg-white py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="lg:text-center">
                        <div className="flex justify-center mb-6">
                            <img 
                                src="/images/logo.png" 
                                alt="FAMEDIC Logo" 
                                className="h-12"
                            />
                        </div>
                        <h1 className="font-poppins text-3xl font-bold tracking-tight text-famedic-darker sm:text-4xl">
                            GRUPO FAMEDIC, S.A. DE C.V.
                        </h1>
                        <h2 className="mt-4 text-2xl font-semibold text-famedic-light">{name}</h2>
                        <p className="mt-2 text-sm text-gray-600">
                            Última actualización: 10 / octubre / 2024
                        </p>
                        <p className="mt-2 text-sm text-gray-600">
                            Última revisión: 10 / octubre / 2024
                        </p>
                    </div>

                    <div className="prose prose-lg mx-auto mt-8 max-w-4xl prose-headings:font-poppins prose-headings:text-famedic-darker prose-strong:text-famedic-darker prose-a:text-famedic-light hover:prose-a:text-famedic-darker">
                        <div className="space-y-8">
                            <section>
                                <p className="text-justify">
                                    <strong>Grupo Famedic, S.A. de C.V.</strong> (en lo sucesivo "FAMEDIC"), sus
                                    subsidiarias y/o filiales con domicilio en Calle José Clemente Orozco
                                    número 335, Despacho 202, Colonia Valle Oriente, C. P. 66269, San Pedro
                                    Garza García, Nuevo León, México, con portal de internet{" "}
                                    <Link 
                                        href="https://www.famedic.com.mx/" 
                                        className="font-semibold"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        https://www.famedic.com.mx/
                                    </Link>{" "}
                                    (en lo sucesivo la "Plataforma"), es responsable del tratamiento y protección
                                    de sus Datos Personales Sensibles y/o no Sensibles, y al respecto nos
                                    permitimos informar lo siguiente:
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Para qué fines utilizaremos sus Datos Personales?
                                </h2>
                                <p className="text-justify">
                                    Los Datos Personales Sensibles y/o no Sensibles que recabemos de Usted,
                                    los utilizaremos principalmente para las siguientes finalidades: 
                                </p>
                                <ul className="list-disc pl-6 space-y-2">
                                    <li><strong>Identificación</strong></li>
                                    <li><strong>Ofrecimiento de productos y/o servicios</strong></li>
                                    <li><strong>Atención y soporte técnico</strong></li>
                                    <li><strong>Mejoramiento de productos y/o servicios</strong></li>
                                    <li><strong>Fines estadísticos</strong></li>
                                </ul>
                                
                                <div className="mt-6 p-4 bg-gray-50 rounded-lg">
                                    <h3 className="text-lg font-semibold text-gray-800">Finalidades secundarias o accesorias</h3>
                                    <p className="text-justify mt-2">
                                        De manera adicional, utilizaremos su información personal para las
                                        siguientes finalidades secundarias o accesorias que no son necesarias
                                        para el servicio solicitado, pero que nos permite y facilita brindarle
                                        una mejor atención:
                                    </p>
                                    <ul className="list-disc pl-6 mt-2 space-y-1">
                                        <li>Para fines de notificaciones sobre: soporte, operaciones, entrega de resultados, y/o aviso de nuevos servicios.</li>
                                        <li>Estrategias de mercadotecnia y/o publicidad.</li>
                                        <li>Promociones, concursos y/o actividades de salud.</li>
                                        <li>Avisos de cobro.</li>
                                    </ul>
                                    <p className="text-justify mt-4">
                                        En caso de que no desee que sus Datos Personales sean tratados para
                                        estos fines adicionales, desde este momento Usted nos puede comunicar lo
                                        anterior, o en su caso tiene un plazo de <strong>5 (cinco) días hábiles</strong> para
                                        manifestarnos vía correo electrónico{" "}
                                        <Link 
                                            href="mailto:contacto@famedic.com.mx" 
                                            className="font-semibold"
                                        >
                                            contacto@famedic.com.mx
                                        </Link>{" "}
                                        su negativa para el tratamiento o aprovechamiento de sus Datos
                                        Personales para las finalidades secundarias.
                                    </p>
                                    <p className="text-justify mt-2">
                                        La negativa para el uso de sus Datos Personales para estas finalidades
                                        adicionales, <strong>no será motivo</strong> para que le sean negados los productos y/o
                                        servicios que solicita o contrata a través de nuestra Plataforma.
                                    </p>
                                </div>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Cómo recabamos sus Datos Personales?
                                </h2>
                                <p className="text-justify">
                                    FAMEDIC recopila sus Datos Personales a través de las interacciones con
                                    los productos y/o servicios que se encuentran en la Plataforma. Mismas
                                    que se indican a continuación:
                                </p>
                                <ul className="list-disc pl-6 space-y-2 mt-2">
                                    <li>Que Usted personalmente nos proporcione sus datos</li>
                                    <li>Por recopilación con la interacción de los productos y/o servicios</li>
                                    <li>Correo Electrónico</li>
                                    <li>Vía Telefónica</li>
                                    <li>A través de un tercero mediante transferencia consentida</li>
                                    <li>De una fuente de acceso público</li>
                                </ul>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Qué Datos Personales utilizaremos para esos fines?
                                </h2>
                                <p className="text-justify">
                                    Para llevar a cabo las finalidades descritas en el presente Aviso,
                                    utilizaremos los siguientes: <strong>Datos de Identificación</strong>; y por último{" "}
                                    <strong>Datos de Contacto</strong>.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Con quién compartimos su información personal y para qué fines?
                                </h2>
                                <p className="text-justify">
                                    Le informamos que sus Datos Personales pueden ser transferidos y
                                    tratados dentro y fuera del país, por personas físicas o morales
                                    distintas a FAMEDIC. En ese sentido, su información puede ser compartida
                                    con las siguientes personas, empresas, organizaciones o autoridades
                                    distintas a nosotros, para los siguientes fines:
                                </p>
                                
                                <div className="mt-6 overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Destinatario de los Datos Personales
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Finalidad
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Se requiere de consentimiento
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            <tr>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    Sociedades subsidiarias o afiliadas a FAMEDIC
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    Para la correcta y mejor prestación de servicios y/o productos a través de terceros en la Plataforma.
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900 font-semibold">
                                                    No es necesario
                                                </td>
                                            </tr>
                                            <tr>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    Instituciones bancarias
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    Para la correcta y mejor prestación de servicios y/o productos a través de terceros en la Plataforma.
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900 font-semibold">
                                                    No es necesario
                                                </td>
                                            </tr>
                                            <tr>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    Proveedores, Distribuidores y/o Empresas terceras nacionales y/o extranjeras que tengan celebrados convenios con FAMEDIC
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    Para la correcta y mejor prestación de servicios y/o productos a través de terceros en la Plataforma.
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900 font-semibold">
                                                    No es necesario
                                                </td>
                                            </tr>
                                            <tr>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    Cualquier tipo de Autoridad, tanto de ámbito Federal, Estatal y/o Municipal
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900">
                                                    i) Cuando requieran de información para el ejercicio de un derecho o una acción.<br/>
                                                    ii) Para dar cumplimiento a resoluciones judiciales y/o administrativas.
                                                </td>
                                                <td className="px-4 py-4 text-sm text-gray-900 font-semibold">
                                                    No es necesario
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div className="mt-6 p-4 bg-gray-50 rounded-lg">
                                    <h3 className="text-lg font-semibold text-gray-800">Procesamiento de pagos</h3>
                                    <p className="text-justify mt-2">
                                        En cumplimiento con la Ley Federal de Protección de Datos Personales en
                                        Posesión de los Particulares (LFPDPPP) y las mejores prácticas de
                                        comercio electrónico, FAMEDIC informa que los datos relacionados con
                                        tarjetas de crédito o débito de los usuarios no son almacenados ni
                                        tratados directamente por FAMEDIC. Los datos son enviados por medio de
                                        canales seguros a procesadores de pago autorizados como:
                                    </p>
                                    <ul className="list-disc pl-6 mt-2 space-y-1">
                                        <li><strong>Stripe, Inc.</strong></li>
                                        <li><strong>Efevoo Pay, S.A.P.I. de C.V.</strong></li>
                                    </ul>
                                </div>
                                
                                <p className="text-justify mt-6">
                                    Salvo los casos anteriores, FAMEDIC no compartirá o transferirá sus
                                    Datos Personales a terceros, por lo que es nuestra responsabilidad
                                    salvaguardar y velar por el correcto uso y/o manejo de los Datos
                                    Personales Sensibles y/o no Sensibles que nos sean proporcionados.
                                </p>
                                
                                <div className="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <h3 className="text-lg font-semibold text-yellow-800">Consentimiento para otras transferencias</h3>
                                    <p className="text-justify mt-2">
                                        Asimismo, hacemos de su conocimiento que para cualquier transferencia a
                                        terceros distintos a los referidos en el presente Aviso de Privacidad,
                                        en términos de la ley requerimos su consentimiento. Si Usted no
                                        manifiesta su negativa para dichas transferencias, entenderemos que nos
                                        lo ha otorgado. Para manifestar su negativa, a partir de este momento
                                        nos puede comunicar su solicitud al siguiente correo electrónico:{" "}
                                        <Link 
                                            href="mailto:contacto@famedic.com.mx" 
                                            className="font-semibold"
                                        >
                                            contacto@famedic.com.mx
                                        </Link>
                                    </p>
                                </div>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    Consentimiento del USUARIO
                                </h2>
                                <div className="p-4 bg-gray-50 rounded-lg">
                                    <p className="text-justify mb-4">
                                        <strong>Para efectos de este apartado, se define USUARIO y/o USUARIOS</strong> como
                                        cualquier persona física que cuente con una cuenta y contraseña de la
                                        Plataforma FAMEDIC y que tenga contratado los servicios que ofrece
                                        FAMEDIC dentro de la misma.
                                    </p>
                                    
                                    <p className="text-justify mb-4">
                                        Los USUARIOS de FAMEDIC que utilicen y tengan acceso a la Plataforma de
                                        FAMEDIC, autorizan que sus datos personales sensibles derivados de los
                                        resultados médicos/clínicos que podrán realizarse con los prestadores de
                                        servicios con los cuales FAMEDIC tiene celebrados contratos y/o
                                        convenios, sean visualizados a través de la Plataforma única y
                                        exclusivamente por los USUARIOS propietarios de dichos resultados.
                                    </p>
                                    
                                    <p className="text-justify mb-4">
                                        Los USUARIOS reconocen que al ser los resultados cargados en la
                                        Plataforma de FAMEDIC, son materia de tratamiento de datos personales
                                        sensibles, y el USUARIO será responsable del buen uso y tratamiento de
                                        dicha información derivada de estos resultados, en el entendido que
                                        FAMEDIC tendrá únicamente a su resguardo dicha información para el
                                        USUARIO.
                                    </p>
                                    
                                    <p className="text-justify mb-4">
                                        FAMEDIC será responsable del resguardo de la documentación cargada en su
                                        Plataforma y de la no divulgación a terceros, sin limitar cualquier acto
                                        que contravenga con las disposiciones oficiales de la Ley Federal De
                                        Protección De Datos Personales En Posesión De Los Particulares derivada,
                                        en tema de datos sensibles, garantizando en todo momento el buen uso y
                                        tratamiento de dichos datos.
                                    </p>
                                    
                                    <p className="text-justify">
                                        FAMEDIC no se obliga ni será responsable de la divulgación, mal
                                        tratamiento, difamación, sin limitar cualquier acto que contravenga con
                                        las disposiciones oficiales de la Ley Federal De Protección De Datos
                                        Personales En Posesión De Los Particulares, que los USUARIOS de su
                                        Plataforma compartan por voluntad a terceras personas o hagan mal uso de
                                        los datos generales, sensibles o algún otro que se considere propio o de
                                        un tercero.
                                    </p>
                                </div>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Cómo puede Acceder, Rectificar o Cancelar sus Datos Personales u Oponerse a su uso?
                                </h2>
                                <p className="text-justify">
                                    Usted en lo personal o mediante representante legal tiene derecho a:
                                </p>
                                <ul className="list-disc pl-6 space-y-2 mt-2">
                                    <li>
                                        <strong>Acceso:</strong> conocer los Datos Personales que tenemos de Usted y los detalles del tratamiento de los mismos.
                                    </li>
                                    <li>
                                        <strong>Rectificación:</strong> solicitar la corrección en caso de ser inexactos, incompletos y/o desactualizados.
                                    </li>
                                    <li>
                                        <strong>Cancelación:</strong> pedir su cancelación de nuestro registro o base de datos cuando considere que resulten excesivos o innecesarios para las finalidades que justificaron su obtención.
                                    </li>
                                    <li>
                                        <strong>Oposición:</strong> oponerse al tratamiento de los mismos para fines específicos.
                                    </li>
                                </ul>
                                <p className="text-justify mt-4">
                                    Los anteriores derechos <strong>Acceso; Rectificación; Cancelación; y Oposición</strong> se conocen como <strong>Derechos ARCO</strong>.
                                </p>
                                
                                <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                    <h3 className="text-lg font-semibold text-blue-800">Ejercicio de Derechos ARCO</h3>
                                    <p className="text-justify mt-2">
                                        Para el ejercicio de sus Derechos ARCO, Usted deberá enviar la solicitud
                                        de Derechos ARCO al siguiente correo electrónico:{" "}
                                        <Link 
                                            href="mailto:contacto@famedic.com.mx" 
                                            className="font-semibold"
                                        >
                                            contacto@famedic.com.mx
                                        </Link>
                                    </p>
                                    <div className="mt-4">
                                        <Link 
                                            href={route('rights-arco')}
                                            className="inline-flex items-center px-4 py-2 text-dark rounded-md transition-colors"
                                        >
                                            Solicitud Única Para Ejercer Derechos ARCO
                                        </Link>
                                    </div>
                                    <p className="text-justify mt-4">
                                        En caso de ser necesario podrá presentar la solicitud de Derechos ARCO
                                        en el domicilio indicado al principio del presente Aviso.
                                    </p>
                                    <p className="text-justify mt-2">
                                        Para mayor información, favor de comunicarse con el Departamento de
                                        Datos Personales a través del correo electrónico antes mencionado.
                                    </p>
                                </div>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Cómo puede revocar su consentimiento para el uso de sus Datos Personales?
                                </h2>
                                <p className="text-justify">
                                    Usted puede revocar el consentimiento que, en su caso, nos haya otorgado
                                    para el tratamiento de sus Datos Personales. Sin embargo, es importante
                                    que tenga en cuenta que no en todos los casos podremos atender su
                                    solicitud o concluir el uso de forma inmediata, ya que es posible que
                                    por alguna obligación legal requiramos seguir tratando sus Datos
                                    Personales. Asimismo, Usted deberá considerar que para ciertos fines, la
                                    revocación de su consentimiento implicará la conclusión de su relación
                                    con nosotros y la prestación del servicio y/o producto de un tercero a
                                    través de la Plataforma.
                                </p>
                                <div className="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <p className="text-justify">
                                        <strong>Procedimiento de revocación:</strong> Para revocar su consentimiento deberá presentar su solicitud en los
                                        términos contenidos en los Derechos ARCO del presente Aviso de
                                        Privacidad, por lo que FAMEDIC responderá cualquier solicitud de
                                        revocación de consentimiento en un plazo máximo de{" "}
                                        <strong>20 (veinte) días calendario</strong> o el máximo permitido por la Ley, y
                                        FAMEDIC hará efectiva la revocación dentro de los{" "}
                                        <strong>10 (diez) días calendario</strong> siguientes a la fecha de respuesta.
                                        Los plazos podrán ser ampliados en los términos que señale la respectiva
                                        legislación aplicable.
                                    </p>
                                    <p className="text-justify mt-2">
                                        Si Usted requiere mayor información sobre el procedimiento y requisitos
                                        para la revocación del consentimiento, Usted podrá ponerse en contacto
                                        con nuestro Departamento de Datos Personales en la siguiente dirección
                                        electrónica:{" "}
                                        <Link 
                                            href="mailto:contacto@famedic.com.mx" 
                                            className="font-semibold"
                                        >
                                            contacto@famedic.com.mx
                                        </Link>
                                    </p>
                                </div>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Cómo puede limitar el uso o divulgación de sus Datos Personales?
                                </h2>
                                <p className="text-justify">
                                    Si es su deseo limitar el uso o divulgación de sus Datos Personales, a
                                    partir de este momento nos puede comunicar su solicitud al siguiente
                                    correo electrónico:{" "}
                                    <Link 
                                        href="mailto:contacto@famedic.com.mx" 
                                        className="font-semibold"
                                    >
                                        contacto@famedic.com.mx
                                    </Link>
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Qué son y de qué forma usamos los Cookies web o cualquier tecnología de rastreo?
                                </h2>
                                <p className="text-justify">
                                    Las <strong>"cookies"</strong> son archivos de texto que son descargados automáticamente
                                    y almacenados en el disco duro del equipo de cómputo del usuario al
                                    navegar en una página de Internet específica, que permiten recordar al
                                    servidor de Internet algunos datos sobre este usuario, entre ellos, sus
                                    preferencias para la visualización de las páginas en ese servidor,
                                    nombre y contraseña.
                                </p>
                                <p className="text-justify mt-4">
                                    Por su parte, las <strong>"web beacons"</strong> son imágenes insertadas en una página de
                                    Internet o correo electrónico, que puede ser utilizado para monitorear
                                    el comportamiento de un visitante, como almacenar información sobre la
                                    dirección IP del usuario, duración del tiempo de interacción en dicha
                                    página y el tipo de navegador utilizado, entre otros.
                                </p>
                                <div className="mt-4 p-4 bg-gray-50 rounded-lg">
                                    <p className="text-justify">
                                        Le informamos que utilizamos <strong>"cookies"</strong> y <strong>"web beacons"</strong> para obtener
                                        información de Usted, como el país de origen, su tipo de navegador y
                                        sistema operativo, páginas de Internet que visita, vínculos que sigue,
                                        dirección IP, sitio que visitó antes de entrar al nuestro.
                                    </p>
                                    <p className="text-justify mt-2">
                                        Estas <strong>"cookies"</strong> y otras tecnologías pueden ser deshabilitadas. Para
                                        conocer cómo hacerlo, puede consultar la sección de ayuda del navegador
                                        de su preferencia <em>(Internet Explorer, Firefox Mozilla, Opera, Chrome, entre otros)</em>.
                                    </p>
                                </div>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold text-famedic-light">
                                    ¿Cómo puede conocer cualquier cambio de este Aviso de Privacidad?
                                </h2>
                                <p className="text-justify">
                                    El presente Aviso de Privacidad puede sufrir en cualquier momento
                                    modificaciones o actualizaciones por motivo a la atención de nuevos
                                    requerimientos legales, nuevos productos y servicios, cambio en
                                    políticas de protección de datos o por otras causas.
                                </p>
                                <div className="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                    <p className="text-justify">
                                        Estas modificaciones estarán disponibles en nuestra página de internet{" "}
                                        <Link 
                                            href="https://www.famedic.com.mx" 
                                            className="font-semibold"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            https://www.famedic.com.mx
                                        </Link>.
                                    </p>
                                </div>
                                <div className="mt-6 p-4 bg-gray-100 rounded-lg">
                                    <p className="text-center font-semibold">
                                        La fecha de la última actualización al presente aviso de privacidad:{" "}
                                        <span className="text-famedic-light">10 / octubre / 2024</span>
                                    </p>
                                    <p className="text-center font-semibold mt-2">
                                        La fecha de la última revisión al presente aviso de privacidad:{" "}
                                        <span className="text-famedic-light">10 / octubre / 2024</span>
                                    </p>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </FamedicLayout>
    );
}