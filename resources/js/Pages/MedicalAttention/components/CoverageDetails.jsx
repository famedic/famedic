// resources/js/Pages/MedicalAttention/components/CoverageDetails.jsx
import { Subheading } from "@/Components/Catalyst/heading";
import { Strong, Text } from "@/Components/Catalyst/text";
import { CheckIcon, StarIcon } from "@heroicons/react/24/solid";
import { usePage } from "@inertiajs/react";
import { useEffect } from "react";

export default function CoverageDetails() {
    const hasOdessaAfiliateAccount = usePage().props.hasOdessaAfiliateAccount;

    // Logs de depuración
    useEffect(() => {
        if (true) {
            console.group('📋 COMPONENTE: CoverageDetails');
            console.log('📥 Datos del usePage:');
            console.log('  - hasOdessaAfiliateAccount:', hasOdessaAfiliateAccount);
            
            if (hasOdessaAfiliateAccount) {
                console.log('✨ Mostrando beneficios premium (con Odessa)');
            } else {
                console.log('🔹 Mostrando beneficios básicos');
            }
            console.groupEnd();
        }
    }, [hasOdessaAfiliateAccount]);

    return (
        <div className="space-y-6">
            <div>
                <Subheading className="flex items-center gap-2">
                    A QUIEN CUBRE
                    {hasOdessaAfiliateAccount && (
                        <StarIcon className="size-5 text-yellow-400" />
                    )}
                </Subheading>
                <ul>
                    <li className="flex items-center gap-x-2">
                        <CheckIcon className="size-4 min-w-4 stroke-green-200" />
                        <Text>Titular</Text>
                    </li>
                    <li className="flex items-center gap-x-2">
                        <CheckIcon className="size-4 min-w-4 stroke-green-200" />
                        <Text>Cónyuge</Text>
                    </li>
                    <li className="flex items-center gap-x-2">
                        <CheckIcon className="size-4 min-w-4 stroke-green-200" />
                        <Text>Hijos</Text>
                    </li>
                </ul>
            </div>
            
            <div className="space-y-2">
                <Subheading className="flex items-center gap-2">
                    QUE INCLUYE
                    {hasOdessaAfiliateAccount ? (
                        <span className="flex">
                            <StarIcon className="size-5 text-yellow-400" />
                            <StarIcon className="size-5 text-yellow-400" />
                            <StarIcon className="size-5 text-yellow-400" />
                        </span>
                    ) : (
                        <span className="flex">
                            <StarIcon className="size-5 text-blue-400" />
                            <StarIcon className="size-5 text-gray-300" />
                            <StarIcon className="size-5 text-gray-300" />
                        </span>
                    )}
                </Subheading>

                <ol className="list-inside list-decimal space-y-4 marker:text-famedic-light">
                    <li className={hasOdessaAfiliateAccount ? "opacity-100" : "opacity-100"}>
                        <Strong>
                            <span className="text-famedic-light">
                                Asistencia telemedicina ilimitadas 24/7
                            </span>
                        </Strong>
                        <ul className="list-inside list-disc marker:!text-famedic-dark">
                            <li>
                                <Text>
                                    Conecta al paciente con médicos generales a
                                    través de Videoconferencia y Chat 24/7
                                </Text>
                            </li>
                        </ul>
                    </li>

                    {hasOdessaAfiliateAccount ? (
                        // Beneficios Premium (con estrellas doradas)
                        <>
                            <li className="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded-lg border border-yellow-200 dark:border-yellow-800">
                                <Strong>
                                    <span className="text-yellow-600 dark:text-yellow-400 flex items-center gap-2">
                                        <StarIcon className="size-5 text-yellow-400" />
                                        Médico en casa hasta 3 veces al año
                                        <StarIcon className="size-5 text-yellow-400" />
                                    </span>
                                </Strong>
                                <ul className="list-inside list-disc marker:!text-yellow-600">
                                    <li>
                                        <Text>
                                            Consultas médicas a domicilio
                                        </Text>
                                    </li>
                                </ul>
                            </li>
                            <li className="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded-lg border border-yellow-200 dark:border-yellow-800">
                                <Strong>
                                    <span className="text-yellow-600 dark:text-yellow-400 flex items-center gap-2">
                                        <StarIcon className="size-5 text-yellow-400" />
                                        Ambulancia en emergencia hasta 1 evento al año
                                        <StarIcon className="size-5 text-yellow-400" />
                                    </span>
                                </Strong>
                                <ul className="list-inside list-disc marker:!text-yellow-600">
                                    <li>
                                        <Text>Ambulancia terrestre</Text>
                                    </li>
                                </ul>
                            </li>
                        </>
                    ) : (
                        // Beneficios Básicos (sin fondo especial)
                        <>
                            <li className="opacity-50">
                                <Strong>
                                    <span className="text-gray-400">
                                        Médico en casa (no incluido)
                                    </span>
                                </Strong>
                            </li>
                            <li className="opacity-50">
                                <Strong>
                                    <span className="text-gray-400">
                                        Ambulancia (no incluido)
                                    </span>
                                </Strong>
                            </li>
                        </>
                    )}
                    
                    <li>
                        <Strong>
                            <span className="text-famedic-light">
                                Asistencias telefónicas ilimitadas
                            </span>
                        </Strong>
                        <ul className="list-inside list-disc marker:!text-famedic-dark">
                            <li>
                                <Text>Psicológica</Text>
                            </li>
                            <li>
                                <Text>Nutricional</Text>
                            </li>
                            <li>
                                <Text>Legal</Text>
                            </li>
                        </ul>
                    </li>
                    
                    {hasOdessaAfiliateAccount && (
                        <li className="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded-lg border border-yellow-200 dark:border-yellow-800">
                            <Strong>
                                <span className="text-yellow-600 dark:text-yellow-400 flex items-center gap-2">
                                    <StarIcon className="size-5 text-yellow-400" />
                                    Reembolso de 3 medicamentos por familia por año
                                    <StarIcon className="size-5 text-yellow-400" />
                                </span>
                            </Strong>
                            <ul className="list-inside list-disc marker:!text-yellow-600">
                                <li>
                                    <Text>
                                        Hasta $350 pesos en cada evento
                                    </Text>
                                </li>
                                <li>
                                    <Text>
                                        Reembolso derivado de la consulta con el médico general (telemedicina)
                                    </Text>
                                </li>
                            </ul>
                        </li>
                    )}
                </ol>
            </div>
        </div>
    );
}