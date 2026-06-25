import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { SparklesIcon } from "@heroicons/react/24/outline";

export default function ChatHeader({ isConfigured }) {
	return (
		<header className="shrink-0 border-b border-zinc-200/80 bg-white/80 px-4 py-4 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-950/80 sm:px-6">
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div className="flex items-start gap-3">
					<div className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-famedic-dark to-famedic-light text-white shadow-sm">
						<SparklesIcon className="size-5" />
					</div>
					<div>
						<h1 className="font-poppins text-lg font-semibold text-zinc-950 dark:text-white">
							Asistente IA de Monitoreo
						</h1>
						<Text className="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">
							Consulta usuarios, compras, carritos, fallas y actividad
							operativa.
						</Text>
						<Text className="mt-1 text-xs text-zinc-500">
							Modo interno · Datos operativos
						</Text>
					</div>
				</div>
				<Badge color={isConfigured ? "green" : "amber"}>
					{isConfigured ? "OpenAI configurado" : "OpenAI no configurado"}
				</Badge>
			</div>
		</header>
	);
}
