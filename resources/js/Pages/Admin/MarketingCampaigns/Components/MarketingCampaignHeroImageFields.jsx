import { Field, Label, ErrorMessage, Description } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";

const SOURCE_OPTIONS = [
	{ value: "none", label: "Sin imagen" },
	{ value: "upload", label: "Subir imagen" },
	{ value: "external", label: "Usar URL externa HTTPS" },
];

export default function MarketingCampaignHeroImageFields({
	data,
	setData,
	errors = {},
	previewUrl = null,
}) {
	const source = data.hero_image_source || "none";
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
			</Field>

			{source === "upload" && (
				<Field>
					<Label>Archivo de imagen</Label>
					<Input
						type="file"
						accept="image/jpeg,image/png,image/webp"
						onChange={(e) =>
							setData(
								"hero_image",
								e.target.files?.[0] || null,
							)
						}
					/>
					<Description>
						JPG, PNG o WebP. Máximo 5 MB. Si no seleccionas un
						archivo nuevo, se conserva la imagen actual.
					</Description>
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
