import { CheckCircleIcon } from "@heroicons/react/20/solid";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Text } from "@/Components/Catalyst/text";

const DEFAULT_TEMPLATES = [
	{
		value: "conversion",
		label: "Conversión directa",
		description:
			"Hero, CTAs y productos destacados para llevar rápido al usuario a comprar o consultar.",
		recommendation: "Recomendada · Ideal para promociones · 1-4 productos",
	},
	{
		value: "editorial",
		label: "Editorial de salud",
		description:
			"Más espacio para explicar la campaña, educar y generar confianza antes de mostrar productos.",
		recommendation: "Ideal para prevención y contenido · 1-4 productos",
	},
	{
		value: "catalog",
		label: "Catálogo premium",
		description:
			"Productos al frente, con la información comercial acompañando el listado.",
		recommendation: "Ideal para campañas amplias · 5 o más productos",
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
						<button
							key={option.value}
							type="button"
							aria-pressed={selected}
							onClick={() => onChange(option.value)}
							className={`min-h-56 rounded-lg border p-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light focus-visible:ring-offset-2 ${
								selected
									? "border-famedic-light bg-famedic-light/10 ring-2 ring-famedic-light/30"
									: "border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900"
							}`}
						>
							<TemplateThumbnail template={option.value} />
							<div className="flex items-start justify-between gap-3">
								<Text className="font-semibold">{option.label}</Text>
								{selected && (
									<CheckCircleIcon className="size-5 shrink-0 text-emerald-600" />
								)}
							</div>
							<Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
								{option.description}
							</Text>
							<Text className="mt-3 text-xs font-medium text-zinc-500">
								{option.recommendation}
							</Text>
						</button>
					);
				})}
			</div>
			{error && <ErrorMessage>{error}</ErrorMessage>}
		</Field>
	);
}
