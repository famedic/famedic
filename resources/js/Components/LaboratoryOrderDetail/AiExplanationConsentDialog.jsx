import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

export default function AiExplanationConsentDialog({
	isOpen,
	onClose,
	onAccept,
	isSubmitting = false,
}) {
	return (
		<Dialog open={isOpen} onClose={onClose} size="md">
			<DialogTitle>Entender mis resultados con ayuda de IA</DialogTitle>
			<DialogDescription>
				Información sobre el uso de inteligencia artificial para explicar resultados de laboratorio.
			</DialogDescription>
			<DialogBody className="space-y-3">
				<Text className="text-sm text-zinc-700 dark:text-slate-300">
					Puedes utilizar una explicación generada por inteligencia artificial para comprender mejor
					algunos datos de tus resultados de laboratorio.
				</Text>
				<Text className="text-sm text-zinc-700 dark:text-slate-300">
					La explicación es informativa y no sustituye la valoración de un profesional de la salud.
				</Text>
				<Text className="text-sm text-zinc-600 dark:text-slate-400">
					Al continuar, aceptas el uso de los datos mínimos de este resultado para generar esta
					explicación.
				</Text>
			</DialogBody>
			<DialogActions>
				<Button plain type="button" onClick={onClose} disabled={isSubmitting}>
					Ahora no
				</Button>
				<Button color="famedic-lime" type="button" onClick={onAccept} disabled={isSubmitting}>
					{isSubmitting ? "Procesando..." : "Continuar"}
				</Button>
			</DialogActions>
		</Dialog>
	);
}
