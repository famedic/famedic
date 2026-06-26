import Card from "@/Components/Card";
import { Text } from "@/Components/Catalyst/text";
import {
	ChatBubbleLeftRightIcon,
	VideoCameraIcon,
	SparklesIcon,
	ClockIcon,
} from "@heroicons/react/24/outline";

function UsageStat({ icon: Icon, label, value }) {
	return (
		<div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/50">
			<div className="mb-3 flex size-9 items-center justify-center rounded-xl bg-white text-famedic-dark shadow-sm dark:bg-slate-900 dark:text-sky-300">
				<Icon className="size-5" />
			</div>
			<p className="font-poppins text-2xl font-bold text-famedic-dark dark:text-white">
				{value ?? "—"}
			</p>
			<Text className="text-sm text-zinc-500">{label}</Text>
		</div>
	);
}

export default function MembershipUsage({ usage }) {
	return (
		<section className="space-y-4">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Uso de la membresía
				</h3>
				<Text className="text-sm text-zinc-500">
					Resumen de cómo has utilizado tus beneficios.
				</Text>
			</div>

			<Card className="p-6 shadow-sm ring-1 ring-slate-100 sm:p-8">
				{usage?.available ? (
					<>
						<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
							<UsageStat
								icon={ChatBubbleLeftRightIcon}
								label="Consultas realizadas"
								value={usage.consultations}
							/>
							<UsageStat
								icon={SparklesIcon}
								label="Psicología"
								value={usage.psychology}
							/>
							<UsageStat
								icon={SparklesIcon}
								label="Nutrición"
								value={usage.nutrition}
							/>
							<UsageStat
								icon={VideoCameraIcon}
								label="Videollamadas"
								value={usage.videoCalls}
							/>
						</div>

						{usage.lastUsedLabel && (
							<div className="mt-6 flex items-center gap-2 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/50">
								<ClockIcon className="size-4 text-zinc-400" />
								<Text className="text-sm text-zinc-600 dark:text-slate-300">
									Último uso: {usage.lastUsedLabel}
								</Text>
							</div>
						)}
					</>
				) : (
					<div className="rounded-2xl border border-dashed border-slate-200 px-6 py-10 text-center dark:border-slate-700">
						<Text className="text-sm text-zinc-500">
							Próximamente podrás ver aquí tus estadísticas de
							uso: consultas, psicología, nutrición y
							videollamadas.
						</Text>
					</div>
				)}
			</Card>
		</section>
	);
}
