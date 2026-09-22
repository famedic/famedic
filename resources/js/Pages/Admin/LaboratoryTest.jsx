import { useMemo, useState } from "react";
import {
	PencilIcon,
	CheckIcon,
	CheckCircleIcon,
	XCircleIcon,
	ExclamationTriangleIcon,
	EllipsisHorizontalIcon,
	ShoppingCartIcon,
	ChartBarIcon,
	BanknotesIcon,
	CalendarDaysIcon,
	LinkIcon,
	Squares2X2Icon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";
import {
	Dropdown,
	DropdownButton,
	DropdownMenu,
	DropdownItem,
} from "@/Components/Catalyst/dropdown";
import {
	Tab,
	TabGroup,
	TabList,
	TabPanel,
	TabPanels,
} from "@/Components/Catalyst/tabs";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import { laboratoryBrandImageSrc } from "@/lib/laboratoryBrand";

const TABS = [
	{ id: "general", label: "General" },
	{ id: "pricing", label: "Precios" },
	{ id: "content", label: "Contenido" },
	{ id: "activity", label: "Actividad" },
];

function activeTabFromLocation() {
	if (typeof window === "undefined") return "general";
	const value = new URLSearchParams(window.location.search).get("tab");
	return TABS.some((tab) => tab.id === value) ? value : "general";
}

export default function LaboratoryTest({
	laboratoryTest,
	stats,
	brands,
}) {
	const [activeTab, setActiveTab] = useState(activeTabFromLocation);

	const tabIndex = useMemo(
		() => Math.max(0, TABS.findIndex((tab) => tab.id === activeTab)),
		[activeTab],
	);

	const changeTab = (tabId) => {
		setActiveTab(tabId);
		const url = new URL(window.location.href);
		if (tabId === "general") {
			url.searchParams.delete("tab");
		} else {
			url.searchParams.set("tab", tabId);
		}
		window.history.replaceState({}, "", url);
	};

	const discountPercent =
		laboratoryTest.public_price_cents > 0
			? Math.round(
					((laboratoryTest.public_price_cents -
						laboratoryTest.famedic_price_cents) /
						laboratoryTest.public_price_cents) *
						100,
				)
			: 0;

	const hasContent =
		(laboratoryTest.feature_list?.length ?? 0) > 0 ||
		Boolean(laboratoryTest.description) ||
		Boolean(laboratoryTest.indications);

	return (
		<AdminLayout title="Prueba de Laboratorio">
			<div className="space-y-8">
				<Header laboratoryTest={laboratoryTest} />

				<StatsOverview stats={stats} />

				<TabGroup selectedIndex={tabIndex} onChange={(index) => changeTab(TABS[index].id)}>
					<TabList className="gap-1 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/60">
						{TABS.map((tab) => (
							<Tab key={tab.id} className="shrink-0">
								{(selected) => (
									<span
										className={
											selected
												? "inline-flex rounded-lg bg-white px-3 py-2 text-sm font-semibold text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50"
												: "inline-flex rounded-lg px-3 py-2 text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
										}
									>
										{tab.label}
									</span>
								)}
							</Tab>
						))}
					</TabList>

					<TabPanels className="mt-6">
						<TabPanel>
							<GeneralTab
								laboratoryTest={laboratoryTest}
								brands={brands}
							/>
						</TabPanel>

						<TabPanel>
							<PricingTab
								laboratoryTest={laboratoryTest}
								discountPercent={discountPercent}
							/>
						</TabPanel>

						<TabPanel>
							{hasContent ? (
								<ContentTab laboratoryTest={laboratoryTest} />
							) : (
								<EmptyTabMessage>
									Esta prueba no tiene características ni
									instrucciones registradas.
								</EmptyTabMessage>
							)}
						</TabPanel>

						<TabPanel>
							<ActivityTab stats={stats} />
						</TabPanel>
					</TabPanels>
				</TabGroup>
			</div>
		</AdminLayout>
	);
}

function Header({ laboratoryTest }) {
	return (
		<div className="flex flex-wrap items-start justify-between gap-4">
			<div className="space-y-3">
				<div className="flex flex-wrap items-center gap-3">
					<Heading>{laboratoryTest.name}</Heading>
					{laboratoryTest.needs_gda_review && (
						<Badge color="amber">
							<ExclamationTriangleIcon className="size-4" />
							Revisar con GDA
						</Badge>
					)}
				</div>
				{laboratoryTest.other_name && (
					<Text>{laboratoryTest.other_name}</Text>
				)}
			</div>

			<Dropdown>
				<DropdownButton outline>
					Acciones
					<EllipsisHorizontalIcon />
				</DropdownButton>
				<DropdownMenu>
					<DropdownItem
						href={route(
							"admin.laboratory-tests.edit",
							laboratoryTest.id,
						)}
					>
						<PencilIcon />
						Editar
					</DropdownItem>
				</DropdownMenu>
			</Dropdown>
		</div>
	);
}

function StatsOverview({ stats }) {
	return (
		<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
			<BillingMetricCard
				label="En carritos activos"
				value={stats.monitoring_active_carts + stats.legacy_customer_carts}
				hint={`${stats.monitoring_active_carts} monitoreo · ${stats.legacy_customer_carts} legacy`}
				icon={ShoppingCartIcon}
				tone={stats.monitoring_active_carts + stats.legacy_customer_carts > 0 ? "amber" : "default"}
			/>
			<BillingMetricCard
				label="Ventas totales"
				value={stats.total_sales}
				hint={`${stats.sales_last_30_days} en los últimos 30 días`}
				icon={ChartBarIcon}
				tone="lime"
			/>
			<BillingMetricCard
				label="Ingresos acumulados"
				value={stats.formatted_total_revenue}
				hint={`${stats.formatted_revenue_last_30_days} en los últimos 30 días`}
				icon={BanknotesIcon}
				tone="default"
			/>
			<BillingMetricCard
				label="En campañas"
				value={stats.marketing_collections + stats.marketing_links}
				hint={`${stats.marketing_collections} colecciones · ${stats.marketing_links} enlaces`}
				icon={LinkIcon}
			/>
		</div>
	);
}

function GeneralTab({ laboratoryTest, brands }) {
	return (
		<div className="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
			<Subheading>Detalles de la prueba</Subheading>

			<DescriptionList className="mt-4">
				<DescriptionTerm>Categoría</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryTest.laboratory_test_category?.name ??
						"Sin categoría"}
				</DescriptionDetails>

				<DescriptionTerm>Marca</DescriptionTerm>
				<DescriptionDetails>
					<LaboratoryBrandCard
						src={laboratoryBrandImageSrc(
							laboratoryTest.brand,
							brands,
						)}
						className="w-36 p-4"
					/>
				</DescriptionDetails>

				<DescriptionTerm>ID GDA</DescriptionTerm>
				<DescriptionDetails>{laboratoryTest.gda_id}</DescriptionDetails>

				<DescriptionTerm>Requiere cita</DescriptionTerm>
				<DescriptionDetails>
					<StatusBadge active={laboratoryTest.requires_appointment} />
				</DescriptionDetails>

				<DescriptionTerm>Revisar con GDA</DescriptionTerm>
				<DescriptionDetails>
					<Badge
						color={
							laboratoryTest.needs_gda_review ? "amber" : "slate"
						}
					>
						{laboratoryTest.needs_gda_review ? (
							<ExclamationTriangleIcon className="size-4" />
						) : (
							<CheckCircleIcon className="size-4" />
						)}
						{laboratoryTest.needs_gda_review ? "Sí" : "No"}
					</Badge>
				</DescriptionDetails>

				{laboratoryTest.gda_review_note && (
					<>
						<DescriptionTerm>Nota revisión GDA</DescriptionTerm>
						<DescriptionDetails>
							<Text>{laboratoryTest.gda_review_note}</Text>
						</DescriptionDetails>
					</>
				)}

				{laboratoryTest.elements && (
					<>
						<DescriptionTerm>Elementos</DescriptionTerm>
						<DescriptionDetails>
							<span className="whitespace-pre-wrap">
								{laboratoryTest.elements}
							</span>
						</DescriptionDetails>
					</>
				)}

				{laboratoryTest.common_use && (
					<>
						<DescriptionTerm>Uso común</DescriptionTerm>
						<DescriptionDetails>
							<span className="whitespace-pre-wrap">
								{laboratoryTest.common_use}
							</span>
						</DescriptionDetails>
					</>
				)}
			</DescriptionList>
		</div>
	);
}

function PricingTab({ laboratoryTest, discountPercent }) {
	return (
		<div className="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
			<Subheading>Información de precios</Subheading>

			<DescriptionList className="mt-4">
				<DescriptionTerm>Precio público</DescriptionTerm>
				<DescriptionDetails>
					<Text className="line-through">
						{laboratoryTest.formatted_public_price}
					</Text>
				</DescriptionDetails>

				<DescriptionTerm>Precio Famedic</DescriptionTerm>
				<DescriptionDetails>
					<Text>
						<Strong>
							{laboratoryTest.formatted_famedic_price}
						</Strong>
					</Text>
				</DescriptionDetails>

				<DescriptionTerm>Descuento</DescriptionTerm>
				<DescriptionDetails>
					<Badge color="green">{discountPercent}% de descuento</Badge>
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function ContentTab({ laboratoryTest }) {
	return (
		<div className="space-y-8">
			{laboratoryTest.feature_list?.length > 0 && (
				<div className="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
					<Subheading>Características incluidas</Subheading>
					<div className="mt-4 grid gap-2 sm:grid-cols-2">
						{laboratoryTest.feature_list.map((feature, index) => (
							<div
								key={index}
								className="flex items-center gap-2"
							>
								<CheckIcon className="size-4 fill-green-600" />
								<Text>{feature}</Text>
							</div>
						))}
					</div>
				</div>
			)}

			{(laboratoryTest.description || laboratoryTest.indications) && (
				<div className="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
					<Subheading>Instrucciones</Subheading>
					<DescriptionList className="mt-4">
						{laboratoryTest.description && (
							<>
								<DescriptionTerm>Descripción</DescriptionTerm>
								<DescriptionDetails>
									<span className="block max-w-3xl whitespace-pre-wrap">
										{laboratoryTest.description}
									</span>
								</DescriptionDetails>
							</>
						)}

						{laboratoryTest.indications && (
							<>
								<DescriptionTerm>Indicaciones</DescriptionTerm>
								<DescriptionDetails>
									<span className="block max-w-3xl whitespace-pre-wrap">
										{laboratoryTest.indications}
									</span>
								</DescriptionDetails>
							</>
						)}
					</DescriptionList>
				</div>
			)}
		</div>
	);
}

function ActivityTab({ stats }) {
	return (
		<div className="space-y-8">
			<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
				<BillingMetricCard
					label="Carritos monitoreo"
					value={stats.monitoring_active_carts}
					hint="Carritos lab activos con este estudio"
					icon={ShoppingCartIcon}
				/>
				<BillingMetricCard
					label="Carritos legacy"
					value={stats.legacy_customer_carts}
					hint="Clientes con el estudio en carrito legacy"
					icon={Squares2X2Icon}
				/>
				<BillingMetricCard
					label="Ventas (30 días)"
					value={stats.sales_last_30_days}
					hint={`${stats.total_sales} ventas históricas`}
					icon={CalendarDaysIcon}
				/>
			</div>

			<RecentPurchasesTable purchases={stats.recent_purchases} />
			<RecentCartsTable carts={stats.recent_monitoring_carts} />
		</div>
	);
}

function RecentPurchasesTable({ purchases }) {
	return (
		<div className="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
			<Subheading>Ventas recientes</Subheading>

			{purchases.length === 0 ? (
				<Text className="mt-4 text-zinc-500">
					Aún no hay ventas registradas para este estudio.
				</Text>
			) : (
				<Table dense className="mt-4 [--gutter:theme(spacing.2)]">
					<TableHead>
						<TableRow>
							<TableHeader>Pedido</TableHeader>
							<TableHeader>Cliente</TableHeader>
							<TableHeader>Fecha</TableHeader>
							<TableHeader className="text-right">
								Precio
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{purchases.map((purchase) => (
							<TableRow
								key={purchase.id}
								href={route(
									"admin.laboratory-purchases.show",
									purchase.laboratory_purchase_id,
								)}
							>
								<TableCell>
									#{purchase.laboratory_purchase_id}
								</TableCell>
								<TableCell>
									{purchase.customer_name ?? "—"}
								</TableCell>
								<TableCell>
									{purchase.formatted_created_at ?? "—"}
								</TableCell>
								<TableCell className="text-right">
									{purchase.formatted_price ?? "—"}
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</div>
	);
}

function RecentCartsTable({ carts }) {
	return (
		<div className="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
			<Subheading>Carritos activos recientes</Subheading>

			{carts.length === 0 ? (
				<Text className="mt-4 text-zinc-500">
					Ningún carrito activo incluye este estudio en este momento.
				</Text>
			) : (
				<Table dense className="mt-4 [--gutter:theme(spacing.2)]">
					<TableHead>
						<TableRow>
							<TableHeader>Carrito</TableHeader>
							<TableHeader>Usuario</TableHeader>
							<TableHeader>Ítems</TableHeader>
							<TableHeader>Actualizado</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{carts.map((cart) => (
							<TableRow
								key={cart.id}
								href={route("admin.carts.show", cart.id)}
							>
								<TableCell>#{cart.id}</TableCell>
								<TableCell>
									{cart.customer_email ?? "—"}
								</TableCell>
								<TableCell>{cart.items_count}</TableCell>
								<TableCell>
									{cart.updated_at
										? new Date(
												cart.updated_at,
											).toLocaleString("es-MX")
										: "—"}
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</div>
	);
}

function StatusBadge({ active }) {
	return (
		<Badge color={active ? "famedic-lime" : "slate"}>
			{active ? (
				<CheckCircleIcon className="size-4" />
			) : (
				<XCircleIcon className="size-4" />
			)}
			{active ? "Sí" : "No"}
		</Badge>
	);
}

function EmptyTabMessage({ children }) {
	return (
		<div className="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
			<Text className="text-zinc-500">{children}</Text>
		</div>
	);
}
