import FamedicLayout from "@/Layouts/FamedicLayout";
import { Link } from "@inertiajs/react";

export default function TermsOfService({ name }) {
    return (
        <FamedicLayout title={name}>
            <div className="bg-white py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="lg:text-center">
                        <h1 className="font-poppins text-3xl font-bold tracking-tight text-famedic-darker sm:text-4xl">
                            {name}
                        </h1>
                        <p className="mt-2 text-sm text-gray-600">
                            Última Revisión: 19 de enero de 2023
                        </p>
                    </div>

                    <div className="prose prose-lg mx-auto mt-8 max-w-4xl prose-headings:font-poppins prose-headings:text-famedic-darker prose-strong:text-famedic-darker prose-a:text-famedic-light hover:prose-a:text-famedic-darker">
                        <div className="space-y-8">
                            <section>
                                <p className="text-justify">
                                    Estos son los términos y condiciones (en lo sucesivo los "Términos y
                                    Condiciones") aplicables al acceso y uso del sitio en Internet (en lo
                                    sucesivo la "Plataforma") de Grupo Famedic, S.A. de C.V., sus filiales o
                                    subsidiarias y aquellas empresas controladas por la misma sociedad o
                                    grupo corporativo (en lo sucesivo "FAMEDIC") y al uso de los servicios
                                    ofrecidos en la Plataforma por cualquier persona (en lo sucesivo el
                                    "Usuario"). El Usuario podrá transmitir información directamente con
                                    FAMEDIC, a fin de celebrar la contratación de diversos productos y/o
                                    servicios ofrecidos por FAMEDIC o terceros a través de la Plataforma,
                                    mismos que se definen más adelante.
                                </p>
                                <p className="text-justify">
                                    Lo anterior con la finalidad de que FAMEDIC utilizará dicha información
                                    para gestionar, proveer y/o realizar todas las acciones y transacciones
                                    necesarias para que el Usuario obtenga los beneficios, entregas,
                                    información y celebración efectiva de los productos y/o servicios
                                    contenidos en la Plataforma. El continuar accesando o usando la
                                    Plataforma, o cualquier servicio ofrecido en la Plataforma, implicará la
                                    aceptación de los Términos y Condiciones por parte de los Usuarios. Por
                                    lo anterior, le sugerimos que lea los Términos y Condiciones antes de
                                    seguir utilizando el Sitio.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">ACEPTACIÓN DEL USUARIO A LOS TÉRMINOS Y CONDICIONES</h2>
                                <p className="text-justify">
                                    El Usuario en este momento acepta, reconoce y manifiesta su total
                                    conformidad con lo establecido en los presentes Términos y Condiciones.
                                    En caso de que el Usuario no esté de acuerdo con el contenido de los
                                    mismos, deberá abstenerse de usar la Plataforma, así como abstenerse de
                                    la navegación en cualquiera de los apartados que lo integran, debiendo
                                    cerrar de su navegador la página correspondiente a la Plataforma.
                                    Adicionalmente el Usuario se obliga a: (i) cumplir con las leyes
                                    aplicables a la transmisión de cualquier tipo de datos obtenidos del
                                    Servicio (ver <Link href={route('privacy-policy')} className="font-semibold">Aviso de Privacidad</Link>) de acuerdo a los Términos y
                                    Condiciones; (ii) no utilizar el Servicio con propósitos ilegales; y
                                    (iii) no interferir o intervenir redes conectadas al Servicio.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">USUARIO</h2>
                                <p className="text-justify">
                                    Cuando se emplea el vocablo "Usuario" se hace referencia a cualquier
                                    persona que acceda, por sus propios derechos o bien en representación o
                                    petición de alguien más, con el fin de navegar en la Plataforma, así
                                    como, enunciativa más no limitativamente, acceder al contenido del
                                    Sitio, celebrar contratos para adquirir los productos y/o servicios
                                    ofrecidos por FAMEDIC o terceros por medio de la Plataforma, acceder a
                                    las herramientas que conforman el mismo, a fin de realizar las
                                    actividades señaladas dentro del primer párrafo de éstos Términos y
                                    Condiciones.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">CUENTA DEL USUARIO Y REGISTRO A LA PLATAFORMA</h2>
                                <p className="text-justify">
                                    FAMEDIC ofrece libre acceso al público en general a su Plataforma, no
                                    obstante, podrá limitar acceso a ciertas ligas o enlaces internos y/o
                                    externos a ciertos Usuarios, en el entendido que, para tener acceso a
                                    todas las funcionalidades, acceso a cierta información y la adquisición
                                    de productos y/o servicios, el Usuario deberá agotar el proceso de
                                    registro para la creación de un usuario. FAMEDIC podrá solicitar para la
                                    creación de la cuenta de usuario, así como para el acceso a ciertas
                                    funcionalidades y adquisición de productos y/o servicios, enunciativa
                                    más no limitativamente: (i) información general para la identificación
                                    del Usuario, (ii) datos de localización del Usuario, (iii) cierta
                                    información de registro del Usuario ante autoridades gubernamentales
                                    administrivas (como lo son el RFC, CURP, y/o equivalentes del país de
                                    origen del Usuario), (iv) la creación de una contraseña para el ingreso
                                    del Usuario a la Plataforma.
                                </p>
                                <p className="text-justify">
                                    La información solicitada por FAMEDIC al Usuario podrá variar según lo
                                    exijan los productos y/o servicios que pretenda contratar el Usuario y
                                    por consecuencia, FAMEDIC podrá, a su propia discreción, solicitar
                                    información adicional. Aunado a lo anterior, FAMEDIC podrá solicitar
                                    cierta información financiera y/o bancaria, para que FAMEDIC realice el
                                    cobro al Usuario en caso de aplicar. Por este medio, el Usuario da su
                                    consentimiento expreso y autoriza a FAMEDIC para realizar los cobros que
                                    autorice el Usuario por medio de la Plataforma.
                                </p>
                                <p className="text-justify">
                                    El Usuario será el único responsable de mantener en resguardo los medios
                                    de acceso, códigos, claves o contraseñas para tener acceso a la
                                    Plataforma. El Usuario será responsable de que todos los datos que le
                                    proporcione a FAMEDIC sean correctos.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">INTERCAMBIO Y REGISTRO DE INFORMACIÓN</h2>
                                <p className="text-justify">
                                    La Plataforma permitirá el intercambio de información entre el Usuario y
                                    FAMEDIC, a través de ciertas publicaciones en la misma y en su caso
                                    envío, digital o físicamente, de documentación, ofertas, descuentos,
                                    banners y demás respecto de los productos y/o servicios ofrecidos por
                                    FAMEDIC y/o terceros ofrecidos por medio de la Plataforma. Para que el
                                    Usuario pueda adquirir dichos productos y/o servicios, deberá llenar
                                    efectivamente ciertos formularios de acuerdo al proceso indicado dentro
                                    de la propia Plataforma, así como a lo establecido en los presentes
                                    Términos y Condiciones.
                                </p>
                                <p className="text-justify">
                                    El Usuario reconoce y acepta que FAMEDIC tendrá en todo momento el
                                    derecho de negar restringir, suspender, cancelar o condicionar el acceso
                                    o utilización de la Plataforma total o parcialmente, de forma temporal o
                                    definitiva a su entera discreción.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">REQUISITOS DE USO</h2>
                                <p className="text-justify">
                                    El Usuario deberá acceder a través de equipos, medios ópticos o de
                                    cualquier otra tecnología, sistemas automatizados de procesamiento de
                                    datos y redes de telecomunicaciones, que cuenten con acceso a internet
                                    seguro y confiable.
                                </p>
                                <p className="text-justify">
                                    FAMEDIC no será responsable por la seguridad de los equipos utilizados
                                    por el Usuario para el acceso a la Plataforma, ni por la disponibilidad
                                    del servicio de internet seguro en los dispositivos a través de los
                                    cuales tenga acceso a ésta, ni tampoco será responsable por daños o
                                    afectaciones que el Usuario pueda sufrir durante el uso del Portal por
                                    virus (malware por su traducción en inglés).
                                </p>
                                <p className="text-justify">
                                    Así mismo, el Usuario reconoce que FAMEDIC no se hace responsable en
                                    caso de que acceda a la Plataforma fuera de zona con conexión a
                                    internet, y que derivado de ello se apliquen a su cargo costos por los
                                    datos consumidos al acceder a dicha Plataforma. En virtud de lo
                                    anterior, el Usuario será responsable de cubrir los cargos que en su
                                    caso se generen por estos u otros conceptos de naturaleza análoga.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">SERVICIOS Y OBJETO</h2>
                                
                                <div className="ml-4 space-y-6">
                                    <div>
                                        <h3 className="text-xl font-semibold text-famedic-light">Contenidos</h3>
                                        <p className="text-justify">
                                            La información contenida en esta Plataforma ha sido elaborada y
                                            publicada por FAMEDIC con la finalidad de proporcionar información
                                            general al Usuario. FAMEDIC se reserva el derecho de actualizar,
                                            cambiar, modificar o eliminar tanto la información como los servicios,
                                            contenidos y configuración de la Plataforma, en cualquier momento y sin
                                            previo aviso. Por el uso de la Plataforma, el usuario reconoce y acepta
                                            que FAMEDIC tiene entera libertad de ampliar, interrumpir, desactivar,
                                            limitar, restringir, cancelar o anular el acceso, disponibilidad y
                                            operatividad del contenido de la Plataforma o de cualquiera de los
                                            productos y/o servicios que se integran en la misma.
                                        </p>
                                        <p className="text-justify">
                                            La información contenida en las páginas y documentos que FAMEDIC pone a
                                            su disposición ha sido obtenida de fuentes que se consideran fidedignas;
                                            sin embargo, FAMEDIC no garantiza su veracidad.
                                        </p>
                                        <p className="text-justify">
                                            FAMEDIC no será responsable de cualquier daño o perjuicio que sufra el
                                            Usuario a consecuencia de demora, inexactitudes, errores u omisiones de
                                            cualquier índole, cambios actualización, reparaciones o mejoras a los
                                            servicios y contenidos, o de los resultados obtenidos por la utilización
                                            de la información contenida en la Plataforma. Aunado a lo anterior,
                                            FAMEDIC no será responsable por errores en el contenido de la
                                            información que los terceros que ofrezcan servicios a través de la
                                            Plataforma envíen o ingresen al Sistema, tales como servicios,
                                            productos, precios, etc., así como por inexactitud en nombres, fechas,
                                            cantidades, cifras, montos, etc.
                                        </p>
                                    </div>

                                    <div>
                                        <h3 className="text-xl font-semibold text-famedic-light">Productos y Servicios ofrecidos por FAMEDIC.</h3>
                                        <p className="text-justify">
                                            FAMEDIC no es una entidad financiera ni presta a los Usuarios servicios
                                            financieros, bancarios o cambiarios y así mismo los servicios referidos
                                            en los presentes Términos y Condiciones no tienen dicha calidad, no
                                            obstante ciertos terceros podrán ofrecer dichos servicios, por lo que el
                                            Usuario y los terceros deberán sujetarse a lo estipulado en el apartado
                                            c) de la presente Cláusula y a los presentes Términos y Condiciones.
                                        </p>
                                        <p className="text-justify">
                                            FAMEDIC podrá, de tiempo en tiempo, ofrecer, enunciativa más no
                                            limitativamente, ciertos productos, servicios, ofertas, descuentos
                                            propios de FAMEDIC para ser adquiridos por los Usuarios, así como poner
                                            a disposición del Usuario ciertos productos y servicios ofrecidos por
                                            terceros por medio de la Plataforma. Los servicios que prestará FAMEDIC
                                            versan sobre: Proporcionar a los Usuarios: (i) un número de usuario y
                                            contraseña individualizada; y (ii) la funcionalidad para que puedan
                                            ingresar a su cuenta individual y realizar por medio de la Plataforma
                                            compras y/o contrataciones de diversos servicios y productos médicos
                                            ofrecidos por terceros, por lo que el Usuario deberá adherirse a los
                                            términos y condiciones del tercero.
                                        </p>
                                        <p className="text-justify">
                                            Todas las ofertas que FAMEDIC publique en su Plataforma están sujetas a
                                            cambios y/o modificaciones a discreción de FAMEDIC, según lo exija la
                                            demanda. Aunado a lo anterior, dichos productos y/o servicios están
                                            sujetas a disponibilidad, por lo que podrá removerse de la Plataforma.
                                        </p>
                                        <p className="text-justify">
                                            El Usuario reconoce que las instrucciones que transmita a través de los
                                            servicios proporcionados por la Plataforma o cualquier otro medio, las
                                            hará con pleno conocimiento de los riesgos que implican las operaciones
                                            que ordena y sin necesidad de haber obtenido previa asesoría de FAMEDIC
                                            respecto de cada operación. Con base en lo anterior, el Usuario libera,
                                            de manera absoluta y sin limitación alguna, a FAMEDIC de toda
                                            responsabilidad que pudiere derivar de los resultados que tengan las
                                            operaciones que el Usuario ordene a través de los servicios
                                            proporcionados por medio de la Plataforma.
                                        </p>
                                    </div>

                                    <div>
                                        <h3 className="text-xl font-semibold text-famedic-light">Servicios ofrecidos por terceros en la Plataforma de FAMEDIC</h3>
                                        <p className="text-justify">
                                            En el intercambio de información, FAMEDIC podá ofrecer por medio de la
                                            Plataforma, ciertos productos y servicios, enunciativa más no
                                            limitativamente, servicios y productos médicos.
                                        </p>
                                        <p className="text-justify">
                                            El Usuario acepta que dicha información y ofertas contenidas en la
                                            plataforma de FAMEDIC, son realizadas por terceros, por lo que el
                                            intercambio de propuestas y aceptación de las mismas, comercialización,
                                            y asesoramiento para celebrarlos o modificarlos, se tendrá que realizar
                                            entre el Usuario y el tercero por medio de la Plataforma, o bien, por
                                            los medios de comunicación que establezca el tercero o de conformidad
                                            con lo estipulado en los Términos y Condiciones y Aviso de Privacidad
                                            del tercero quién presta el servicio por medio de la Plataforma de
                                            FAMEDIC. FAMEDIC en estos casos, fungirá como intermediario poniendo
                                            dichos servicios a disposición del Usuario, no obstante, FAMEDIC se
                                            asegurará de que el tercero que ofrezca los Servicios cuente con todos
                                            los permisos y/o certificaciones aplicables.
                                        </p>
                                        <p className="text-justify">
                                            FAMEDIC podrá, de tiempo en tiempo, ofrecer ligas a otros sitios o
                                            páginas Web desde la Plataforma que pudieran ser del interés del
                                            Usuario, en los cuales FAMEDIC podrá exponer enlaces, iconos y/o marcas
                                            de diversos proveedores independientes, cuyo material ha sido
                                            desarrollado y es mantenido por ellos mismos, por lo que no podrá
                                            considerarse que existe algún tipo de asociación entre FAMEDIC y dichos
                                            terceros, por lo cual FAMEDIC no será responsable por los cambios en los
                                            enlaces o sus direcciones, así como tampoco por la actualización,
                                            calidad, veracidad, integridad y consistencia de la información o la
                                            disponibilidad y funcionamiento de dichos sitios o respecto a cualquier
                                            acto, omisión o garantía de cualquier tipo relacionada con los mismos.
                                            FAMEDIC de ninguna manera respalda, y no tendrá obligación o
                                            responsabilidad alguna respecto a la entrega o no de los respectivos
                                            servicios o contenidos de tales proveedores o de la precisión,
                                            totalidad, calidad o puntualidad de los mismos.
                                        </p>
                                        <p className="text-justify">
                                            Respecto de los servicios y contenidos que presentan terceros dentro de
                                            la Plataforma, la función de FAMEDIC se limita exclusivamente, para
                                            conveniencia del Usuario, a proporcionar un medio para poner en contacto
                                            al Usuario con proveedores de servicios relacionados con el giro pero
                                            independientes de FAMEDIC. Los servicios o productos de terceros que se
                                            comercializan dentro de la Plataforma y/o en los sitios de terceros
                                            entrelazados son suministrados por comerciantes independientes a
                                            FAMEDIC. FAMEDIC no es ni podrá ser considerado como proveedor de los
                                            bienes y servicios que se ofrecen en dichas páginas y/o sitios. La
                                            inclusión de dichas páginas y/o enlaces no implica la aprobación,
                                            respaldo, patrocinio, recomendación o garantía, por parte de FAMEDIC, de
                                            los servicios y bienes que se comercializan en los mismos, ni del
                                            contenido de dichas páginas. No existe ningún tipo de relación laboral,
                                            asociación o sociedad, entre FAMEDIC y dichos terceros.
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">PAGOS</h2>
                                <p className="text-justify">
                                    El Usuario únicamente podrá realizar instrucciones de pago a través de
                                    la Plataforma. Para estos efectos, FAMEDIC no almacena directamente los
                                    datos sensibles de las tarjetas bancarias de los usuarios. Los datos
                                    relacionados con tarjetas de crédito o débito no son tratados
                                    directamente por FAMEDIC. Los datos en el registro de un método de pago
                                    son enviados a través de canales seguros a los procesadores de pago
                                    autorizados como lo son: Stripe, Inc. y Efevoo Pay, S.A.P.I. de C.V.,
                                    quienes cuentan con certificación PCI DSS.
                                </p>
                                <p className="text-justify">
                                    FAMEDIC únicamente conserva un token encriptado generado por dichos
                                    procesadores, el cual NO contiene datos sensibles, NO puede ser
                                    reutilizado fuera del entorno del procesador y NO representa ningún
                                    riesgo de exposición financiera para el usuario. Dicho token permite
                                    identificar y vincular el método de pago del usuario para futuras
                                    transacciones autorizadas. Toda transacción, ya sea única o recurrente,
                                    será ejecutada única y exclusivamente con el consentimiento expreso del
                                    usuario, quien podrá gestionar los datos de sus métodos de pago desde su
                                    cuenta en la plataforma.
                                </p>
                                <p className="text-justify">
                                    No serán válidas las Operaciones realizadas por teléfono, fax, correo
                                    electrónico o cualquier otro medio ajeno a la Plataforma. Para realizar
                                    cualquier tipo de Operación, el Usuario debe contar con fondos
                                    suficientes y disponibles en su cuenta (bancaria o de caja de ahorro de
                                    ODESSA) para poder efectuar Operaciones. En caso de que el Usuario no
                                    tenga fondos suficientes, FAMEDIC no procesará instrucciones de pago.
                                </p>
                                <p className="text-justify">
                                    El Usuario podrá realizar Operaciones en la Plataforma por medio de su
                                    cuenta bancaria o por medio de los fondos disponibles en su caja de
                                    ahorro de ODESSA. Cuando el Usuario realice Operaciones por medio de
                                    transferencia bancaria, ésta deberá realizarse directamente por medio de
                                    la Plataforma de FAMEDIC. Para realizar las Operaciones por medio de
                                    transferencia bancaria, FAMEDIC solicitará al Usuario que proporcione
                                    ciertos datos de la cuenta bancaria del Usuario a FAMEDIC, únicamente
                                    para que FAMEDIC pueda hacerse el cobro de la Operación instruida por el
                                    Usuario por medio de la Plataforma de FAMEDIC y dar cumplimiento al
                                    objeto de los presentes Términos y Condiciones. FAMEDIC podrá utilizar
                                    diversas plataformas de pago de terceros para realizar el cobro de la
                                    transferencia, por lo que el Usuario acepta que se adhiere a los
                                    términos y condiciones del tercero para la realización del pago. Aunado
                                    a lo anterior, cualquier conflicto que tenga el Usuario con su cuenta
                                    bancaria deberá revisarse directamente con la institución bancaria.
                                </p>
                                <p className="text-justify">
                                    Aunado a lo anterior, para que el Usuario realice Operaciones con su
                                    caja de ahorro de ODESSA, dicha caja de ahorro debe estar debidamente
                                    registrada y suscrita en la Plataforma de FAMEDIC. Para que el Usuario
                                    pueda realizar Operaciones con los fondos disponibles de su caja de
                                    ahorro de ODESSA deberá seleccionar la opción de pago por medio de la
                                    Plataforma.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">RESTRICCIONES EN EL USO</h2>
                                <p className="text-justify">
                                    El Usuario no podrá usar el Servicio con un propósito ilegal o que de
                                    alguna manera sea inconsistente con los Términos y Condiciones. El
                                    Usuario acuerda usar el Servicio exclusivamente para el uso y beneficio
                                    personal o de su organización, y no para reventa u otro tipo de
                                    transferencia. El Usuario acuerda no usar, transferir, distribuir o
                                    disponer de cualquier información contenida en el Servicio de cualquier
                                    manera que pudiera competir con el negocio de FAMEDIC. El Usuario
                                    reconoce que el Servicio ha sido desarrollado, compilado, preparado,
                                    revisado, seleccionado y estructurado por FAMEDIC y otros, a través de
                                    métodos y criterios cuyo desarrollo e implementación ha significado una
                                    gran inversión en términos de tiempo, esfuerzo y dinero y constituye
                                    propiedad intelectual valiosa y secretos comerciales de FAMEDIC y dichos
                                    otros. El Usuario acuerda proteger los derechos de FAMEDIC y de
                                    cualquier tercero que tenga derechos en el Servicio, durante y después
                                    del período de vigencia de este acuerdo, y cumplir con todos aquellos
                                    requerimientos razonables y por escrito que FAMEDIC o sus proveedores de
                                    contenidos, equipos u otros puedan hacerle con el objeto de proteger sus
                                    derechos en el Servicio, tanto contractuales como legales. El Usuario
                                    acuerda notificar a FAMEDIC por escrito tan pronto tome conocimiento de
                                    cualquier acceso o uso no autorizado del Servicio o de cualquier reclamo
                                    que señale que el Servicio infringe cualquier ley de propiedad
                                    intelectual o industrial u otra, o cualquier otro derecho de terceros.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">AVISO DE PRIVACIDAD</h2>
                                <p className="text-justify">
                                    Grupo Famedic, S.A. de C.V., con domicilio convencional en Avenida José
                                    Clemente Orozco número 335, Interior 202, Colonia Valle Oriente, C.P.
                                    66269, San Pedro Garza García, Nuevo León, México, hace del conocimiento
                                    del Usuario que podrá compartir la información del Usuario con
                                    proveedores, instituciones y cualquier integrante del grupo corporativo
                                    de Grupo Famedic, S.A. de C.V., para dar cumplimiento a todo lo
                                    estipulado en los presentes Términos y Condiciones, por lo que FAMEDIC
                                    pone a disposición del Usuario el Aviso de Privacidad que podrá
                                    encontrarse en la Plataforma y en el siguiente sitio web{" "}
                                    <Link 
                                        href="https://www.ods.com.mx/extranet2/avisodeprivacidad.php" 
                                        className="font-semibold break-all"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        https://www.ods.com.mx/extranet2/avisodeprivacidad.php
                                    </Link>
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">LICENCIA</h2>
                                <p className="text-justify">
                                    El Usuario no adquiere ningún derecho o licencia sobre el Servicio, y
                                    los materiales contenidos en él, distinto del derecho de uso limitado
                                    del Servicio de acuerdo a los Términos y Condiciones.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">SIGNOS DISTINTIVOS</h2>
                                <p className="text-justify">
                                    Todos los signos distintivos registrados utilizados dentro de la
                                    Plataforma son propiedad de FAMEDIC, o bien cuenta con los permisos y/o
                                    licencias necesarias para el uso de los mismos, en virtud de ello el
                                    Usuario acepta y reconoce que no está autorizado ni legitimado de forma
                                    alguna para utilizar ni explotar las marcas comerciales, logos, diseños,
                                    y demás conceptos análogos, de FAMEDIC, por lo que no podrá divulgar,
                                    reproducir el contenido de la Plataforma ni podrán ser empleado para
                                    fines distintos a los permitidos en estos Términos y Condiciones y a los
                                    estrictamente relacionados con el fin del uso de la Plataforma, ni
                                    siquiera sin fines de lucro.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">PROPIEDAD INDUSTRIAL</h2>
                                <p className="text-justify">
                                    Los derechos de la propiedad intelectual respecto al contenido de la
                                    Plataforma, los signos distintivos, su código fuente, así como los
                                    derechos de uso y explotación de los mismos, incluyendo su divulgación,
                                    publicación, reproducción distribución y transformación, son propiedad
                                    exclusiva de FAMEDIC. En virtud de lo anterior el Usuario reconoce que
                                    no podrá divulgar, publicar, reproducir, distribuir, transformar o
                                    disponer de ningún modo, el dominio propiedad de FAMEDIC, ni de
                                    cualquier material que sea resultado de la Propiedad Intelectual de éste
                                    último.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">LIMITACIÓN DE RESPONSABILIDAD</h2>
                                <p className="text-justify">
                                    Bajo ninguna circunstancia, incluyendo pero no limitado a negligencia,
                                    FAMEDIC, sus proveedores o sus agentes terceros serán responsables
                                    frente al Usuario o frente a un tercero por daños directos, indirectos,
                                    incidentales, incluyendo daño emergente y lucro cesante,
                                    consecuenciales, punitivos o ejemplificadores, aun en el caso que un
                                    representante autorizado de FAMEDIC hubiera sido notificado
                                    específicamente sobre la posibilidad de dichos daños, que provengan por
                                    el uso o la inhabilidad en el uso del servicio, links o Ítems del
                                    servicio o de cualquier estipulación de los Términos y Condiciones,
                                    tales como, pero sin limitarse a, pérdida de ingresos o ingresos
                                    anticipados o negocios perdidos. (La ley aplicable podría no permitir la
                                    limitación o exclusión de responsabilidad o daños incidentales o
                                    consecuenciales).
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">DECLARACIONES Y GARANTÍAS</h2>
                                <p className="text-justify">
                                    El Usuario declara, garantiza y asegura que: (i) tiene la capacidad de
                                    celebrar el presente acuerdo; (ii) tiene por lo menos 18 años de edad;
                                    (iii) no usará ninguno de los derechos otorgados en este documento para
                                    ningún propósito ilegal; y (iv) utilizará el Servicio sólo como está
                                    indicado en estos Términos y Condiciones.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">MODIFICACIONES</h2>
                                <p className="text-justify">
                                    FAMEDIC podrá en cualquier momento modificar los Términos y Condiciones
                                    establecidos en el presente documento. En consecuencia, el Usuario
                                    reconoce y acepta leer atentamente los Términos y Condiciones cada vez
                                    que desee utilizar la Plataforma.
                                </p>
                            </section>

                            <section>
                                <h2 className="text-2xl font-semibold">LEY APLICABLE</h2>
                                <p className="text-justify">
                                    La resolución de cualquier conflicto derivado de los presentes Términos
                                    y Condiciones, así como de cualquier operación que se derive por la
                                    celebración de cualquier contenido de la Plataforma de FAMEDIC, deberá
                                    ser resuelta ante la jurisdicción aplicable de la ciudad de Monterrey,
                                    Nuevo León, México. En caso de que el Usuario y FAMEDIC celebren un
                                    instrumento jurídico por aparte, prevalecerá lo estipulado en dicho
                                    instrumento.
                                </p>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </FamedicLayout>
    );
}