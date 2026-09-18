import Card from "@/Components/Card";
import BranchBottomSheet from "@/Components/LaboratoryCart/BranchBottomSheet";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	ArrowPathIcon,
	ChevronDownIcon,
	ChevronRightIcon,
	ClockIcon,
	ExclamationTriangleIcon,
	MapPinIcon,
} from "@heroicons/react/20/solid";
import clsx from "clsx";
import { useCallback, useEffect, useRef, useState } from "react";
import {
	brandSections,
	compatibleBranches,
	compatibleStoresUiState,
	deleteSelectedLaboratoryStore,
	fetchCompatibleLaboratoryStores,
	fetchSelectedLaboratoryStore,
	filterBranchesBySearch,
	formatDistance,
	formatHours,
	groupBranchesByMunicipality,
	nearestCompatibleBranch,
	sanitizePostalCodeInput,
	validateMexicanPostalCodeForSearch,
	normalizeCompatibleStoresResponse,
	postalCodeLocationStatus,
	saveSelectedLaboratoryStore,
	safeReason,
	selectedStoreErrorMessage,
	selectionLabel,
	storeMunicipality,
} from "@/lib/laboratoryCompatibleStores";

const TABS = [
	{ id: "recommended", label: "Recomendadas" },
	{ id: "all", label: "Todas" },
	{ id: "filters", label: "Filtros" },
];

const MAX_INITIAL_MUNICIPALITY_GROUPS = 3;

function sectionIntroCopy(state, locationStatus) {
	if (state !== "ready") {
		return "Famedic puede recomendar sucursales con base en los requisitos conocidos de los estudios de tu carrito.";
	}

	if (locationStatus === "resolved") {
		return "Encontramos sucursales compatibles cerca de ti.";
	}

	return "Encuentra una sucursal compatible para tus estudios.";
}

export default function LaboratoryCompatibleStoresSection({
	cartItemsCount = 0,
	laboratoryBrand,
}) {
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(false);
	const [selectedBranchId, setSelectedBranchId] = useState(null);
	const [selectedStore, setSelectedStore] = useState(null);
	const [savingBranchId, setSavingBranchId] = useState(null);
	const [selectionError, setSelectionError] = useState(null);
	const [search, setSearch] = useState("");
	const [postalCode, setPostalCode] = useState("");
	const [postalCodeToSearch, setPostalCodeToSearch] = useState("");
	const [reloadToken, setReloadToken] = useState(0);
	const clearPostalCodeOnNextLoadRef = useRef(false);
	const [postalCodeError, setPostalCodeError] = useState(null);
	const [desktopExpanded, setDesktopExpanded] = useState(false);
	const [mobileSheetOpen, setMobileSheetOpen] = useState(false);
	const [activeTab, setActiveTab] = useState("recommended");
	const [showAllMunicipalityGroups, setShowAllMunicipalityGroups] =
		useState(false);

	const loadStores = useCallback(async () => {
		if (!laboratoryBrand?.value) {
			return;
		}

		setLoading(true);
		setError(false);
		setSelectionError(null);

		const shouldClearPostalCode = clearPostalCodeOnNextLoadRef.current;

		try {
			const [storesResponse, selectionResponse] = await Promise.all([
				fetchCompatibleLaboratoryStores({
					params: {
						brand: laboratoryBrand.value,
						postal_code: postalCodeToSearch,
						clear_postal_code: shouldClearPostalCode,
					},
				}),
				fetchSelectedLaboratoryStore({
					brand: laboratoryBrand.value,
				}),
			]);

			setData(storesResponse);
			if (
				!postalCodeToSearch &&
				!shouldClearPostalCode &&
				storesResponse?.meta?.postal_code
			) {
				setPostalCode(storesResponse.meta.postal_code);
				setPostalCodeToSearch(storesResponse.meta.postal_code);
			}
			setSelectedBranchId(
				selectionResponse?.selected
					? Number(selectionResponse.store?.id)
					: null,
			);
			setSelectedStore(
				selectionResponse?.selected ? selectionResponse.store : null,
			);
		} catch {
			setError(true);
		} finally {
			clearPostalCodeOnNextLoadRef.current = false;
			setLoading(false);
		}
	}, [laboratoryBrand?.value, postalCodeToSearch, reloadToken]);

	useEffect(() => {
		setSelectedBranchId(null);
		setSelectedStore(null);
		loadStores();
	}, [cartItemsCount, loadStores]);

	const handlePostalCodeChange = useCallback((value) => {
		const digits = sanitizePostalCodeInput(value);

		if (digits.length > 5) {
			setPostalCodeError(
				"El código postal debe tener exactamente 5 dígitos.",
			);
			setPostalCode(digits);
			return;
		}

		setPostalCodeError(null);
		setPostalCode(digits);
	}, []);

	const handlePostalCodeSubmit = useCallback(
		(event) => {
			event.preventDefault();
			const validation = validateMexicanPostalCodeForSearch(postalCode);

			if (!validation.valid) {
				setPostalCodeError(validation.error);
				return;
			}

			setPostalCodeError(null);
			setPostalCodeToSearch(validation.postalCode);
		},
		[postalCode],
	);

	const handlePostalCodeClear = useCallback(() => {
		setPostalCode("");
		setPostalCodeToSearch("");
		setPostalCodeError(null);
		clearPostalCodeOnNextLoadRef.current = true;
		setReloadToken((token) => token + 1);
	}, []);

	const handleSelect = useCallback(
		async (branch, { closeMobileSheet = false } = {}) => {
			if (!laboratoryBrand?.value || savingBranchId !== null) {
				return;
			}

			setSelectionError(null);
			setSavingBranchId(branch.id);

			try {
				if (selectedBranchId === branch.id) {
					await deleteSelectedLaboratoryStore({
						brand: laboratoryBrand.value,
					});
					setSelectedBranchId(null);
					setSelectedStore(null);
					if (closeMobileSheet) {
						setMobileSheetOpen(false);
					}
					return;
				}

				const response = await saveSelectedLaboratoryStore({
					brand: laboratoryBrand.value,
					laboratoryStoreId: branch.id,
				});

				setSelectedBranchId(
					response.selected ? Number(response.store?.id) : null,
				);
				setSelectedStore(response.selected ? response.store : null);

				if (closeMobileSheet) {
					setMobileSheetOpen(false);
				}
			} catch (err) {
				const message = selectedStoreErrorMessage(err);
				setSelectedBranchId(null);
				setSelectedStore(null);

				if (err?.response?.status === 422) {
					await loadStores();
				}

				setSelectionError(message);
			} finally {
				setSavingBranchId(null);
			}
		},
		[
			laboratoryBrand?.value,
			loadStores,
			savingBranchId,
			selectedBranchId,
		],
	);

	const state = compatibleStoresUiState({ loading, error, data });
	const normalized = normalizeCompatibleStoresResponse(data);
	const locationStatus = postalCodeLocationStatus(normalized);

	const storesPanel = (
		<StoresPanel
			state={state}
			normalized={normalized}
			locationStatus={locationStatus}
			postalCode={postalCode}
			onPostalCodeChange={handlePostalCodeChange}
			onPostalCodeSubmit={handlePostalCodeSubmit}
			onPostalCodeClear={handlePostalCodeClear}
			postalCodeError={postalCodeError}
			loading={loading}
			onRetry={loadStores}
			activeTab={activeTab}
			onTabChange={setActiveTab}
			selectedBranchId={selectedBranchId}
			onSelect={handleSelect}
			savingBranchId={savingBranchId}
			selectionError={selectionError}
			search={search}
			onSearchChange={setSearch}
			showAllMunicipalityGroups={showAllMunicipalityGroups}
			onShowAllMunicipalityGroups={() =>
				setShowAllMunicipalityGroups(true)
			}
			isMobileSheet={mobileSheetOpen}
		/>
	);

	if (cartItemsCount === 0) {
		return null;
	}

	return (
		<section
			className="mt-10 lg:mt-12"
			aria-labelledby="compatible-stores-heading"
			aria-live="polite"
		>
			<PreferredStoreChip
				store={selectedStore}
				onClear={() => {
					if (!laboratoryBrand?.value || savingBranchId !== null) {
						return;
					}

					setSavingBranchId(selectedBranchId);
					deleteSelectedLaboratoryStore({
						brand: laboratoryBrand.value,
					})
						.then(() => {
							setSelectedBranchId(null);
							setSelectedStore(null);
						})
						.catch((err) => {
							setSelectionError(selectedStoreErrorMessage(err));
						})
						.finally(() => setSavingBranchId(null));
				}}
				clearing={savingBranchId === selectedBranchId}
			/>

			<div className="lg:hidden">
				<button
					type="button"
					onClick={() => setMobileSheetOpen(true)}
					className="flex w-full items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-left transition hover:border-famedic-light/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light dark:border-slate-800 dark:bg-slate-900"
				>
					<span className="flex min-w-0 items-start gap-3">
						<MapPinIcon className="mt-0.5 size-5 shrink-0 text-famedic-light" />
						<span>
							<span
								id="compatible-stores-heading-mobile"
								className="block font-medium text-zinc-950 dark:text-white"
							>
								Encontrar sucursal compatible
							</span>
							<span className="mt-0.5 block text-sm text-zinc-600 dark:text-slate-400">
								Opcional · Por código postal
							</span>
						</span>
					</span>
					<ChevronRightIcon className="size-5 shrink-0 text-zinc-400" />
				</button>

				<BranchBottomSheet
					open={mobileSheetOpen}
					onClose={() => setMobileSheetOpen(false)}
				>
					{storesPanel}
				</BranchBottomSheet>
			</div>

			<div className="hidden lg:block">
				<button
					type="button"
					onClick={() => setDesktopExpanded((current) => !current)}
					className="flex w-full items-start justify-between gap-4 rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 text-left transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light dark:border-slate-800 dark:bg-slate-900/50 dark:hover:bg-slate-900"
					aria-expanded={desktopExpanded}
					aria-controls="compatible-stores-panel"
				>
					<span className="flex gap-3">
						<MapPinIcon className="mt-0.5 size-5 shrink-0 text-famedic-light" />
						<span>
							<Subheading
								id="compatible-stores-heading"
								className="text-base"
							>
								¿Quieres encontrar una sucursal?
							</Subheading>
							<Text className="mt-1 text-sm">
								Busca sucursales compatibles por código postal.
							</Text>
						</span>
					</span>
					<ChevronDownIcon
						className={clsx(
							"size-5 shrink-0 text-zinc-500 transition",
							desktopExpanded && "rotate-180",
						)}
					/>
				</button>

				{desktopExpanded && (
					<div
						id="compatible-stores-panel"
						className="mt-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
					>
						{storesPanel}
					</div>
				)}
			</div>
		</section>
	);
}

function PreferredStoreChip({ store, onClear, clearing = false }) {
	if (!store) {
		return null;
	}

	return (
		<Card className="mb-4 border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20">
			<div className="flex items-start justify-between gap-3">
				<div>
					<Text className="text-sm font-medium text-emerald-900 dark:text-emerald-200">
						📍 Sucursal preferida
					</Text>
					<Subheading className="mt-1 text-base">{store.name}</Subheading>
					<Text className="mt-0.5 text-sm text-zinc-700 dark:text-slate-300">
						{store.municipality ||
							store.city ||
							store.address ||
							""}
					</Text>
					<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
						La cita y horario se confirman contigo después.
					</Text>
				</div>
				<Button
					type="button"
					outline
					className="!py-2 text-sm"
					onClick={onClear}
					disabled={clearing}
				>
					{clearing ? "Quitando..." : "Quitar"}
				</Button>
			</div>
		</Card>
	);
}

function StoresPanel({
	state,
	normalized,
	locationStatus,
	postalCode,
	onPostalCodeChange,
	onPostalCodeSubmit,
	onPostalCodeClear,
	postalCodeError,
	loading,
	onRetry,
	activeTab,
	onTabChange,
	selectedBranchId,
	onSelect,
	savingBranchId,
	selectionError,
	search,
	onSearchChange,
	showAllMunicipalityGroups,
	onShowAllMunicipalityGroups,
	isMobileSheet = false,
}) {
	return (
		<div className="space-y-5">
			<Text className="text-sm text-zinc-600 dark:text-slate-400">
				{sectionIntroCopy(state, locationStatus)}
			</Text>

			<PostalCodePanel
				postalCode={postalCode}
				onPostalCodeChange={onPostalCodeChange}
				onSubmit={onPostalCodeSubmit}
				onClear={onPostalCodeClear}
				error={postalCodeError}
				status={locationStatus}
				appliedPostalCode={normalized.meta.postal_code}
				disabled={loading}
			/>

			{state === "loading" && <CompatibleStoresSkeleton />}

			{state === "error" && <ErrorState onRetry={onRetry} />}

			{state === "empty" && (
				<NoticeState>
					Agrega estudios a tu carrito para consultar sucursales
					compatibles.
				</NoticeState>
			)}

			{state === "no-compatible" && (
				<NoticeState
					title="No encontramos una sucursal compatible"
					reasons={normalized.reasons}
				>
					No encontramos una sucursal que cumpla con todos los
					requisitos conocidos de los estudios de tu carrito.
				</NoticeState>
			)}

			{state === "unresolved" && <UnresolvedState data={normalized} />}

			{state === "ready" && (
				<>
					<BranchTabBar activeTab={activeTab} onTabChange={onTabChange} />

					{activeTab === "recommended" && (
						<RecommendedBranchesTab
							data={normalized}
							selectedBranchId={selectedBranchId}
							savingBranchId={savingBranchId}
							onSelect={(branch) =>
								onSelect(branch, {
									closeMobileSheet: isMobileSheet,
								})
							}
							hasResolvedPostalCode={locationStatus === "resolved"}
						/>
					)}

					{activeTab === "all" && (
						<AllBranchesTab
							data={normalized}
							selectedBranchId={selectedBranchId}
							savingBranchId={savingBranchId}
							onSelect={(branch) =>
								onSelect(branch, {
									closeMobileSheet: isMobileSheet,
								})
							}
							showAllMunicipalityGroups={showAllMunicipalityGroups}
							onShowAllMunicipalityGroups={
								onShowAllMunicipalityGroups
							}
						/>
					)}

					{activeTab === "filters" && (
						<FiltersTab
							data={normalized}
							search={search}
							onSearchChange={onSearchChange}
							selectedBranchId={selectedBranchId}
							savingBranchId={savingBranchId}
							onSelect={(branch) =>
								onSelect(branch, {
									closeMobileSheet: isMobileSheet,
								})
							}
						/>
					)}

					{selectionError && (
						<NoticeState title="No pudimos guardar la sucursal">
							{selectionError}
						</NoticeState>
					)}

					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						La compatibilidad se basa en los requisitos conocidos de
						tus estudios. No representa una cita confirmada ni
						horario garantizado.
					</Text>
				</>
			)}
		</div>
	);
}

function BranchTabBar({ activeTab, onTabChange }) {
	return (
		<div
			className="flex flex-wrap gap-2"
			role="tablist"
			aria-label="Vista de sucursales"
		>
			{TABS.map((tab) => (
				<button
					key={tab.id}
					type="button"
					role="tab"
					aria-selected={activeTab === tab.id}
					onClick={() => onTabChange(tab.id)}
					className={clsx(
						"rounded-full px-4 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light",
						activeTab === tab.id
							? "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-dark"
							: "bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-slate-800 dark:text-slate-200",
					)}
				>
					{tab.label}
				</button>
			))}
		</div>
	);
}

function RecommendedBranchesTab({
	data,
	selectedBranchId,
	savingBranchId,
	onSelect,
	hasResolvedPostalCode,
}) {
	const sections = brandSections(data);
	const branches = sections[0]?.branches ?? [];
	const compatible = compatibleBranches(branches);
	const featured =
		(hasResolvedPostalCode
			? nearestCompatibleBranch(branches)
			: null) ?? compatible[0] ?? null;
	const alternatives = compatible
		.filter((branch) => branch.id !== featured?.id)
		.slice(0, 3);

	if (!featured && alternatives.length === 0) {
		return (
			<NoticeState>
				No encontramos sucursales compatibles para mostrar.
			</NoticeState>
		);
	}

	return (
		<div className="space-y-4">
			{featured && (
				<div>
					<Subheading className="mb-2 text-sm font-semibold">
						⭐ Recomendada
					</Subheading>
					<Text className="mb-3 text-xs text-zinc-600 dark:text-slate-400">
						Mejor opción según compatibilidad
						{hasResolvedPostalCode ? " y cercanía" : ""}. No reserva
						cita.
					</Text>
					<BranchCard
						branch={featured}
						selected={selectedBranchId === featured.id}
						saving={savingBranchId === featured.id}
						onSelect={() => onSelect(featured)}
						featured
					/>
				</div>
			)}

			{alternatives.length > 0 && (
				<div>
					<Subheading className="mb-3 text-sm">
						{hasResolvedPostalCode
							? "Otras opciones cerca de ti"
							: "Otras opciones compatibles"}
					</Subheading>
					<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
						{alternatives.map((branch) => (
							<BranchCard
								key={`${branch.brand}-${branch.id}`}
								branch={branch}
								selected={selectedBranchId === branch.id}
								saving={savingBranchId === branch.id}
								onSelect={() => onSelect(branch)}
								compact
							/>
						))}
					</div>
				</div>
			)}
		</div>
	);
}

function AllBranchesTab({
	data,
	selectedBranchId,
	savingBranchId,
	onSelect,
	showAllMunicipalityGroups,
	onShowAllMunicipalityGroups,
}) {
	const sections = brandSections(data);

	return (
		<div className="space-y-4">
			{sections.map((section) => (
				<div key={section.brand ?? "all"}>
					{sections.length > 1 && (
						<Subheading className="mb-3 text-base">
							{section.brand}
						</Subheading>
					)}
					<MunicipalityGroups
						branches={section.branches}
						selectedBranchId={selectedBranchId}
						savingBranchId={savingBranchId}
						onSelect={onSelect}
						showAllGroups={showAllMunicipalityGroups}
						onShowAllGroups={onShowAllMunicipalityGroups}
					/>
				</div>
			))}
		</div>
	);
}

function FiltersTab({
	data,
	search,
	onSearchChange,
	selectedBranchId,
	savingBranchId,
	onSelect,
}) {
	const sections = brandSections(data);
	const allBranches = sections.flatMap((section) => section.branches);
	const filtered = filterBranchesBySearch(allBranches, search);

	return (
		<div className="space-y-4">
			<div>
				<label
					htmlFor="branch-search-filter"
					className="mb-2 block text-sm font-medium text-zinc-950 dark:text-white"
				>
					Buscar sucursal
				</label>
				<Input
					id="branch-search-filter"
					type="search"
					value={search}
					onChange={(event) => onSearchChange(event.target.value)}
					placeholder="Nombre, colonia o municipio..."
				/>
			</div>

			{filtered.length === 0 ? (
				<NoticeState>
					No encontramos sucursales que coincidan con tu búsqueda.
				</NoticeState>
			) : (
				<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
					{filtered.map((branch) => (
						<BranchCard
							key={`${branch.brand}-${branch.id}`}
							branch={branch}
							selected={selectedBranchId === branch.id}
							saving={savingBranchId === branch.id}
							onSelect={() => onSelect(branch)}
							compact
						/>
					))}
				</div>
			)}
		</div>
	);
}

function PostalCodePanel({
	postalCode,
	onPostalCodeChange,
	onSubmit,
	onClear,
	error,
	status,
	appliedPostalCode,
	disabled,
}) {
	const showResolved = status === "resolved" && appliedPostalCode;
	const showUnresolved = status === "unresolved" && appliedPostalCode;

	return (
		<form
			onSubmit={onSubmit}
			className="rounded-lg border border-zinc-200 bg-zinc-50/50 p-4 dark:border-slate-800 dark:bg-slate-900/40"
		>
			<label
				htmlFor="compatible-stores-postal-code"
				className="text-sm font-medium text-zinc-950 dark:text-white"
			>
				Código postal (opcional)
			</label>
			<Text className="mb-3 text-sm">
				Te ayuda a ordenar sucursales compatibles.
			</Text>
			<div className="flex flex-col gap-2 sm:flex-row sm:items-center">
				<Input
					id="compatible-stores-postal-code"
					inputMode="numeric"
					value={postalCode}
					onChange={(event) => {
						onPostalCodeChange(event.target.value);
					}}
					placeholder="Ej. 03100"
					aria-invalid={Boolean(error)}
					className="sm:max-w-xs"
				/>
				<div className="flex gap-2">
					<Button type="submit" disabled={disabled} className="!py-2.5">
						Buscar
					</Button>
					<Button
						type="button"
						outline
						disabled={disabled || !appliedPostalCode}
						onClick={onClear}
						className="!py-2.5"
					>
						Limpiar
					</Button>
				</div>
			</div>

			{error && (
				<Text className="mt-2 text-sm text-red-600">{error}</Text>
			)}
			{showResolved && (
				<Text className="mt-2 text-sm text-emerald-800 dark:text-emerald-300">
					Encontramos sucursales compatibles cerca de ti.
				</Text>
			)}
			{showUnresolved && (
				<Text className="mt-2 text-sm text-amber-800 dark:text-amber-300">
					Aún no tenemos ubicación para este código postal. Mostramos
					sucursales compatibles sin ordenar por distancia.
				</Text>
			)}
		</form>
	);
}

function MunicipalityGroups({
	branches,
	selectedBranchId,
	savingBranchId,
	onSelect,
	showAllGroups,
	onShowAllGroups,
}) {
	const groups = groupBranchesByMunicipality(compatibleBranches(branches));
	const selectedGroup = groups.find((group) =>
		group.branches.some((branch) => branch.id === selectedBranchId),
	);
	const defaultOpen =
		selectedGroup?.municipality ?? groups[0]?.municipality ?? null;
	const [openGroups, setOpenGroups] = useState(() =>
		defaultOpen ? { [defaultOpen]: true } : {},
	);

	useEffect(() => {
		if (defaultOpen) {
			setOpenGroups((current) => ({ ...current, [defaultOpen]: true }));
		}
	}, [defaultOpen]);

	if (groups.length === 0) {
		return (
			<NoticeState>
				No encontramos sucursales compatibles para mostrar.
			</NoticeState>
		);
	}

	const visibleGroups = showAllGroups
		? groups
		: groups.slice(0, MAX_INITIAL_MUNICIPALITY_GROUPS);
	const hiddenCount = groups.length - visibleGroups.length;

	return (
		<div className="space-y-3">
			<Subheading className="text-sm">Más sucursales por zona</Subheading>
			<div className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
				{visibleGroups.map((group) => {
					const open = Boolean(openGroups[group.municipality]);

					return (
						<div key={group.municipality}>
							<button
								type="button"
								className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light dark:hover:bg-slate-800"
								onClick={() =>
									setOpenGroups((current) => ({
										...current,
										[group.municipality]: !open,
									}))
								}
								aria-expanded={open}
							>
								<span className="truncate text-sm font-semibold text-zinc-950 dark:text-white">
									{group.municipality}
								</span>
								<span className="flex shrink-0 items-center gap-2 text-sm text-zinc-600 dark:text-slate-400">
									{group.branches.length}
									{open ? (
										<ChevronDownIcon className="size-4" />
									) : (
										<ChevronRightIcon className="size-4" />
									)}
								</span>
							</button>

							{open && (
								<div className="grid grid-cols-1 gap-3 px-3 pb-4 md:grid-cols-2">
									{group.branches.map((branch) => (
										<BranchCard
											key={`${branch.brand}-${branch.id}`}
											branch={branch}
											selected={
												selectedBranchId === branch.id
											}
											saving={savingBranchId === branch.id}
											onSelect={() => onSelect(branch)}
											compact
										/>
									))}
								</div>
							)}
						</div>
					);
				})}
			</div>

			{hiddenCount > 0 && (
				<Button
					type="button"
					outline
					onClick={onShowAllGroups}
					className="w-full sm:w-auto"
				>
					Ver más zonas ({hiddenCount})
				</Button>
			)}
		</div>
	);
}

function BranchCard({
	branch,
	selected,
	saving,
	onSelect,
	featured = false,
	compact = false,
}) {
	const distance = formatDistance(branch.distanceKm);
	const hours = formatHours(branch.hours);
	const showDistance = distance !== null;

	return (
		<Card
			as="article"
			className={clsx(
				"transition",
				compact ? "p-3" : "p-4",
				selected &&
					"border-famedic-light bg-famedic-light/5 ring-2 ring-famedic-light ring-offset-2 dark:ring-famedic-lime",
				featured && !selected && "border-famedic-light/60",
			)}
			aria-selected={selected}
		>
			<div className="flex items-start justify-between gap-3">
				<div className="min-w-0">
					<Subheading className={compact ? "text-sm" : "text-base"}>
						{branch.name}
					</Subheading>
					<Text className="mt-0.5 text-sm">
						{storeMunicipality(branch)}
					</Text>
				</div>
				<Badge
					color={
						selected ? "green" : featured ? "famedic-lime" : "sky"
					}
				>
					{selected
						? "Preferida"
						: featured
							? "Recomendada"
							: "Compatible"}
				</Badge>
			</div>

			{branch.address && !compact && (
				<Text className="mt-2 text-sm">{branch.address}</Text>
			)}

			<div className="mt-2 flex flex-wrap gap-2">
				{showDistance && (
					<Badge color="zinc">
						<MapPinIcon className="size-4" />
						{distance}
					</Badge>
				)}
				{hours && (
					<Badge color="zinc">
						<ClockIcon className="size-4" />
						<span>{hours}</span>
						<span className="sr-only">Horario referencial</span>
					</Badge>
				)}
			</div>

			{!compact && (
				<Text className="mt-2 text-xs text-zinc-600 dark:text-slate-400">
					Compatible con tus estudios · Horario referencial
				</Text>
			)}

			<Button
				type="button"
				className="mt-3 w-full !py-2.5"
				color={selected ? "famedic-lime" : "famedic"}
				onClick={onSelect}
				aria-pressed={selected}
				disabled={saving}
			>
				{saving ? "Guardando..." : selectionLabel(selected)}
			</Button>
		</Card>
	);
}

function UnresolvedState({ data }) {
	const partialBranches = compatibleBranches(data.branches);

	return (
		<div className="space-y-4">
			<NoticeState
				title="Necesitamos revisar algunos requisitos"
				reasons={data.unresolved_reasons}
			>
				Algunos estudios de tu carrito tienen requisitos que todavía no
				podemos determinar con suficiente precisión para recomendar una
				sucursal.
			</NoticeState>

			{partialBranches.length > 0 && (
				<div className="grid grid-cols-1 gap-3 md:grid-cols-2">
					{partialBranches.map((branch) => (
						<Card
							key={`${branch.brand}-${branch.id}`}
							className="p-4 opacity-80"
						>
							<Subheading className="text-sm">
								{branch.name}
							</Subheading>
							<Text className="mt-2 text-sm">
								Coincidencia parcial — no se presenta como
								recomendación hasta resolver los requisitos
								pendientes.
							</Text>
						</Card>
					))}
				</div>
			)}
		</div>
	);
}

function CompatibleStoresSkeleton() {
	return (
		<div className="space-y-3">
			<div className="h-24 animate-pulse rounded-lg bg-zinc-200 dark:bg-slate-800" />
			<div className="h-32 animate-pulse rounded-lg bg-zinc-200 dark:bg-slate-800" />
		</div>
	);
}

function ErrorState({ onRetry }) {
	return (
		<Card className="p-5">
			<div className="flex gap-3">
				<ExclamationTriangleIcon className="mt-1 size-5 shrink-0 text-amber-500" />
				<div className="w-full">
					<Subheading>No pudimos consultar las sucursales</Subheading>
					<Text className="mt-2">
						Intenta nuevamente en unos momentos.
					</Text>
					<Button
						type="button"
						outline
						className="mt-4"
						onClick={onRetry}
					>
						<ArrowPathIcon />
						Reintentar
					</Button>
				</div>
			</div>
		</Card>
	);
}

function NoticeState({ title, children, reasons = [] }) {
	const publicReasons = reasons.map(safeReason).filter(Boolean);

	return (
		<Card className="p-4">
			{title && <Subheading className="text-sm">{title}</Subheading>}
			<Text className={title ? "mt-2 text-sm" : "text-sm"}>
				{children}
			</Text>
			{publicReasons.length > 0 && (
				<ul className="mt-2 space-y-1 text-sm text-zinc-600 dark:text-slate-300">
					{publicReasons.map((reason) => (
						<li key={reason}>{reason}</li>
					))}
				</ul>
			)}
		</Card>
	);
}
