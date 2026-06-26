import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import {
	HeartIcon,
	ClockIcon,
	UsersIcon,
	VideoCameraIcon,
	CheckIcon,
} from "@heroicons/react/24/outline";
import { SparklesIcon } from "@heroicons/react/24/solid";

const ICON_MAP = {
	heart: HeartIcon,
	clock: ClockIcon,
	brain: SparklesIcon,
	nutrition: SparklesIcon,
	family: UsersIcon,
	video: VideoCameraIcon,
};

function PlanRow({ label, value, children }) {
	return (
		<div className="flex items-start justify-between gap-4 border-b border-slate-100 py-3 last:border-b-0 dark:border-slate-800">
			<Text className="text-sm text-zinc-500">{label}</Text>
			<div className="text-right text-sm font-medium text-zinc-800 dark:text-slate-100">
				{children ?? value ?? "—"}
			</div>
		</div>
	);
}

export default function MembershipPlan({ plan, benefits = [] }) {
	if (!plan) {
		return (
			<Card className="rounded-2xl p-6 ring-1 ring-slate-100">
				<Text className="text-sm text-zinc-500">
					No hay información del plan disponible.
				</Text>
			</Card>
		);
	}

	const statusColor =
		plan.status === "Pagado"
			? "emerald"
			: plan.status === "Pendiente"
				? "amber"
				: "sky";

	return (
		<div className="space-y-6">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Detalles del plan
				</h3>
				<Text className="text-sm text-zinc-500">
					Información completa de tu membresía.
				</Text>
			</div>

			<Card className="rounded-2xl p-6 ring-1 ring-slate-100 sm:p-8">
				<div className="divide-y divide-slate-100 dark:divide-slate-800">
					<PlanRow label="Plan" value={plan.name} />
					<PlanRow label="Precio" value={plan.price} />
					<PlanRow label="Tipo" value={plan.paymentType} />
					<PlanRow label="Renovación" value={plan.renewalDate} />
					<PlanRow label="Estado">
						<Badge color={statusColor}>{plan.status}</Badge>
					</PlanRow>
					<PlanRow
						label="Vigencia"
						value={
							plan.startDate && plan.endDate
								? `${plan.startDate} — ${plan.endDate}`
								: "—"
						}
					/>
					<PlanRow label="Proveedor" value={plan.provider} />
				</div>
			</Card>

			{benefits.length > 0 && (
				<div className="space-y-4">
					<h4 className="font-poppins font-semibold text-famedic-dark dark:text-white">
						Beneficios incluidos
					</h4>
					<div className="grid gap-3 sm:grid-cols-2">
						{benefits.map((benefit) => {
							const Icon = ICON_MAP[benefit.icon] ?? CheckIcon;

							return (
								<Card
									key={benefit.title}
									className="rounded-2xl p-4 ring-1 ring-slate-100"
								>
									<div className="flex items-start gap-3">
										<div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
											<Icon className="size-4" />
										</div>
										<div>
											<p className="text-sm font-medium">
												{benefit.title}
											</p>
											<Text className="mt-0.5 text-xs text-zinc-500">
												{benefit.description}
											</Text>
										</div>
									</div>
								</Card>
							);
						})}
					</div>
				</div>
			)}
		</div>
	);
}
