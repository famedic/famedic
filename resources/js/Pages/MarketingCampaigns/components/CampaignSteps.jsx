import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

const STEPS = [
	["1", "Elige tus estudios", "Agrega uno o varios estudios al carrito."],
	["2", "Completa tus datos", "Registra la información del paciente."],
	["3", "Agenda o acude", "Continúa según las indicaciones del laboratorio."],
];

export default function CampaignSteps() {
	return (
		<section className="space-y-4">
			<Subheading>Compra en tres pasos</Subheading>
			<div className="grid gap-4 md:grid-cols-3">
				{STEPS.map(([number, title, description]) => (
					<div key={number} className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
						<div className="flex size-8 items-center justify-center rounded-full bg-famedic-lime font-semibold text-famedic-dark">
							{number}
						</div>
						<Text className="mt-3 font-semibold">{title}</Text>
						<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{description}</Text>
					</div>
				))}
			</div>
		</section>
	);
}
