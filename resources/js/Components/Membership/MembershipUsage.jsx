import Card from "@/Components/Card";
import { Text } from "@/Components/Catalyst/text";
import {
	ChatBubbleLeftRightIcon,
	VideoCameraIcon,
	SparklesIcon,
	ClockIcon,
} from "@heroicons/react/24/outline";

function UsageStat({ icon: Icon, label, value, accent = "violet" }) {
	const accents = {
		violet: "bg-violet-50 text-violet-600",
		sky: "bg-sky-50 text-sky-600",
		emerald: "bg-emerald-50 text-emerald-600",
		amber: "bg-amber-50 text-amber-600",
	};

	return (
		<Card className="rounded-2xl p-4 shadow-sm ring-1 ring-slate-100">
			<div
				className={`mb-3 flex size-9 items-center justify-center rounded-xl ${accents[accent]}`}
			>
				<Icon className="size-5" />
			</div>
			<p className="font-poppins text-2xl font-bold text-famedic-dark dark:text-white">
				{value ?? "—"}
			</p>
			<Text className="text-sm text-zinc-500">{label}</Text>
		</Card>
	);
}

export default function MembershipUsage({ usage }) {
	const metrics = [
		{
			icon: ChatBubbleLeftRightIcon,
			label: "Consultas",
			value: usage?.consultations,
			accent: "violet",
		},
		{
			icon: SparklesIcon,
			label: "Psicología",
			value: usage?.psychology,
			accent: "sky",
		},
		{
			icon: SparklesIcon,
			label: "Nutrición",
			value: usage?.nutrition,
			accent: "emerald",
		},
		{
			icon: VideoCameraIcon,
			label: "Videollamadas",
			value: usage?.videoCalls,
			accent: "amber",
		},
	];

	return (
		<div className="space-y-6">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Uso y beneficios
				</h3>
				<Text className="text-sm text-zinc-500">
					Métricas de cómo has utilizado tu membresía.
				</Text>
			</div>

			{usage?.available ? (
				<>
					<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
						{metrics.map((metric) => (
							<UsageStat key={metric.label} {...metric} />
						))}
					</div>

					<Card className="rounded-2xl p-5 ring-1 ring-slate-100">
						<div className="flex items-center justify-between gap-4">
							<div>
								<Text className="text-sm text-zinc-500">
									Telemedicina
								</Text>
								<p className="font-poppins text-xl font-semibold text-famedic-dark dark:text-white">
									{usage?.videoCalls ?? "—"} sesiones
								</p>
							</div>
							{usage?.lastUsedLabel && (
								<div className="flex items-center gap-2 text-sm text-zinc-500">
									<ClockIcon className="size-4" />
									Último uso: {usage.lastUsedLabel}
								</div>
							)}
						</div>
					</Card>
				</>
			) : (
				<Card className="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center ring-0">
					<div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-600">
						<SparklesIcon className="size-6" />
					</div>
					<p className="mt-4 font-medium text-zinc-800 dark:text-slate-100">
						Estadísticas próximamente
					</p>
					<Text className="mx-auto mt-2 max-w-md text-sm text-zinc-500">
						Pronto podrás ver consultas, psicología, nutrición y
						videollamadas en un dashboard con indicadores y gráficas.
					</Text>
				</Card>
			)}
		</div>
	);
}
