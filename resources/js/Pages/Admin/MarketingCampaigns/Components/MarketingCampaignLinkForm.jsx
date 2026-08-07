import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import MarketingCampaignDateRangeFields from "./MarketingCampaignDateRangeFields";
import MarketingCampaignTargetFields from "./MarketingCampaignTargetFields";
import MarketingCampaignUtmFields from "./MarketingCampaignUtmFields";
import MarketingCampaignFormSection from "./MarketingCampaignFormSection";
import MarketingCampaignHeroImageFields from "./MarketingCampaignHeroImageFields";
import MarketingCampaignGalleryFields from "./MarketingCampaignGalleryFields";
import MarketingCampaignProductSelector from "./MarketingCampaignProductSelector";
import MarketingCampaignCategorySelector from "./MarketingCampaignCategorySelector";
import { resolveLinkBrandValue } from "./marketingCampaignLinkBrand";

function optionEntries(options) {
	if (!options) return [];
	if (Array.isArray(options)) {
		return options.map((option) =>
			typeof option === "string"
				? [option, option]
				: [option.value, option.label ?? option.value],
		);
	}
	return Object.entries(options);
}

export function slugify(value) {
	return String(value || "")
		.normalize("NFD")
		.replace(/[\u0300-\u036f]/g, "")
		.toLowerCase()
		.trim()
		.replace(/[^a-z0-9]+/g, "-")
		.replace(/^-+|-+$/g, "")
		.slice(0, 180);
}

function formatDateTime(value) {
	if (!value) return "—";
	try {
		return new Date(value).toLocaleString("es-MX");
	} catch {
		return String(value).slice(0, 16);
	}
}

export default function MarketingCampaignLinkForm({
	data,
	setData,
	errors = {},
	statusOptions = {},
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
	aliases = [],
	isEdit = false,
	processing = false,
	onSubmit,
	submitLabel = "Guardar enlace",
	primaryProducts = [],
	onPrimaryProductsChange,
	relatedProducts = [],
	onRelatedProductsChange,
	relatedCategoryItems = [],
	onRelatedCategoryItemsChange,
	galleryItems = [],
	onGalleryItemsChange,
	heroPreviewUrl = null,
}) {
	const [slugTouched, setSlugTouched] = useState(
		() => Boolean(data.slug && String(data.slug).trim()),
	);

	const brandValue = resolveLinkBrandValue(data, collections);
	const primaryIds = primaryProducts.map((item) => Number(item.id));

	const handleNameChange = (value) => {
		const next = {
			...data,
			name: value,
		};
		if (!slugTouched) {
			next.slug = slugify(value);
		}
		setData(next);
	};

	const handleSlugChange = (value) => {
		setSlugTouched(true);
		setData("slug", value);
	};

	return (
		<form onSubmit={onSubmit} className="space-y-6">
			<MarketingCampaignFormSection
				title="Resumen"
				description="Identidad del enlace, estado y vigencia."
				defaultOpen
			>
				<div className="space-y-4">
					<Field>
						<Label>Nombre</Label>
						<Input
							autoFocus
							value={data.name || ""}
							onChange={(e) => handleNameChange(e.target.value)}
							placeholder="Nombre del enlace"
						/>
						{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
					</Field>

					<Field>
						<Label>Dirección del enlace</Label>
						<Input
							value={data.slug || ""}
							onChange={(e) => handleSlugChange(e.target.value)}
							placeholder="mi-enlace-campana"
						/>
						<Text className="mt-1 font-mono text-sm text-zinc-600 dark:text-zinc-400">
							/c/{data.slug || "…"}
						</Text>
						<Text className="mt-1 text-xs text-zinc-500">
							Antes llamado slug · parámetro técnico{" "}
							<code>slug</code>
						</Text>
						{isEdit && (
							<Text className="mt-1 text-sm text-zinc-500">
								Si cambias el slug, el valor anterior se conserva
								como alias.
							</Text>
						)}
						{errors.slug && <ErrorMessage>{errors.slug}</ErrorMessage>}
					</Field>

					<Field>
						<Label>Estado</Label>
						<Listbox
							value={data.status || ""}
							onChange={(value) => setData("status", value)}
							placeholder="Seleccionar estado"
						>
							{optionEntries(statusOptions).map(([value, label]) => (
								<ListboxOption key={value} value={value}>
									<ListboxLabel>{label}</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
						{errors.status && (
							<ErrorMessage>{errors.status}</ErrorMessage>
						)}
					</Field>

					<MarketingCampaignDateRangeFields
						data={data}
						setData={setData}
						errors={errors}
					/>
				</div>
			</MarketingCampaignFormSection>

			<MarketingCampaignFormSection
				title="Destino y productos"
				description="Define qué se promocionará y los estudios visibles en la landing."
			>
				<MarketingCampaignTargetFields
					data={data}
					setData={setData}
					errors={errors}
					brands={brands}
					categories={categories}
					collections={collections}
					productSearchUrl={productSearchUrl}
				/>
			</MarketingCampaignFormSection>

			<MarketingCampaignFormSection
				title="Productos principales"
				description="Estudios destacados. Si queda vacío, se usa el fallback del destino."
			>
				{!brandValue ? (
					<Text className="text-sm text-zinc-500">
						Selecciona un destino con marca válida para buscar
						estudios.
					</Text>
				) : (
					<MarketingCampaignProductSelector
						brand={brandValue}
						selectedItems={primaryProducts}
						onChange={onPrimaryProductsChange}
						productSearchUrl={productSearchUrl}
						maxItems={20}
						error={
							errors.primary_laboratory_test_ids ||
							errors["primary_laboratory_test_ids.0"]
						}
						emptyMessage="Sin estudios destacados. Se usará el fallback del destino."
						addLabel="Buscar estudios destacados"
					/>
				)}
			</MarketingCampaignFormSection>

			<MarketingCampaignFormSection
				title="Contenido de la landing"
				description="Textos públicos, CTAs y opciones de visualización."
			>
				<div className="space-y-4">
					<Field>
						<Label>Texto superior</Label>
						<Input
							value={data.eyebrow || ""}
							onChange={(e) => setData("eyebrow", e.target.value)}
							placeholder="Campaña Famedic"
							maxLength={120}
						/>
						{errors.eyebrow && (
							<ErrorMessage>{errors.eyebrow}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Título público</Label>
						<Input
							value={data.public_title || ""}
							onChange={(e) =>
								setData("public_title", e.target.value)
							}
							maxLength={180}
						/>
						{errors.public_title && (
							<ErrorMessage>{errors.public_title}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Subtítulo</Label>
						<Input
							value={data.public_subtitle || ""}
							onChange={(e) =>
								setData("public_subtitle", e.target.value)
							}
							maxLength={255}
						/>
						{errors.public_subtitle && (
							<ErrorMessage>{errors.public_subtitle}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Descripción</Label>
						<Textarea
							rows={4}
							value={data.public_description || ""}
							onChange={(e) =>
								setData("public_description", e.target.value)
							}
						/>
						{errors.public_description && (
							<ErrorMessage>
								{errors.public_description}
							</ErrorMessage>
						)}
					</Field>

					<div className="grid gap-4 sm:grid-cols-2">
						<Field>
							<Label>Texto CTA principal</Label>
							<Input
								value={data.primary_cta_label || ""}
								onChange={(e) =>
									setData("primary_cta_label", e.target.value)
								}
								maxLength={80}
							/>
							{errors.primary_cta_label && (
								<ErrorMessage>
									{errors.primary_cta_label}
								</ErrorMessage>
							)}
						</Field>
						<Field>
							<Label>Texto CTA secundario</Label>
							<Input
								value={data.secondary_cta_label || ""}
								onChange={(e) =>
									setData(
										"secondary_cta_label",
										e.target.value,
									)
								}
								maxLength={80}
							/>
							{errors.secondary_cta_label && (
								<ErrorMessage>
									{errors.secondary_cta_label}
								</ErrorMessage>
							)}
						</Field>
					</div>

					<div className="space-y-3">
						<CheckboxField>
							<Checkbox
								checked={Boolean(data.show_prices)}
								onChange={(checked) =>
									setData("show_prices", checked)
								}
							/>
							<Label>Mostrar precios</Label>
						</CheckboxField>
						<CheckboxField>
							<Checkbox
								checked={Boolean(data.show_brand_logo)}
								onChange={(checked) =>
									setData("show_brand_logo", checked)
								}
							/>
							<Label>Mostrar logo de marca</Label>
						</CheckboxField>
						<CheckboxField>
							<Checkbox
								checked={Boolean(data.show_campaign_dates)}
								onChange={(checked) =>
									setData("show_campaign_dates", checked)
								}
							/>
							<Label>Mostrar vigencia</Label>
						</CheckboxField>
					</div>
				</div>
			</MarketingCampaignFormSection>

			<MarketingCampaignFormSection
				title="Imágenes"
				description="Hero principal y galería opcional."
			>
				<div className="space-y-8">
					<MarketingCampaignHeroImageFields
						data={data}
						setData={setData}
						errors={errors}
						previewUrl={heroPreviewUrl}
					/>
					<div className="border-t border-zinc-100 pt-6 dark:border-zinc-800">
						<Text className="mb-4 font-medium">Galería</Text>
						<MarketingCampaignGalleryFields
							items={galleryItems}
							onChange={onGalleryItemsChange}
							errors={errors}
						/>
					</div>
				</div>
			</MarketingCampaignFormSection>

			<MarketingCampaignFormSection
				title="Relacionados"
				description="Estudios y categorías adicionales para la landing comercial."
			>
				<div className="space-y-8">
					<div>
						<Text className="mb-3 font-medium">
							También te puede interesar
						</Text>
						{!brandValue ? (
							<Text className="text-sm text-zinc-500">
								Selecciona un destino con marca válida.
							</Text>
						) : (
							<MarketingCampaignProductSelector
								brand={brandValue}
								selectedItems={relatedProducts}
								onChange={onRelatedProductsChange}
								productSearchUrl={productSearchUrl}
								maxItems={8}
								excludeIds={primaryIds}
								error={
									errors.related_laboratory_test_ids ||
									errors["related_laboratory_test_ids.0"]
								}
								emptyMessage="Sin estudios relacionados."
								addLabel="Buscar estudios relacionados"
							/>
						)}
					</div>

					<div className="border-t border-zinc-100 pt-6 dark:border-zinc-800">
						<Text className="mb-3 font-medium">
							Categorías relacionadas
						</Text>
						<MarketingCampaignCategorySelector
							categories={categories}
							selectedItems={relatedCategoryItems}
							onChange={onRelatedCategoryItemsChange}
							error={errors.related_category_ids}
						/>
					</div>
				</div>
			</MarketingCampaignFormSection>

			<MarketingCampaignFormSection
				title="Marketing y UTMs"
				description="Parámetros UTM opcionales para atribución futura."
			>
				<MarketingCampaignUtmFields
					data={data}
					setData={setData}
					errors={errors}
					friendlyLabels
				/>
			</MarketingCampaignFormSection>

			{isEdit && aliases?.length > 0 && (
				<MarketingCampaignFormSection
					title="Historial de slugs"
					description="Aliases conservados al cambiar la dirección del enlace."
				>
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Slug</TableHeader>
								<TableHeader>Creado</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{aliases.map((alias) => (
								<TableRow key={alias.id ?? alias.slug}>
									<TableCell className="font-mono">
										{alias.slug}
									</TableCell>
									<TableCell>
										{formatDateTime(alias.created_at)}
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</MarketingCampaignFormSection>
			)}

			<div className="sticky bottom-4 flex justify-end rounded-xl border border-zinc-200 bg-white/95 p-3 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
				<Button type="submit" color="lime" disabled={processing}>
					{processing && <ArrowPathIcon className="animate-spin" />}
					{submitLabel}
				</Button>
			</div>
		</form>
	);
}
