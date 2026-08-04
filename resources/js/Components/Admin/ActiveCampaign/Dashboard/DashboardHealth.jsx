import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import TruthBadge from "./TruthBadge";
import SectionHeading, { QuietLink } from "./SectionHeading";
import {
	SignalIcon,
	ExclamationTriangleIcon,
	QueueListIcon,
	ClockIcon,
	UserGroupIcon,
	BanknotesIcon,
} from "@heroicons/react/16/solid";

const ICONS = {
	integration: SignalIcon,
	errors: ExclamationTriangleIcon,
	backlog: QueueListIcon,
	last_sync: ClockIcon,
	patients: UserGroupIcon,
	credits: BanknotesIcon,
};

/** Map tones del service → tokens de BillingMetricCard (sin tocar billingUi). */
const TONE_MAP = {
	green: "lime",
	amber: "amber",
	red: "red",
	sky: "sky",
	default: "default",
};

export default function DashboardHealth({ cards = [], healthUrl, settingsUrl }) {
	return (
		<section className="space-y-4">
			<SectionHeading
				eyebrow="Salud"
				title="Estado de la integración"
				description="Señales operativas de ActiveCampaign y cola de sync."
				action={
					<div className="flex flex-wrap gap-4">
						{healthUrl ? (
							<QuietLink href={healthUrl}>Health Center</QuietLink>
						) : null}
						{settingsUrl ? (
							<QuietLink href={settingsUrl}>Configuración</QuietLink>
						) : null}
					</div>
				}
			/>

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
				{cards.map((card) => {
					const Icon = ICONS[card.id] || SignalIcon;
					return (
						<div key={card.id} className="relative">
							<div className="absolute right-3 top-3 z-10">
								<TruthBadge truth={card.truth} />
							</div>
							<BillingMetricCard
								label={card.label}
								value={card.value}
								hint={card.hint}
								tone={TONE_MAP[card.tone] || "default"}
								icon={Icon}
								className="pr-20"
							/>
						</div>
					);
				})}
			</div>
		</section>
	);
}
