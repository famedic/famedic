import { Anchor, Text } from "@/Components/Catalyst/text";

/**
 * Componente reutilizable para mostrar el acuerdo de términos y condiciones
 */
export default function TermsAgreement({ className = "" }) {
    return (
        <div className={`rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800 ${className}`}>
            <Text className="text-sm">
                Al hacer clic en el botón "Registrar", aceptas todos los{" "}
                <Anchor
                    href={route("terms-of-service")}
                    target="_blank"
                    className="font-semibold underline hover:no-underline"
                >
                    Términos y condiciones de servicio
                </Anchor>{" "}
                y la{" "}
                <Anchor
                    href={route("privacy-policy")}
                    target="_blank"
                    className="font-semibold underline hover:no-underline"
                >
                    Política de privacidad
                </Anchor>
                .
            </Text>
        </div>
    );
}