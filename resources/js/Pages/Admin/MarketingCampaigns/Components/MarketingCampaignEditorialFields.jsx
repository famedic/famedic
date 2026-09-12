import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import {
	Listbox,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";

const ICON_OPTIONS = [
	{ value: "shield", label: "Prevención" },
	{ value: "heart", label: "Bienestar" },
	{ value: "lab", label: "Laboratorio" },
	{ value: "clock", label: "Rapidez" },
];

const PLACEHOLDER_PATTERNS = [
	/t[ií]tulo de prueba/i,
	/descripci[oó]n de prueba/i,
	/lorem ipsum/i,
	/texto de prueba/i,
	/\bqa\b/i,
];

function normalizeItems(items = []) {
	return Array.isArray(items) ? items.slice(0, 4) : [];
}

export function hasEditorialPlaceholderContent(data = {}) {
	const values = [
		data.editorial_eyebrow,
		data.editorial_title,
		data.editorial_body,
		...normalizeItems(data.editorial_items).flatMap((item) => [
			item?.title,
			item?.description,
		]),
	].filter((value) => typeof value === "string" && value.trim() !== "");

	return values.some((value) =>
		PLACEHOLDER_PATTERNS.some((pattern) => pattern.test(value)),
	);
}

export default function MarketingCampaignEditorialFields({
	data,
	setData,
	errors = {},
}) {
	const items = normalizeItems(data.editorial_items);
	const hasPlaceholders = hasEditorialPlaceholderContent(data);

	const setItem = (index, patch) => {
		const next = items.map((item, itemIndex) =>
			itemIndex === index ? { ...item, ...patch } : item,
		);
		setData("editorial_items", next);
	};

	const addItem = () => {
		if (items.length >= 4) return;
		setData("editorial_items", [
			...items,
			{ title: "", description: "", icon: "shield" },
		]);
	};

	const removeItem = (index) => {
		setData(
			"editorial_items",
			items.filter((_, itemIndex) => itemIndex !== index),
		);
	};

	if ((data.landing_template || "conversion") !== "editorial") {
		return null;
	}

	return (
		<div className="space-y-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
			<div>
				<Text className="font-semibold">Contenido editorial</Text>
				<Text className="mt-1 text-sm text-zinc-500">
					Textos opcionales para campañas preventivas o educativas. No
					se permite HTML.
				</Text>
			</div>

			{hasPlaceholders && (
				<div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
					Detectamos textos de prueba en el contenido editorial. Puedes guardar, pero conviene reemplazarlos antes de publicar.
				</div>
			)}

			<div className="grid gap-4 sm:grid-cols-2">
				<Field>
					<Label>Etiqueta editorial</Label>
					<Input
						value={data.editorial_eyebrow || ""}
						onChange={(event) =>
							setData("editorial_eyebrow", event.target.value)
						}
						maxLength={120}
					/>
					{errors.editorial_eyebrow && (
						<ErrorMessage>{errors.editorial_eyebrow}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Título editorial</Label>
					<Input
						value={data.editorial_title || ""}
						onChange={(event) =>
							setData("editorial_title", event.target.value)
						}
						maxLength={180}
					/>
					{errors.editorial_title && (
						<ErrorMessage>{errors.editorial_title}</ErrorMessage>
					)}
				</Field>
			</div>

			<Field>
				<Label>Cuerpo editorial</Label>
				<Textarea
					value={data.editorial_body || ""}
					onChange={(event) =>
						setData("editorial_body", event.target.value)
					}
					rows={4}
				/>
				{errors.editorial_body && (
					<ErrorMessage>{errors.editorial_body}</ErrorMessage>
				)}
			</Field>

			<div className="space-y-3">
				<div className="flex items-center justify-between gap-3">
					<Text className="font-medium">Puntos informativos</Text>
					<Button
						type="button"
						outline
						onClick={addItem}
						disabled={items.length >= 4}
					>
						Agregar punto
					</Button>
				</div>
				{items.map((item, index) => (
					<div
						key={index}
						className="grid gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-950 sm:grid-cols-[10rem_1fr_auto]"
					>
						<Field>
							<Label>Icono</Label>
							<Listbox
								value={item.icon || "shield"}
								onChange={(value) => setItem(index, { icon: value })}
							>
								{ICON_OPTIONS.map((option) => (
									<ListboxOption key={option.value} value={option.value}>
										<ListboxLabel>{option.label}</ListboxLabel>
									</ListboxOption>
								))}
							</Listbox>
						</Field>
						<div className="grid gap-3">
							<Field>
								<Label>Título</Label>
								<Input
									value={item.title || ""}
									onChange={(event) =>
										setItem(index, { title: event.target.value })
									}
									maxLength={120}
								/>
							</Field>
							<Field>
								<Label>Descripción</Label>
								<Input
									value={item.description || ""}
									onChange={(event) =>
										setItem(index, {
											description: event.target.value,
										})
									}
									maxLength={500}
								/>
							</Field>
						</div>
						<Button
							type="button"
							plain
							onClick={() => removeItem(index)}
							className="self-start"
						>
							Quitar
						</Button>
					</div>
				))}
			</div>
		</div>
	);
}
