import { useState } from "react";
import { router } from "@inertiajs/react";
import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	CheckIcon,
	ChevronDownIcon,
	ChevronUpIcon,
	PlusIcon,
	ShieldCheckIcon,
	XMarkIcon,
} from "@heroicons/react/24/solid";

const BENEFIT_CHIPS = [
	"Médico 24/7",
	"Psicología",
	"Nutrición",
	"Familia",
];

const COVERAGE_TABS = [
	{ id: "who", label: "A quién cubre" },
	{ id: "includes", label: "Qué incluye" },
	{ id: "excludes", label: "No incluye" },
];

const WHO_COVERS = ["Titular", "Cónyuge", "Hijos"];

const INCLUDED_BENEFITS = [
	{
		title: "Telemedicina ilimitada 24/7",
		description:
			"Consulta con médicos generales por videoconferencia o chat 24/7.",
	},
	{
		title: "Asistencias telefónicas ilimitadas",
		description: "Psicológica, nutricional y legal.",
	},
];

const EXCLUDED_BENEFITS = [
	"Médico en casa",
	"Ambulancia en emergencia",
	"Reembolso de medicamentos",
];

export default function MembershipCrossSellCard({
	laboratoryBrand,
	membershipCrossSell,
	formattedMembershipPrice,
}) {
	const [isAdding, setIsAdding] = useState(false);
	const [showDetails, setShowDetails] = useState(false);
	const [activeTab, setActiveTab] = useState("who");

	const displayPrice =
		formattedMembershipPrice || membershipCrossSell?.formattedPrice || "$300.00";

	const displayAmount = membershipCrossSell?.priceCents
		? Math.round(membershipCrossSell.priceCents / 100)
		: displayPrice.replace(/[^0-9]/g, "");

	const handleAddMembership = () => {
		if (isAdding) return;

		setIsAdding(true);

		router.post(
			route("laboratory.cart-membership.store", {
				laboratory_brand: laboratoryBrand.value,
			}),
			{},
			{
				preserveScroll: true,
				onFinish: () => setIsAdding(false),
			},
		);
	};

	const toggleDetails = () => setShowDetails((prev) => !prev);

	return (
		<div className="mt-6 transition-all duration-300 sm:mt-8">
			<Card className="overflow-hidden shadow-sm ring-1 ring-slate-100">
				<div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:p-5">
					<div className="flex min-w-0 flex-1 items-start gap-3">
						<div
							className="hidden shrink-0 sm:flex sm:items-center sm:justify-center sm:rounded-lg sm:bg-sky-50 sm:p-2.5 dark:sm:bg-sky-500/10"
							aria-hidden="true"
						>
							<ShieldCheckIcon className="size-5 text-sky-600 dark:text-sky-400" />
						</div>

						<div className="min-w-0 flex-1 space-y-2">
							<Badge
								color="sky"
								className="w-fit px-2 py-0.5 text-xs"
							>
								<ShieldCheckIcon className="size-3.5" />
								Protege a tu familia
							</Badge>

							<h3 className="font-poppins text-base font-semibold leading-snug text-famedic-dark sm:text-lg dark:text-white">
								Membresía Médica Anual
							</h3>

							<Text className="text-sm leading-snug text-zinc-600 dark:text-slate-300">
								Acceso a atención médica familiar 24/7 por un
								año.
							</Text>

							<div className="flex flex-wrap gap-1.5 pt-0.5">
								{BENEFIT_CHIPS.map((chip) => (
									<span
										key={chip}
										className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
									>
										{chip}
									</span>
								))}
							</div>

							<button
								type="button"
								onClick={toggleDetails}
								className="inline-flex items-center gap-1 pt-0.5 text-sm font-medium text-famedic-dark underline-offset-2 hover:underline dark:text-sky-300"
								aria-expanded={showDetails}
							>
								Ver detalles de cobertura
								{showDetails ? (
									<ChevronUpIcon className="size-3.5" />
								) : (
									<ChevronDownIcon className="size-3.5" />
								)}
							</button>
						</div>
					</div>

					<div className="flex shrink-0 flex-col gap-3 border-t border-slate-100 pt-4 sm:items-end sm:border-t-0 sm:pt-0 sm:text-right">
						<div className="flex items-baseline gap-1 sm:flex-col sm:items-end sm:gap-0">
							<span className="font-poppins text-xl font-bold leading-none text-famedic-dark dark:text-white">
								${displayAmount} MXN
							</span>
							<Text className="text-sm text-zinc-500 dark:text-slate-400">
								/ año
							</Text>
						</div>

						<Button
							type="button"
							onClick={handleAddMembership}
							disabled={isAdding}
							className="w-full sm:w-auto sm:min-w-[180px] !py-2.5"
						>
							<PlusIcon className="size-4" />
							{isAdding ? "Agregando..." : "Agregar membresía"}
						</Button>
					</div>
				</div>

				{showDetails && (
					<div className="border-t border-slate-100 bg-slate-50/60 px-4 py-3 sm:px-5 sm:py-4 dark:border-slate-800 dark:bg-slate-800/40">
						<div
							role="tablist"
							aria-label="Detalles de cobertura de la membresía"
							className="flex flex-wrap gap-1"
						>
							{COVERAGE_TABS.map((tab) => (
								<button
									key={tab.id}
									type="button"
									role="tab"
									aria-selected={activeTab === tab.id}
									onClick={() => setActiveTab(tab.id)}
									className={`rounded-full px-3 py-1 text-xs font-medium transition-colors sm:text-sm ${
										activeTab === tab.id
											? "bg-famedic-dark text-white dark:bg-sky-600"
											: "bg-white text-zinc-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800"
									}`}
								>
									{tab.label}
								</button>
							))}
						</div>

						<div
							role="tabpanel"
							className="mt-3 space-y-2"
						>
							{activeTab === "who" && (
								<ul className="space-y-1.5">
									{WHO_COVERS.map((person) => (
										<li
											key={person}
											className="flex items-center gap-2 text-sm text-zinc-700 dark:text-slate-200"
										>
											<CheckIcon className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
											{person}
										</li>
									))}
								</ul>
							)}

							{activeTab === "includes" && (
								<ul className="space-y-2.5">
									{INCLUDED_BENEFITS.map((benefit) => (
										<li
											key={benefit.title}
											className="flex gap-2"
										>
											<CheckIcon className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
											<div>
												<p className="text-sm font-medium text-zinc-800 dark:text-slate-100">
													{benefit.title}
												</p>
												<p className="text-xs leading-snug text-zinc-500 dark:text-slate-400">
													{benefit.description}
												</p>
											</div>
										</li>
									))}
								</ul>
							)}

							{activeTab === "excludes" && (
								<div className="space-y-2">
									<ul className="space-y-1.5">
										{EXCLUDED_BENEFITS.map((item) => (
											<li
												key={item}
												className="flex items-center gap-2 text-sm text-zinc-500 dark:text-slate-400"
											>
												<XMarkIcon className="size-4 shrink-0 text-zinc-400 dark:text-slate-500" />
												{item}
											</li>
										))}
									</ul>
								</div>
							)}
						</div>
					</div>
				)}
			</Card>
		</div>
	);
}
