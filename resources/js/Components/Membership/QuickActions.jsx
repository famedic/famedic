import { Button } from "@/Components/Catalyst/button";
import Card from "@/Components/Card";
import {
	PhoneIcon,
	ArrowPathIcon,
	UserPlusIcon,
	SparklesIcon,
	DocumentArrowDownIcon,
} from "@heroicons/react/24/outline";

const ACTIONS = [
	{
		key: "call",
		label: "Llamar línea médica",
		icon: PhoneIcon,
		getHref: (membership) => membership?.access?.telHref,
		requires: (membership) => Boolean(membership?.access?.telHref),
	},
	{
		key: "benefits",
		label: "Ver beneficios",
		icon: SparklesIcon,
		action: "benefits",
	},
	{
		key: "beneficiary",
		label: "Agregar beneficiario",
		icon: UserPlusIcon,
		getHref: (membership) => membership?.capabilities?.addBeneficiaryUrl,
		requires: (membership) => membership?.capabilities?.canAddBeneficiary,
	},
	{
		key: "renew",
		label: "Renovar membresía",
		icon: ArrowPathIcon,
		getHref: (membership) => membership?.status?.renewUrl,
		requires: (membership) =>
			membership?.status?.canRenew && membership?.capabilities?.canRenew,
	},
	{
		key: "receipt",
		label: "Descargar comprobante",
		icon: DocumentArrowDownIcon,
		getHref: (membership) => membership?.capabilities?.receiptDownloadUrl,
		requires: (membership) =>
			membership?.capabilities?.canDownloadReceipt &&
			membership?.capabilities?.receiptDownloadUrl,
	},
];

export default function QuickActions({ membership, onAction }) {
	const visibleActions = ACTIONS.filter((action) =>
		action.requires ? action.requires(membership) : true,
	).slice(0, 4);

	if (visibleActions.length === 0) {
		return null;
	}

	return (
		<div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
			{visibleActions.map((action) => {
				const Icon = action.icon;
				const href = action.getHref?.(membership);

				if (action.action) {
					return (
						<Card
							key={action.key}
							as="button"
							type="button"
							onClick={() => onAction?.(action.action)}
							className="flex flex-col items-start gap-3 rounded-2xl p-4 text-left shadow-sm ring-1 ring-slate-100 transition hover:shadow-md hover:ring-violet-200"
						>
							<div className="flex size-9 items-center justify-center rounded-xl bg-famedic-dark/5 text-famedic-dark dark:bg-white/10 dark:text-white">
								<Icon className="size-5" />
							</div>
							<span className="text-sm font-medium text-zinc-800 dark:text-slate-100">
								{action.label}
							</span>
						</Card>
					);
				}

				return (
					<Card
						key={action.key}
						href={href ?? undefined}
						className="flex flex-col items-start gap-3 rounded-2xl p-4 shadow-sm ring-1 ring-slate-100 transition hover:shadow-md hover:ring-violet-200"
					>
						<div className="flex size-9 items-center justify-center rounded-xl bg-famedic-dark/5 text-famedic-dark dark:bg-white/10 dark:text-white">
							<Icon className="size-5" />
						</div>
						<span className="text-sm font-medium text-zinc-800 dark:text-slate-100">
							{action.label}
						</span>
					</Card>
				);
			})}
		</div>
	);
}
