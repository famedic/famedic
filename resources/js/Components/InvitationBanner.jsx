import { Text } from "@/Components/Catalyst/text";

/**
 * Banner para mostrar información del invitador
 */
export default function InvitationBanner({ inviter }) {
    if (!inviter) return null;

    return (
        <div className="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-900/20">
            <Text className="text-center">
                <span className="mr-2 text-lg">🎉</span>
                {inviter.name && inviter.name !== "Usuario" ? (
                    <>
                        <strong className="font-semibold">{inviter.name}</strong> te ha invitado a
                        unirte y disfrutar los beneficios de Famedic!
                    </>
                ) : (
                    <>
                        Te han invitado a unirte y disfrutar los
                        beneficios de Famedic!
                    </>
                )}
            </Text>
        </div>
    );
}