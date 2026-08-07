import { useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import Card from "@/Components/Card";
import MarketingCampaignProductSelector from "./MarketingCampaignProductSelector";
import MarketingCampaignCollectionBrandChangeModal from "./MarketingCampaignCollectionBrandChangeModal";
import MarketingCampaignCollectionPricingSummary from "./MarketingCampaignCollectionPricingSummary";
import MarketingCampaignCollectionHeader from "./MarketingCampaignCollectionHeader";
import MarketingCampaignCollectionStickyActions from "./MarketingCampaignCollectionStickyActions";
import MarketingCampaignStatusBadge from "./MarketingCampaignStatusBadge";

function brandEntries(brands) {
	if (!brands) return [];
	return Object.entries(brands).map(([value, brand]) => [
		value,
		typeof brand === "string" ? brand : brand?.label ?? brand?.name ?? value,
	]);
}

function itemBrand(item) {
	if (!item) return "";
	if (typeof item.brand === "object") {
		return String(item.brand?.value ?? item.brand?.name ?? "");
	}
	return String(item.brand ?? "");
}

function brandLabel(value, brands) {
	return brandEntries(brands).find(([key]) => key === value)?.[1] || value;
}

export default function MarketingCampaignCollectionForm({
	campaign,
	collection = {},
	data,
	setData,
	errors = {},
	brands = {},
	productSearchUrl,
	maxCollectionItems = 50,
	initialItems = [],
	processing = false,
	onSubmit,
	onCancel,
	submitLabel = "Guardar colección",
	usingLinks = [],
	usingLinksCount = 0,
	showUsingLinks = false,
	compact = false,
	hideHeader = false,
	hideStickyActions = false,
}) {
	const [selectedItems, setSelectedItems] = useState(initialItems);
	const [pendingBrand, setPendingBrand] = useState(null);
	const [brandModalOpen, setBrandModalOpen] = useState(false);
	const [publicTitleTouched, setPublicTitleTouched] = useState(() => {
		if (collection?.public_title && collection?.name) {
			return collection.public_title !== collection.name;
		}

		return Boolean(collection?.public_title);
	});
	const [publicTitleBlurred, setPublicTitleBlurred] = useState(false);

	const syncItems = (items) => {
		setSelectedItems(items);
		setData("laboratory_test_ids", items.map((item) => item.id));
	};

	const applyBrandChange = (value, items) => {
		setData({
			...data,
			laboratory_brand: value,
			laboratory_test_ids: items.map((item) => item.id),
		});
		setSelectedItems(items);
		setPendingBrand(null);
		setBrandModalOpen(false);
	};

	const handleBrandChange = (value) => {
		if (!value || value === data.laboratory_brand) {
			return;
		}

		if (selectedItems.length === 0) {
			setData("laboratory_brand", value);
			return;
		}

		const incompatible = selectedItems.some(
			(item) => itemBrand(item) !== String(value),
		);

		if (!incompatible) {
			setData("laboratory_brand", value);
			return;
		}

		setPendingBrand(value);
		setBrandModalOpen(true);
	};

	const confirmBrandChange = () => {
		if (!pendingBrand) return;
		applyBrandChange(pendingBrand, []);
	};

	const handleNameChange = (value) => {
		if (!publicTitleTouched) {
			setData({
				...data,
				name: value,
				public_title: value,
			});
			return;
		}

		setData("name", value);
	};

	const handlePublicTitleChange = (value) => {
		setPublicTitleTouched(true);
		setData("public_title", value);
	};

	const publicTitleError =
		errors.public_title ||
		((publicTitleTouched || publicTitleBlurred) &&
		!String(data.public_title || "").trim()
			? "El título público es obligatorio."
			: null);

	const collectionHeader = useMemo(
		() => ({
			...collection,
			name: data.name || collection.name,
			laboratory_brand: data.laboratory_brand || collection.laboratory_brand,
			is_active: data.is_active,
		}),
		[collection, data.name, data.laboratory_brand, data.is_active],
	);

	const handleSubmit = (event, returnToCampaign = false) => {
		event.preventDefault();
		if (processing) return;
		onSubmit?.(event, { returnToCampaign });
	};

	return (
		<form
			onSubmit={(event) => handleSubmit(event, false)}
			className="space-y-8 pb-24"
		>
			{!hideHeader && (
				<MarketingCampaignCollectionHeader
					campaign={campaign}
					collection={collectionHeader}
					brands={brands}
					selectedCount={selectedItems.length}
				/>
			)}

			{showUsingLinks && (
				<Card className="space-y-3 p-4">
					<Text className="font-semibold">
						Usada en {usingLinksCount} enlace
						{usingLinksCount === 1 ? "" : "s"}
					</Text>
					{usingLinks.length === 0 ? (
						<Text className="text-sm text-zinc-500">
							Ningún enlace utiliza esta colección todavía.
						</Text>
					) : (
						<ul className="space-y-2">
							{usingLinks.map((link) => (
								<li
									key={link.id}
									className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
								>
									<div>
										<Text className="font-medium">
											{link.name}
										</Text>
										<Text className="font-mono text-sm text-zinc-500">
											/c/{link.slug}
										</Text>
									</div>
									<div className="flex flex-wrap items-center gap-2">
										<MarketingCampaignStatusBadge
											status={link.status}
											label={link.status_label}
											kind="link"
										/>
										{link.public_url && (
											<Button
												type="button"
												outline
												onClick={() =>
													window.open(
														link.public_url,
														"_blank",
														"noopener,noreferrer",
													)
												}
											>
												Abrir
											</Button>
										)}
										<Button
											href={route(
												"admin.marketing-campaigns.links.edit",
												{
													marketing_campaign:
														campaign.id,
													marketing_campaign_link:
														link.id,
												},
											)}
											outline
										>
											Editar
										</Button>
									</div>
								</li>
							))}
						</ul>
					)}
				</Card>
			)}

			<section className="space-y-4">
				<div>
					<Text className="font-semibold">
						Información de la colección
					</Text>
					<Text className="mt-1 text-sm text-zinc-500">
						Una colección es un grupo reutilizable de estudios de una
						sola marca. Puede utilizarse en uno o varios enlaces de
						esta campaña.
					</Text>
				</div>

				<div className="grid gap-4">
					<Field>
						<Label>Nombre interno</Label>
						<Input
							autoFocus={!compact}
							value={data.name || ""}
							onChange={(e) => handleNameChange(e.target.value)}
						/>
						{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
					</Field>

					<Field>
						<Label>Título público</Label>
						<Input
							value={data.public_title || ""}
							onChange={(e) =>
								handlePublicTitleChange(e.target.value)
							}
							onBlur={() => setPublicTitleBlurred(true)}
						/>
						{publicTitleError && (
							<ErrorMessage>{publicTitleError}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Descripción pública</Label>
						<Textarea
							rows={3}
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

					<Field>
						<Label>Marca de laboratorio</Label>
						<Listbox
							value={data.laboratory_brand || ""}
							onChange={handleBrandChange}
							placeholder="Seleccionar marca"
						>
							{brandEntries(brands).map(([value, label]) => (
								<ListboxOption key={value} value={value}>
									<ListboxLabel>{label}</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
						{errors.laboratory_brand && (
							<ErrorMessage>
								{errors.laboratory_brand}
							</ErrorMessage>
						)}
					</Field>

					<CheckboxField>
						<Checkbox
							checked={Boolean(data.is_active)}
							onChange={(checked) => setData("is_active", checked)}
						/>
						<Label>Colección activa</Label>
					</CheckboxField>
				</div>
			</section>

			<section className="space-y-4">
				<div className="flex flex-wrap items-end justify-between gap-3">
					<div>
						<Text className="font-semibold">
							Estudios seleccionados
						</Text>
						<Text className="mt-1 text-sm text-zinc-500">
							{data.laboratory_brand
								? `Buscando dentro de ${brandLabel(data.laboratory_brand, brands)}.`
								: "Selecciona una marca para buscar estudios."}
						</Text>
					</div>
					<Text className="text-sm font-medium">
						{selectedItems.length} estudio
						{selectedItems.length === 1 ? "" : "s"} seleccionado
						{selectedItems.length === 1 ? "" : "s"}
					</Text>
				</div>

				<MarketingCampaignProductSelector
					brand={data.laboratory_brand}
					selectedItems={selectedItems}
					onChange={syncItems}
					productSearchUrl={productSearchUrl}
					maxItems={maxCollectionItems}
					variant="collection"
					showSelectedCount={false}
					error={
						errors.laboratory_test_ids ||
						errors["laboratory_test_ids.0"]
					}
					emptyMessage="Todavía no has agregado estudios. Puedes guardar la colección vacía y completarla después."
					addLabel="Buscar estudios"
				/>

				<MarketingCampaignCollectionPricingSummary items={selectedItems} />
			</section>

			{!hideStickyActions && (
				<MarketingCampaignCollectionStickyActions
					processing={processing}
					onCancel={onCancel}
					onSave={(event) => handleSubmit(event, false)}
					onSaveAndReturn={(event) => handleSubmit(event, true)}
					saveLabel={submitLabel}
					showSaveAndReturn={Boolean(onCancel)}
				/>
			)}

			<MarketingCampaignCollectionBrandChangeModal
				isOpen={brandModalOpen}
				close={() => {
					setBrandModalOpen(false);
					setPendingBrand(null);
				}}
				brandLabel={brandLabel(pendingBrand, brands)}
				productCount={selectedItems.filter(
					(item) => itemBrand(item) !== String(pendingBrand),
				).length}
				onConfirm={confirmBrandChange}
			/>
		</form>
	);
}
