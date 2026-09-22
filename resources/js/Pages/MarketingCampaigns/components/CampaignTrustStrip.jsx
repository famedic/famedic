import {
	BuildingStorefrontIcon,
	CreditCardIcon,
	ShieldCheckIcon,
	SparklesIcon,
} from "@heroicons/react/20/solid";
import { Text } from "@/Components/Catalyst/text";

const BASE_ITEMS = [
	{
		title: "Cobertura en Mexico",
		description: "Consulta disponibilidad por ciudad y sucursal participante.",
		icon: BuildingStorefrontIcon,
	},
	{
		title: "Compra segura",
		description: "Proceso protegido y precios Famedic visibles antes de continuar.",
		icon: CreditCardIcon,
	},
	{
		title: "Respaldo Famedic",
		description: "Acompanamiento para elegir y dar seguimiento a tus estudios.",
		icon: ShieldCheckIcon,
	},
];

export default function CampaignTrustStrip({ brand, variant = "editorial" }) {
	const items = variant === "catalog"
		? [
				{
					title: "Laboratorios confiables",
					description: brand?.label ? `Opciones disponibles con ${brand.label}.` : "Opciones verificadas por Famedic.",
					icon: SparklesIcon,
				},
				...BASE_ITEMS,
			]
		: BASE_ITEMS;

	if (variant === "catalog") {
		return (
			<section className={`grid gap-0 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 ${
				items.length === 4 ? "md:grid-cols-2 xl:grid-cols-4" : "md:grid-cols-3"
			}`}>
				{items.map((item, index) => {
					const Icon = item.icon;
					return (
						<div
							key={item.title}
							className={`flex gap-4 p-5 ${
								index > 0 ? "border-t border-slate-200 md:border-l md:border-t-0" : ""
							}`}
						>
							<span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-100">
								<Icon className="size-7" />
							</span>
							<span className="min-w-0">
								<Text className="font-semibold text-famedic-darker">{item.title}</Text>
								<Text className="mt-1 text-sm leading-6 text-slate-600">{item.description}</Text>
							</span>
						</div>
					);
				})}
			</section>
		);
	}

	return (
		<section className={`grid gap-3 rounded-2xl bg-sky-50/80 p-4 ring-1 ring-sky-100 sm:p-5 ${
			items.length === 4 ? "md:grid-cols-2 xl:grid-cols-4" : "md:grid-cols-3"
		}`}>
			{items.map((item) => {
				const Icon = item.icon;
				return (
					<div key={item.title} className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-sky-100/80">
						<span className="flex size-12 items-center justify-center rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-100">
							<Icon className="size-7" />
						</span>
						<Text className="mt-3 font-semibold text-famedic-darker">{item.title}</Text>
						<Text className="mt-1 text-sm leading-6 text-slate-600">{item.description}</Text>
					</div>
				);
			})}
		</section>
	);
}
