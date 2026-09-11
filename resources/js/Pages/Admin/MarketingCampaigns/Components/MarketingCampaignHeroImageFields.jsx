import { useState } from "react";
import { Field, Label, ErrorMessage, Description } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	MARKETING_CAMPAIGN_IMAGE_MAX_BYTES,
	MARKETING_CAMPAIGN_IMAGE_RECOMMENDED_BYTES,
	formatFileSize,
	validateMarketingCampaignImageFile,
} from "./imageFileValidation";

const SOURCE_OPTIONS = [
	{ value: "none", label: "Sin imagen" },
	{ value: "upload", label: "Subir imagen" },
	{ value: "external", label: "Usar URL externa HTTPS" },
];

const HERO_IMAGE_GUIDANCE =
	"Recomendado: 1920 x 1080 px o proporción 16:9. Mantén el producto o mensaje principal centrado para evitar recortes en móvil.";
const HERO_MAX_SIZE_LABEL = formatFileSize(MARKETING_CAMPAIGN_IMAGE_MAX_BYTES);
const HERO_RECOMMENDED_SIZE_LABEL = formatFileSize(
	MARKETING_CAMPAIGN_IMAGE_RECOMMENDED_BYTES,
);

export default function MarketingCampaignHeroImageFields({
	data,
	setData,
	errors = {},
	previewUrl = null,
}) {
	const source = data.hero_image_source || "none";
	const [localImageMessage, setLocalImageMessage] = useState(null);
	const heroImageValidation = validateMarketingCampaignImageFile(
		data.hero_image,
	);
	const heroImageMessage = localImageMessage ?? heroImageValidation;
	const resolvedPreview =
		source === "external" && data.hero_image_url
			? data.hero_image_url
			: source === "upload" && data.hero_image instanceof File
				? URL.createObjectURL(data.hero_image)
				: previewUrl;

	const handleSourceChange = (value) => {
		setData({
			...data,
			hero_image_source: value,
			hero_image: value === "upload" ? data.hero_image || null : null,
			hero_image_url:
				value === "external" ? data.hero_image_url || "" : "",
		});
	};

	return (
		<div className="space-y-4">
			<Field>
				<Label>Imagen principal (hero)</Label>
				<Listbox
					value={source}
					onChange={handleSourceChange}
					placeholder="Seleccionar fuente"
				>
					{SOURCE_OPTIONS.map((option) => (
						<ListboxOption key={option.value} value={option.value}>
							<ListboxLabel>{option.label}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
				{errors.hero_image_source && (
					<ErrorMessage>{errors.hero_image_source}</ErrorMessage>
				)}
				<Description>{HERO_IMAGE_GUIDANCE}</Description>
			</Field>

			{source === "upload" && (
				<Field>
					<Label>Archivo de imagen</Label>
					<Input
						type="file"
						accept="image/jpeg,image/png,image/webp"
						onChange={(e) => {
							const file = e.target.files?.[0] || null;
							const validation =
								validateMarketingCampaignImageFile(file);

							if (!validation.valid) {
								setData("hero_image", null);
								setLocalImageMessage(validation);
								e.target.value = "";
								return;
							}

							setLocalImageMessage(null);
							setData("hero_image", file);
						}}
					/>
					<Description>
						JPG, PNG o WebP. Máximo {HERO_MAX_SIZE_LABEL}. Para
						mejor carga, usa WebP o JPG optimizado cerca de{" "}
						{HERO_RECOMMENDED_SIZE_LABEL}. Si no seleccionas un
						archivo nuevo, se conserva la imagen actual.
					</Description>
					{heroImageMessage?.message && (
						<Text
							className={`mt-1 text-sm ${
								heroImageMessage.severity === "error"
									? "text-red-600 dark:text-red-500"
									: heroImageMessage.severity === "success"
									? "text-emerald-700 dark:text-emerald-400"
									: "text-amber-700 dark:text-amber-400"
							}`}
						>
							{heroImageMessage.message}
						</Text>
					)}
					{errors.hero_image && (
						<ErrorMessage>{errors.hero_image}</ErrorMessage>
					)}
				</Field>
			)}

			{source === "external" && (
				<Field>
					<Label>URL HTTPS</Label>
					<Input
						value={data.hero_image_url || ""}
						onChange={(e) =>
							setData("hero_image_url", e.target.value)
						}
						placeholder="https://ejemplo.com/imagen.jpg"
					/>
					<Description>
						Usa una imagen HTTPS en proporción 16:9, idealmente de
						1920 x 1080 px, sin texto pegado a los bordes.
					</Description>
					{errors.hero_image_url && (
						<ErrorMessage>{errors.hero_image_url}</ErrorMessage>
					)}
				</Field>
			)}

			<Field>
				<Label>Texto alternativo</Label>
				<Input
					value={data.hero_image_alt || ""}
					onChange={(e) => setData("hero_image_alt", e.target.value)}
					maxLength={180}
				/>
				{errors.hero_image_alt && (
					<ErrorMessage>{errors.hero_image_alt}</ErrorMessage>
				)}
			</Field>

			{resolvedPreview ? (
				<div className="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
					<img
						src={resolvedPreview}
						alt={data.hero_image_alt || "Vista previa hero"}
						className="max-h-64 w-full object-cover"
					/>
					<Text className="px-3 py-2 text-sm text-zinc-500">
						Vista previa
					</Text>
				</div>
			) : null}
		</div>
	);
}
