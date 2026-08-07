import { useCallback, useEffect, useMemo, useState } from "react";
import { router } from "@inertiajs/react";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import Card from "@/Components/Card";
import MarketingCampaignDateRangeFields from "../Components/MarketingCampaignDateRangeFields";
import MarketingCampaignHeroImageFields from "../Components/MarketingCampaignHeroImageFields";
import MarketingCampaignGalleryFields from "../Components/MarketingCampaignGalleryFields";
import MarketingCampaignProductSelector from "../Components/MarketingCampaignProductSelector";
import MarketingCampaignUtmFields from "../Components/MarketingCampaignUtmFields";
import MarketingCampaignLandingPreview from "../Components/MarketingCampaignLandingPreview";
import MarketingCampaignCollectionPreview from "../Components/MarketingCampaignCollectionPreview";
import MarketingCampaignCollectionInlinePanel from "../Components/MarketingCampaignCollectionInlinePanel";
import MarketingCampaignCollectionPricingSummary from "../Components/MarketingCampaignCollectionPricingSummary";
import MarketingCampaignWizardStepper from "./MarketingCampaignWizardStepper";
import {
	getSteps,
	initialWizardState,
	slugify,
	validateStep,
	applySmartDefaults,
} from "./wizardDefaults";
import { buildLinkPayload, buildSetupPayload } from "./wizardSubmit";
import { clearDraft, loadDraft, saveDraft } from "./wizardStorage";

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

function brandEntries(brands) {
	if (!brands) return [];
	return Object.entries(brands).map(([value, brand]) => [
		value,
		typeof brand === "string" ? brand : brand?.label ?? brand?.name ?? value,
	]);
}

function formatPrice(product) {
	if (!product?.famedic_price_cents && product?.famedic_price_cents !== 0) {
		return null;
	}
	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
	}).format(product.famedic_price_cents / 100);
}

function ErrorSummary({ errors }) {
	const entries = Object.entries(errors || {});
	if (!entries.length) return null;

	return (
		<div
			className="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40"
			role="alert"
		>
			<Text className="font-medium text-red-800 dark:text-red-200">
				Revisa los siguientes campos:
			</Text>
			<ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700 dark:text-red-300">
				{entries.map(([key, message]) => (
					<li key={key}>{message}</li>
				))}
			</ul>
		</div>
	);
}

export default function MarketingCampaignSetupWizard({
	mode = "campaign",
	campaign = null,
	statusOptions = [],
	linkStatusOptions = [],
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
	utmPresets = [],
	promotionOptions = [],
	maxCollectionItems = 50,
}) {
	const campaignId = campaign?.id ?? null;
	const steps = useMemo(() => getSteps(mode), [mode]);
	const [localCollections, setLocalCollections] = useState(collections);
	const [inlinePanelOpen, setInlinePanelOpen] = useState(false);
	const [inlineNotice, setInlineNotice] = useState("");

	useEffect(() => {
		setLocalCollections(collections);
	}, [collections]);

	const mergedCollections = localCollections;
	const context = useMemo(
		() => ({ brands, categories, collections: mergedCollections }),
		[brands, categories, mergedCollections],
	);

	const [state, setState] = useState(() => {
		const draft = loadDraft(mode, campaignId);
		return initialWizardState(mode, { campaign, initialDraft: draft });
	});
	const [stepErrors, setStepErrors] = useState({});
	const [processing, setProcessing] = useState(false);
	const [serverErrors, setServerErrors] = useState({});

	useEffect(() => {
		saveDraft(mode, state, campaignId);
	}, [mode, campaignId, state]);

	const currentStepDef = steps[state.step] || steps[0];
	const linkStatusList = linkStatusOptions.length
		? linkStatusOptions
		: statusOptions;

	const patchState = useCallback((patch) => {
		setState((prev) => ({ ...prev, ...patch }));
	}, []);

	const patchLink = useCallback((key, value) => {
		setState((prev) => ({
			...prev,
			link: { ...prev.link, [key]: value },
		}));
	}, []);

	const patchCampaign = useCallback((key, value) => {
		setState((prev) => ({
			...prev,
			campaign: { ...prev.campaign, [key]: value },
			link:
				key === "name" && !prev.slugTouched
					? {
							...prev.link,
							name: prev.link.name || value,
							slug: slugify(value),
							utm_campaign: slugify(value),
						}
					: prev.link,
		}));
	}, []);

	const goToStep = (index) => {
		if (index < 0 || index >= steps.length) return;
		setStepErrors({});
		setState((prev) => ({ ...prev, step: index }));
	};

	const validateCurrentStep = () => {
		const errors = validateStep(currentStepDef.id, state, mode);
		setStepErrors(errors);
		return Object.keys(errors).length === 0;
	};

	const continueStep = () => {
		if (!validateCurrentStep()) return;
		const next = Math.min(state.step + 1, steps.length - 1);
		if (next !== state.step) {
			const withDefaults =
				steps[next]?.id === "content" && !state.contentTouched
					? applySmartDefaults(state, context)
					: state;
			setState({ ...withDefaults, step: next });
			setStepErrors({});
		}
	};

	const discardDraft = () => {
		clearDraft(mode, campaignId);
		setState(initialWizardState(mode, { campaign }));
		setStepErrors({});
		setServerErrors({});
	};

	const submit = (activate = false) => {
		const errors = validateStep("preview", state, mode);
		if (Object.keys(errors).length) {
			setStepErrors(errors);
			return;
		}

		setProcessing(true);
		setServerErrors({});

		if (mode === "campaign") {
			const payload = buildSetupPayload(state, context, activate);
			router.post(route("admin.marketing-campaigns.setup.store"), payload, {
				forceFormData: true,
				onFinish: () => setProcessing(false),
				onSuccess: () => clearDraft(mode, campaignId),
				onError: (errs) => {
					setServerErrors(errs);
					setProcessing(false);
				},
			});
			return;
		}

		const linkPayload = buildLinkPayload(state, context);
		router.post(
			route("admin.marketing-campaigns.links.store", campaignId),
			{
				...linkPayload,
				status: activate ? "active" : linkPayload.status,
			},
			{
				forceFormData: true,
				onFinish: () => setProcessing(false),
				onSuccess: () => clearDraft(mode, campaignId),
				onError: (errs) => {
					setServerErrors(errs);
					setProcessing(false);
				},
			},
		);
	};

	const prepared = useMemo(
		() => applySmartDefaults(state, context),
		[state, context],
	);

	const previewBrand =
		brands[prepared.brand] ||
		brands[prepared.link?.target_payload?.brand] ||
		null;

	const previewProducts = (prepared.primaryProducts.length
		? prepared.primaryProducts
		: prepared.product
			? [prepared.product]
			: []
	).map((product) => ({
		...product,
		price_label: formatPrice(product),
	}));

	const previewGallery = (prepared.galleryItems || [])
		.map((item) => ({
			key: item.key || item.id,
			url:
				item.url ||
				(item.file instanceof File ? URL.createObjectURL(item.file) : null),
			alt: item.alt,
		}))
		.filter((item) => item.url);

	const heroPreviewUrl =
		prepared.link.hero_image_source === "external"
			? prepared.link.hero_image_url
			: prepared.link.hero_image instanceof File
				? URL.createObjectURL(prepared.link.hero_image)
				: prepared.heroPreviewUrl;

	const publicUrl = prepared.link.slug
		? `${window.location.origin}/c/${prepared.link.slug}`
		: "";

	const applyPreset = (presetValue) => {
		const preset = utmPresets.find((item) => item.value === presetValue);
		patchState({
			utmPreset: presetValue,
			link: {
				...state.link,
				utm_source: preset?.source ?? state.link.utm_source,
				utm_medium: preset?.medium ?? state.link.utm_medium,
			},
		});
	};

	const renderGeneralStep = () => (
		<div className="space-y-4">
			<Field>
				<Label>Nombre de campaña</Label>
				<Input
					autoFocus
					value={state.campaign.name}
					onChange={(e) => patchCampaign("name", e.target.value)}
				/>
				{stepErrors["campaign.name"] && (
					<ErrorMessage>{stepErrors["campaign.name"]}</ErrorMessage>
				)}
			</Field>
			<Field>
				<Label>Descripción interna</Label>
				<Textarea
					value={state.campaign.description}
					onChange={(e) =>
						patchCampaign("description", e.target.value)
					}
					rows={3}
				/>
			</Field>
			<Field>
				<Label>Estado inicial</Label>
				<Listbox
					value={state.campaign.status}
					onChange={(value) => patchCampaign("status", value)}
				>
					{optionEntries(statusOptions).map(([value, label]) => (
						<ListboxOption key={value} value={value}>
							<ListboxLabel>{label}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
				<Text className="mt-1 text-sm text-zinc-500">
					Borrador: trabajo en progreso. Activa: visible según vigencia
					del enlace.
				</Text>
			</Field>
			<MarketingCampaignDateRangeFields
				data={state.campaign}
				setData={(key, value) => patchCampaign(key, value)}
				errors={stepErrors}
			/>
		</div>
	);

	const renderPromotionStep = () => (
		<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
			{promotionOptions.map((option) => {
				const disabled =
					option.value === "existing_collection" &&
					mode === "campaign" &&
					!collections.length;
				const selected = state.promotion === option.value;

				return (
					<button
						key={option.value}
						type="button"
						disabled={disabled}
						onClick={() =>
							patchState({
								promotion: option.value,
								brand: "",
								categoryId: "",
								product: null,
								collectionId: "",
							})
						}
						className={`rounded-xl border p-4 text-left transition ${
							selected
								? "border-famedic-light bg-famedic-light/10 ring-2 ring-famedic-light/40"
								: "border-zinc-200 hover:border-zinc-300 dark:border-zinc-700"
						} ${disabled ? "cursor-not-allowed opacity-50" : ""}`}
					>
						<Text className="font-semibold">{option.label}</Text>
						<Text className="mt-1 text-sm text-zinc-500">
							{option.description}
						</Text>
						{disabled && (
							<Text className="mt-2 text-xs text-amber-600">
								Disponible al crear enlaces en una campaña
								existente.
							</Text>
						)}
					</button>
				);
			})}
			{stepErrors.promotion && (
				<ErrorMessage>{stepErrors.promotion}</ErrorMessage>
			)}
		</div>
	);

	const selectedCollection = mergedCollections.find(
		(item) => Number(item.id) === Number(state.collectionId),
	);

	const handleInlineCollectionCreated = (collection) => {
		setLocalCollections((current) => {
			const exists = current.some(
				(item) => Number(item.id) === Number(collection.id),
			);
			if (exists) {
				return current.map((item) =>
					Number(item.id) === Number(collection.id)
						? { ...item, ...collection }
						: item,
				);
			}
			return [...current, collection];
		});
		patchState({
			promotion: "existing_collection",
			collectionId: collection.id,
			newCollection: {
				name: "",
				public_title: "",
				public_description: "",
				laboratory_brand: "",
				laboratory_test_ids: [],
			},
			primaryProducts: [],
		});
		setInlineNotice("Colección creada y seleccionada.");
	};

	const renderProductsStep = () => {
		const { promotion } = state;

		if (promotion === "new_collection") {
			if (mode === "link" && campaign?.id) {
				return (
					<div className="space-y-4">
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Crea la colección con el mismo formulario completo
							sin salir del asistente.
						</Text>
						<Button
							type="button"
							color="lime"
							onClick={() => setInlinePanelOpen(true)}
						>
							Crear colección de estudios
						</Button>
						{inlineNotice && (
							<Text className="text-sm text-emerald-700 dark:text-emerald-400">
								{inlineNotice}
							</Text>
						)}
						<MarketingCampaignCollectionInlinePanel
							open={inlinePanelOpen}
							onClose={() => setInlinePanelOpen(false)}
							campaign={campaign}
							brands={brands}
							productSearchUrl={productSearchUrl}
							maxCollectionItems={maxCollectionItems}
							onCreated={handleInlineCollectionCreated}
						/>
					</div>
				);
			}

			return (
				<div className="space-y-4">
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Una colección es un grupo reutilizable de estudios de
						una sola marca. Se creará junto con la campaña al
						finalizar el asistente.
					</Text>
					<Field>
						<Label>Nombre interno</Label>
						<Input
							value={state.newCollection.name}
							onChange={(e) =>
								patchState({
									newCollection: {
										...state.newCollection,
										name: e.target.value,
									},
								})
							}
						/>
						{stepErrors["newCollection.name"] && (
							<ErrorMessage>
								{stepErrors["newCollection.name"]}
							</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Título público</Label>
						<Input
							value={state.newCollection.public_title}
							onChange={(e) =>
								patchState({
									newCollection: {
										...state.newCollection,
										public_title: e.target.value,
									},
								})
							}
						/>
					</Field>
					<Field>
						<Label>Descripción pública</Label>
						<Textarea
							value={state.newCollection.public_description}
							onChange={(e) =>
								patchState({
									newCollection: {
										...state.newCollection,
										public_description: e.target.value,
									},
								})
							}
							rows={3}
						/>
					</Field>
					<Field>
						<Label>Marca de laboratorio</Label>
						<Listbox
							value={state.newCollection.laboratory_brand}
							onChange={(value) =>
								patchState({
									newCollection: {
										...state.newCollection,
										laboratory_brand: value,
									},
									brand: value,
								})
							}
						>
							{brandEntries(brands).map(([value, label]) => (
								<ListboxOption key={value} value={value}>
									<ListboxLabel>{label}</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
						{stepErrors["newCollection.laboratory_brand"] && (
							<ErrorMessage>
								{stepErrors["newCollection.laboratory_brand"]}
							</ErrorMessage>
						)}
					</Field>
					{state.newCollection.laboratory_brand && (
						<>
							<MarketingCampaignProductSelector
								brand={state.newCollection.laboratory_brand}
								productSearchUrl={productSearchUrl}
								selectedItems={state.primaryProducts}
								onChange={(items) =>
									patchState({ primaryProducts: items })
								}
								variant="collection"
								maxItems={maxCollectionItems}
								emptyMessage="Todavía no has agregado estudios. Puedes guardar la colección vacía y completarla después."
							/>
							<MarketingCampaignCollectionPricingSummary
								items={state.primaryProducts}
							/>
						</>
					)}
				</div>
			);
		}

		return (
			<div className="space-y-4">
				{["brand", "category", "multiple_products"].includes(
					promotion,
				) && (
					<Field>
						<Label>Marca</Label>
						<Listbox
							value={state.brand}
							onChange={(value) => patchState({ brand: value })}
						>
							{brandEntries(brands).map(([value, label]) => (
								<ListboxOption key={value} value={value}>
									<ListboxLabel>{label}</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
						{stepErrors.brand && (
							<ErrorMessage>{stepErrors.brand}</ErrorMessage>
						)}
					</Field>
				)}

				{promotion === "category" && (
					<Field>
						<Label>Categoría</Label>
						<Listbox
							value={state.categoryId ? String(state.categoryId) : ""}
							onChange={(value) =>
								patchState({ categoryId: Number(value) })
							}
						>
							{categories.map((category) => (
								<ListboxOption
									key={category.id}
									value={String(category.id)}
								>
									<ListboxLabel>{category.name}</ListboxLabel>
								</ListboxOption>
							))}
						</Listbox>
						{stepErrors.categoryId && (
							<ErrorMessage>{stepErrors.categoryId}</ErrorMessage>
						)}
					</Field>
				)}

				{promotion === "product" && (
					<MarketingCampaignProductSelector
						productSearchUrl={productSearchUrl}
						maxItems={1}
						selectedItems={state.product ? [state.product] : []}
						onChange={(items) =>
							patchState({
								product: items[0] || null,
								brand: items[0]?.brand || state.brand,
							})
						}
						emptyMessage="Busca y selecciona un estudio."
						addLabel="Estudio seleccionado"
					/>
				)}

				{promotion === "existing_collection" && (
					<>
						<Field>
							<Label>Colección de estudios</Label>
							<Listbox
								value={
									state.collectionId
										? String(state.collectionId)
										: ""
								}
								onChange={(value) =>
									patchState({
										collectionId: Number(value),
									})
								}
							>
								{mergedCollections.map((collection) => (
									<ListboxOption
										key={collection.id}
										value={String(collection.id)}
									>
										<ListboxLabel>
											{collection.public_title ||
												collection.name}
										</ListboxLabel>
									</ListboxOption>
								))}
							</Listbox>
							{stepErrors.collectionId && (
								<ErrorMessage>
									{stepErrors.collectionId}
								</ErrorMessage>
							)}
						</Field>
						{selectedCollection && (
							<MarketingCampaignCollectionPreview
								collection={selectedCollection}
								brands={brands}
								previewItems={
									selectedCollection.preview_items || []
								}
								campaignId={campaignId}
								onEdit={() =>
									window.open(
										route(
											"admin.marketing-campaigns.collections.edit",
											{
												marketing_campaign: campaignId,
												marketing_campaign_collection:
													selectedCollection.id,
											},
										),
										"_blank",
										"noopener,noreferrer",
									)
								}
							/>
						)}
					</>
				)}

				{["brand", "category", "multiple_products"].includes(
					promotion,
				) &&
					state.brand && (
						<div className="space-y-3">
							<Text className="text-sm font-medium">
								Productos destacados (opcional)
							</Text>
							<MarketingCampaignProductSelector
								brand={state.brand}
								productSearchUrl={productSearchUrl}
								selectedItems={state.primaryProducts}
								onChange={(items) =>
									patchState({ primaryProducts: items })
								}
							/>
						</div>
					)}
			</div>
		);
	};

	const renderContentStep = () => (
		<div className="space-y-6">
			<div className="grid gap-4 sm:grid-cols-2">
				<Field>
					<Label>Texto superior</Label>
					<Input
						value={state.link.eyebrow}
						onChange={(e) => {
							patchState({ contentTouched: true });
							patchLink("eyebrow", e.target.value);
						}}
					/>
				</Field>
				<Field>
					<Label>Título</Label>
					<Input
						value={state.link.public_title}
						onChange={(e) => {
							patchState({ contentTouched: true });
							patchLink("public_title", e.target.value);
						}}
					/>
					{stepErrors["link.public_title"] && (
						<ErrorMessage>
							{stepErrors["link.public_title"]}
						</ErrorMessage>
					)}
				</Field>
				<Field className="sm:col-span-2">
					<Label>Subtítulo</Label>
					<Input
						value={state.link.public_subtitle}
						onChange={(e) => {
							patchState({ contentTouched: true });
							patchLink("public_subtitle", e.target.value);
						}}
					/>
				</Field>
				<Field className="sm:col-span-2">
					<Label>Descripción</Label>
					<Textarea
						value={state.link.public_description}
						onChange={(e) => {
							patchState({ contentTouched: true });
							patchLink("public_description", e.target.value);
						}}
						rows={4}
					/>
				</Field>
				<Field>
					<Label>CTA principal</Label>
					<Input
						value={state.link.primary_cta_label}
						onChange={(e) =>
							patchLink("primary_cta_label", e.target.value)
						}
					/>
				</Field>
				<Field>
					<Label>CTA secundario</Label>
					<Input
						value={state.link.secondary_cta_label}
						onChange={(e) =>
							patchLink("secondary_cta_label", e.target.value)
						}
					/>
				</Field>
			</div>

			<MarketingCampaignHeroImageFields
				data={state.link}
				setData={(key, value) => {
					if (typeof key === "object") {
						setState((prev) => ({
							...prev,
							link: { ...prev.link, ...key },
						}));
						return;
					}
					patchLink(key, value);
				}}
				errors={{ ...stepErrors, ...serverErrors }}
				previewUrl={heroPreviewUrl}
			/>

			<MarketingCampaignGalleryFields
				items={state.galleryItems}
				onChange={(items) => patchState({ galleryItems: items })}
				errors={serverErrors}
			/>
		</div>
	);

	const renderChannelStep = () => (
		<div className="space-y-6">
			<div className="grid gap-4 sm:grid-cols-2">
				<Field>
					<Label>Nombre interno del enlace</Label>
					<Input
						value={state.link.name}
						onChange={(e) => {
							const value = e.target.value;
							setState((prev) => ({
								...prev,
								link: {
									...prev.link,
									name: value,
									slug: prev.slugTouched
										? prev.link.slug
										: slugify(value),
								},
							}));
						}}
					/>
					{stepErrors["link.name"] && (
						<ErrorMessage>{stepErrors["link.name"]}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>Dirección del enlace</Label>
					<Input
						value={state.link.slug}
						onChange={(e) => {
							patchState({ slugTouched: true });
							patchLink("slug", slugify(e.target.value));
						}}
					/>
					<Text className="mt-1 font-mono text-sm text-zinc-500">
						/c/{state.link.slug || "…"}
					</Text>
					{stepErrors["link.slug"] && (
						<ErrorMessage>{stepErrors["link.slug"]}</ErrorMessage>
					)}
				</Field>
			</div>

			<Field>
				<Label>Preset de canal</Label>
				<Listbox
					value={state.utmPreset}
					onChange={applyPreset}
					placeholder="Elegir preset (opcional)"
				>
					{utmPresets.map((preset) => (
						<ListboxOption key={preset.value} value={preset.value}>
							<ListboxLabel>{preset.label}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
			</Field>

			<MarketingCampaignUtmFields
				data={state.link}
				setData={patchLink}
				errors={serverErrors}
				friendlyLabels
			/>
		</div>
	);

	const renderPreviewStep = () => (
		<div className="space-y-6">
			<Card className="space-y-3 p-5">
				<Text className="font-semibold">Resumen</Text>
				{mode === "campaign" && (
					<Text>Campaña: {prepared.campaign.name}</Text>
				)}
				<Text>
					Destino:{" "}
					{promotionOptions.find((o) => o.value === prepared.promotion)
						?.label || prepared.promotion}
				</Text>
				<Text>Dirección: /c/{prepared.link.slug}</Text>
				{publicUrl && (
					<Text className="break-all font-mono text-sm">{publicUrl}</Text>
				)}
			</Card>

			<MarketingCampaignLandingPreview
				content={{
					eyebrow: prepared.link.eyebrow,
					public_title: prepared.link.public_title,
					public_subtitle: prepared.link.public_subtitle,
					public_description: prepared.link.public_description,
					hero_url: heroPreviewUrl,
					hero_alt: prepared.link.hero_image_alt,
				}}
				brand={
					previewBrand
						? {
								label: previewBrand.label || previewBrand.name,
								logo_url: previewBrand.logo_url,
							}
						: null
				}
				products={previewProducts}
				relatedProducts={prepared.relatedProducts}
				gallery={previewGallery}
				showPrices={prepared.link.show_prices}
				showLogo={prepared.link.show_brand_logo}
				primaryAction={{ label: prepared.link.primary_cta_label }}
				secondaryAction={{ label: prepared.link.secondary_cta_label }}
			/>
		</div>
	);

	const stepContent = {
		general: renderGeneralStep,
		promotion: renderPromotionStep,
		products: renderProductsStep,
		content: renderContentStep,
		channel: renderChannelStep,
		preview: renderPreviewStep,
	}[currentStepDef.id]?.();

	return (
		<div className="space-y-8">
			<MarketingCampaignWizardStepper
				steps={steps}
				currentStep={state.step}
				onStepSelect={goToStep}
			/>

			<ErrorSummary errors={{ ...stepErrors, ...serverErrors }} />

			<section className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
				<Text className="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">
					{currentStepDef.label}
				</Text>
				{stepContent}
			</section>

			<div className="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white/95 p-4 shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
				<div className="flex flex-wrap gap-2">
					{state.step > 0 && (
						<Button
							type="button"
							outline
							onClick={() => goToStep(state.step - 1)}
						>
							Atrás
						</Button>
					)}
					<Button type="button" plain onClick={discardDraft}>
						Descartar borrador
					</Button>
				</div>
				<div className="flex flex-wrap gap-2">
					{currentStepDef.id !== "preview" ? (
						<Button type="button" color="lime" onClick={continueStep}>
							Continuar
						</Button>
					) : (
						<>
							<Button
								type="button"
								outline
								disabled={processing}
								onClick={() => submit(false)}
							>
								{processing ? "Guardando…" : "Guardar borrador"}
							</Button>
							<Button
								type="button"
								color="lime"
								disabled={processing}
								onClick={() => submit(true)}
							>
								{processing ? "Guardando…" : "Guardar y activar"}
							</Button>
						</>
					)}
				</div>
			</div>
		</div>
	);
}
