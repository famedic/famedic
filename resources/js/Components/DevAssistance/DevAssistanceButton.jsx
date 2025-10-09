import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
} from "@/Components/Catalyst/dialog";
import { CommandLineIcon } from "@heroicons/react/16/solid";
import DevAssistanceComposer from "@/Components/DevAssistance/DevAssistanceComposer";

export function CreateDevAssistanceDialogContent({ storeRoute, onSuccess }) {
	return (
		<>
			<DialogTitle>Solicitar asistencia técnica</DialogTitle>
			<DialogDescription>
				Describe detalladamente el problema, error o situación que estás
				experimentando. Incluye pasos para reproducir el problema,
				mensajes de error específicos, y cualquier información adicional
				que pueda ayudar al equipo de desarrollo.
			</DialogDescription>
			<DialogBody className="space-y-6">
				<DevAssistanceComposer
					route={storeRoute}
					onSuccess={onSuccess}
				/>
			</DialogBody>
		</>
	);
}

export default function DevAssistanceButton({ storeRoute, className = "" }) {
	const [isOpen, setIsOpen] = useState(false);

	return (
		<>
			<Button
				outline
				onClick={() => setIsOpen(true)}
				className={className}
			>
				<CommandLineIcon />
				Solicitar asistencia técnica
			</Button>

			<Dialog open={isOpen} onClose={() => setIsOpen(false)}>
				<CreateDevAssistanceDialogContent
					storeRoute={storeRoute}
					onSuccess={() => setIsOpen(false)}
				/>
			</Dialog>
		</>
	);
}
