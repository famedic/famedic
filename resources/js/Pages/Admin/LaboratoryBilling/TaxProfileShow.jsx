import { useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	DescriptionDetails,
	DescriptionList,
	DescriptionTerm,
} from "@/Components/Catalyst/description-list";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import BillingNav from "@/Components/Admin/LaboratoryBilling/BillingNav";
import TaxProfileStatusBadge from "@/Components/Admin/LaboratoryBilling/TaxProfileStatusBadge";
import BillingTrendChart from "@/Components/Admin/LaboratoryBilling/BillingTrendChart";
import BillingStatusBadge from "@/Components/Admin/LaboratoryBilling/BillingStatusBadge";
import BillingPanel from "@/Components/Admin/LaboratoryBilling/BillingPanel";
import BillingPurchaseLink from "@/Components/Admin/LaboratoryBilling/BillingPurchaseLink";
import {
	billingMutedTextClass,
	billingSecondaryTextClass,
} from "@/Components/Admin/LaboratoryBilling/billingUi";
import clsx from "clsx";

const TABS = [
	{ key: "info", label: "Información" },
	{ key: "usage", label: "Uso y actividad" },
	{ key: "documents", label: "Documentos" },
	{ key: "history", label: "Historial" },
];

export default function TaxProfileShow({ taxProfile, filters = {} }) {
	const [tab, setTab] = useState("info");

	return (
		<AdminLayout title={`Perfil fiscal · ${taxProfile?.rfc || ""}`}>
			<div className="space-y-8">
				<div className="flex flex-wrap items-start justify-between gap-3">
					<div>
						<Heading>
							{taxProfile?.razon_social || taxProfile?.name || "Perfil fiscal"}
						</Heading>
						<Text className={`mt-1 ${billingMutedTextClass}`}>
							{taxProfile?.rfc || "Sin RFC"} · Detalle de perfil fiscal
						</Text>
						<div className="mt-2">
							<TaxProfileStatusBadge
								isActive={taxProfile?.is_active}
								isDefault={taxProfile?.is_default}
								usageStatus={taxProfile?.is_used ? "used" : "unused"}
							/>
						</div>
					</div>
					<Button
						href={route("admin.laboratory-billing.tax-profiles.index", filters)}
						outline
					>
						Volver
					</Button>
				</div>

				<BillingNav active="tax-profiles" query={filters} />

				<nav className="flex flex-wrap gap-2" aria-label="Detalle del perfil">
					{TABS.map((item) => (
						<button
							key={item.key}
							type="button"
							onClick={() => setTab(item.key)}
							className={clsx(
								"rounded-lg px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime",
								tab === item.key
									? "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker"
									: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800/80",
							)}
							aria-current={tab === item.key ? "page" : undefined}
						>
							{item.label}
						</button>
					))}
				</nav>

				{tab === "info" ? (
					<BillingPanel>
						<DescriptionList>
							<DescriptionTerm>RFC</DescriptionTerm>
							<DescriptionDetails>{taxProfile?.rfc || "—"}</DescriptionDetails>
							<DescriptionTerm>Razón social</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.razon_social || taxProfile?.name || "—"}
							</DescriptionDetails>
							<DescriptionTerm>Tipo de persona</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.tipo_persona_label || "—"}
							</DescriptionDetails>
							<DescriptionTerm>Régimen fiscal</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.formatted_tax_regime || "—"}
							</DescriptionDetails>
							<DescriptionTerm>Código postal</DescriptionTerm>
							<DescriptionDetails>{taxProfile?.zipcode || "—"}</DescriptionDetails>
							<DescriptionTerm>Uso CFDI</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.formatted_cfdi_use || "—"}
							</DescriptionDetails>
							<DescriptionTerm>Domicilio fiscal</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.domicilio_fiscal || "—"}
							</DescriptionDetails>
							<DescriptionTerm>Paciente propietario</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.customer ? (
									<>
										{taxProfile.customer.name || "—"}
										{taxProfile.customer.email
											? ` · ${taxProfile.customer.email}`
											: ""}
									</>
								) : (
									"—"
								)}
							</DescriptionDetails>
							<DescriptionTerm>Creado</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.formatted_created_at || "—"}
							</DescriptionDetails>
							<DescriptionTerm>Actualizado</DescriptionTerm>
							<DescriptionDetails>
								{taxProfile?.formatted_updated_at || "—"}
							</DescriptionDetails>
						</DescriptionList>
					</BillingPanel>
				) : null}

				{tab === "usage" ? (
					<div className="space-y-4">
						<section className="grid gap-3 sm:grid-cols-3">
							<BillingPanel>
								<p className="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
									Solicitudes
								</p>
								<p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
									{taxProfile?.invoice_requests_count ?? 0}
								</p>
							</BillingPanel>
							<BillingPanel>
								<p className="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
									En uso
								</p>
								<p className="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
									{taxProfile?.is_used ? "Sí" : "No"}
								</p>
							</BillingPanel>
							<BillingPanel>
								<p className="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
									Cliente
								</p>
								<p className="mt-1 text-sm font-medium text-zinc-900 dark:text-zinc-50">
									{taxProfile?.customer?.name || "—"}
								</p>
							</BillingPanel>
						</section>

						{(taxProfile?.monthly_usage || []).length > 0 ? (
							<BillingTrendChart
								title="Uso por mes"
								description="Solicitudes vinculadas a este perfil."
								points={(taxProfile.monthly_usage || []).map((point) => ({
									label: point.label,
									requests: point.value,
									invoices_completed: 0,
								}))}
								series={[
									{ key: "requests", name: "Solicitudes", color: "#0ea5e9" },
								]}
							/>
						) : (
							<Text className={billingMutedTextClass}>
								No hay suficientes datos para graficar el uso mensual.
							</Text>
						)}

						<BillingPanel>
							<Subheading>Pedidos recientes</Subheading>
							{(taxProfile?.recent_requests || []).length === 0 ? (
								<Text className={`mt-3 ${billingMutedTextClass}`}>
									Sin solicitudes asociadas.
								</Text>
							) : (
								<Table dense className="mt-3">
									<TableHead>
										<TableRow>
											<TableHeader>Pedido</TableHeader>
											<TableHeader>Solicitud</TableHeader>
											<TableHeader>Finalización</TableHeader>
											<TableHeader>Estado</TableHeader>
											<TableHeader>Acción</TableHeader>
										</TableRow>
									</TableHead>
									<TableBody>
										{taxProfile.recent_requests.map((row) => (
											<TableRow key={row.id}>
												<TableCell>
													<BillingPurchaseLink
														href={row.detail_url || row.purchase?.show_url}
														label={
															row.purchase?.folio || row.purchase?.id || null
														}
													/>
												</TableCell>
												<TableCell>
													{row.formatted_requested_at || "—"}
												</TableCell>
												<TableCell>
													{row.invoice?.formatted_completed_at || "—"}
												</TableCell>
												<TableCell>
													<BillingStatusBadge
														status={row.billing?.status}
														label={row.billing?.status_label}
														color={row.billing?.status_color}
													/>
												</TableCell>
												<TableCell>
													{row.detail_url ? (
														<Button href={row.detail_url} plain>
															Ver
														</Button>
													) : (
														"—"
													)}
												</TableCell>
											</TableRow>
										))}
									</TableBody>
								</Table>
							)}
						</BillingPanel>
					</div>
				) : null}

				{tab === "documents" ? (
					<BillingPanel>
						<Subheading>Constancia fiscal</Subheading>
						{taxProfile?.has_fiscal_certificate &&
						taxProfile?.fiscal_certificate_url ? (
							<div className="mt-3">
								<Button href={taxProfile.fiscal_certificate_url} outline>
									Ver constancia
								</Button>
								<Text className={`mt-2 ${billingSecondaryTextClass}`}>
									La descarga usa la ruta segura existente. No se expone la ruta
									interna de almacenamiento.
								</Text>
							</div>
						) : (
							<Text className={`mt-3 ${billingMutedTextClass}`}>
								Este perfil no tiene constancia fiscal disponible.
							</Text>
						)}
					</BillingPanel>
				) : null}

				{tab === "history" ? (
					<BillingPanel>
						<Subheading>Historial disponible</Subheading>
						<ul className="mt-3 space-y-2 text-sm text-zinc-600 dark:text-zinc-300">
							<li>Creado: {taxProfile?.formatted_created_at || "—"}</li>
							<li>Actualizado: {taxProfile?.formatted_updated_at || "—"}</li>
							<li>
								Solicitudes vinculadas: {taxProfile?.invoice_requests_count ?? 0}
							</li>
						</ul>
						<Text className={`mt-3 ${billingSecondaryTextClass}`}>
							No existe un sistema de auditoría detallado de cambios de perfil.
							Solo se muestra información confiable de timestamps y vínculos.
						</Text>
					</BillingPanel>
				) : null}
			</div>
		</AdminLayout>
	);
}
