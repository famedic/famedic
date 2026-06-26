import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

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

export default function MembershipPlanInfo({ plan }) {
	if (!plan) {
		return null;
	}

	const statusColor =
		plan.status === "Pagado"
			? "emerald"
			: plan.status === "Pendiente"
				? "amber"
				: "sky";

	return (
		<Card className="p-6 shadow-sm ring-1 ring-slate-100 sm:p-8">
			<div className="mb-4">
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Información del plan
				</h3>
				<Text className="text-sm text-zinc-500">
					Detalles de tu membresía actual.
				</Text>
			</div>

			<div className="divide-y divide-slate-100 dark:divide-slate-800">
				<PlanRow label="Plan" value={plan.name} />
				<PlanRow label="Precio" value={plan.price} />
				<PlanRow label="Fecha de compra" value={plan.purchaseDate} />
				<PlanRow label="Renovación" value={plan.renewalDate} />
				<PlanRow label="Tipo de pago" value={plan.paymentType} />
				<PlanRow label="Estado">
					<Badge color={statusColor}>{plan.status}</Badge>
				</PlanRow>
			</div>
		</Card>
	);
}
