import { CheckCircleIcon } from "@heroicons/react/20/solid";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Text } from "@/Components/Catalyst/text";
import MarketingCampaignFieldHelp from "./MarketingCampaignFieldHelp";

const DEFAULT_TEMPLATES = [
	{
		value: "conversion",
		label: "Conversión directa",
		description:
			"Hero, CTAs y productos destacados para llevar rápido al usuario a comprar o consultar.",
		recommendation: "Recomendada",
		useCase: "Promociones · 1-4 productos",
	},
	{
		value: "editorial",
		label: "Editorial de salud",
		description:
			"Más espacio para explicar la campaña, educar y generar confianza antes de mostrar productos.",
		recommendation: "Educativa",
		useCase: "Prevención · 1-4 productos",
	},
	{
		value: "catalog",
		label: "Catálogo premium",
		description:
			"Productos al frente, con la información comercial acompañando el listado.",
		recommendation: "Amplia",
		useCase: "Campañas amplias · 5+ productos",
	},
];

function TemplateThumbnail({ template }) {
	if (template === "catalog") {
		return (
			<div className="grid h-20 grid-cols-3 gap-1 rounded-md bg-zinc-100 p-2 dark:bg-zinc-800">
				{Array.from({ length: 6 }).map((_, index) => (
					<div key={index} className="rounded bg-white dark:bg-zinc-700" />
				))}
			</div>
		);
	}

	if (template === "editorial") {
		return (
			<div className="h-20 rounded-md bg-zinc-900 p-2">
				<div className="h-7 rounded bg-white/20" />
				<div className="mt-2 h-2 w-3/4 rounded bg-white/70" />
				<div className="mt-1 h-2 w-1/2 rounded bg-white/40" />
			</div>
		);
	}

	return (
		<div className="grid h-20 grid-cols-[1fr_0.8fr] gap-2 rounded-md bg-lime-50 p-2 dark:bg-lime-950/40">
			<div>
				<div className="h-3 w-2/3 rounded bg-lime-300" />
				<div className="mt-2 h-2 rounded bg-zinc-300" />
				<div className="mt-1 h-2 w-3/4 rounded bg-zinc-300" />
				<div className="mt-3 h-5 w-20 rounded bg-lime-400" />
			</div>
			<div className="rounded bg-white dark:bg-zinc-700" />
		</div>
	);
}

export default function MarketingCampaignLandingTemplateSelector({
	value = "conversion",
	onChange,
	options = DEFAULT_TEMPLATES,
	error,
}) {
	const selectedValue = value || "conversion";

	return (
		<Field>
			<Label>Plantilla de landing</Label>
			<div className="mt-3 grid gap-3 lg:grid-cols-3">
				{options.map((option) => {
					const selected = selectedValue === option.value;

					return (
						<div
							key={option.value}
							className={`rounded-lg border p-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light focus-visible:ring-offset-2 ${
								selected
									? "border-famedic-light bg-famedic-light/10 ring-2 ring-famedic-light/30"
									: "border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
							}`}
						>
							<TemplateThumbnail template={option.value} />
							<div className="mt-3 flex items-start justify-between gap-3">
								<Text className="font-semibold">
									<MarketingCampaignFieldHelp label={option.label}>
										{option.description}
									</MarketingCampaignFieldHelp>
								</Text>
								{selected && (
									<CheckCircleIcon className="size-5 shrink-0 text-emerald-600" />
								)}
							</div>
							<Text className="mt-2 text-xs font-semibold uppercase text-lime-700 dark:text-lime-400">
								{option.recommendation}
							</Text>
							<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
								{option.useCase}
							</Text>
							<button
								type="button"
								aria-pressed={selected}
								onClick={() => onChange(option.value)}
								className="mt-3 w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-900 transition hover:border-famedic-light hover:text-famedic-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light dark:border-zinc-700 dark:text-white"
							>
								{selected ? "Seleccionada" : "Seleccionar"}
							</button>
						</div>
					);
				})}
			</div>
			{error && <ErrorMessage>{error}</ErrorMessage>}
		</Field>
	);
}
