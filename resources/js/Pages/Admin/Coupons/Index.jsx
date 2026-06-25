import { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { Badge } from "@/Components/Catalyst/badge";
import { PlusIcon } from "@heroicons/react/16/solid";
import {
	ClipboardDocumentCheckIcon,
	FunnelIcon,
	InformationCircleIcon,
} from "@heroicons/react/24/outline";
import Card from "@/Components/Card";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { couponValiditySummary } from "@/lib/couponEligibilityUi";
import {
	computeIndexMetrics,
	couponActiveBadge,
	couponUsageSummary,
} from "@/lib/couponAdminUi";
import {
	creatorDisplayName,
	formatMxnFromNumber,
	formatShortDateTime,
} from "@/lib/couponFormat";
import CouponMetricCard from "@/Components/Admin/Coupon/CouponMetricCard";
import AuthorizationInboxLink from "@/Components/Admin/Coupon/AuthorizationInboxLink";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import CouponStatusBadge from "@/Components/Admin/Coupon/CouponStatusBadge";
import CouponValidityBadge from "@/Components/Admin/Coupon/CouponValidityBadge";
import CouponActionMenu from "@/Components/Admin/Coupon/CouponActionMenu";
import CouponEmptyState from "@/Components/Admin/Coupon/CouponEmptyState";
import { CreditCardIcon } from "@heroicons/react/24/outline";

function approvalStatusPlainLabel(value) {
	switch (value) {
		case "pending_authorization":
			return "Pendiente de autorización por correo";
		case "active":
			return "Activo en el sistema";
		case "rejected":
			return "Rechazado";
		default:
			return value ? String(value) : "—";
	}
}

export default function CouponsIndex({
	coupons,
	filters,
	authorizerContext = {},
	approvalsOverview = { pending_assignment_requests: 0 },
}) {
	const metrics = computeIndexMetrics(coupons?.data ?? []);
	const pendingCouponIds = new Set(authorizerContext.pending_my_action_coupon_ids ?? []);
	const pendingMultisigTotal = approvalsOverview.pending_assignment_requests ?? 0;
	const pendingSettingsRequests = authorizerContext.pending_settings_requests ?? [];
	const pendingAssignmentCards = authorizerContext.pending_assignment_cards ?? [];
	const [showHelp, setShowHelp] = useState(false);
	const [showFilters, setShowFilters] = useState(false);
	const isAuthorizer = !!authorizerContext.is_authorizer;
	const [actingOnRequestId, setActingOnRequestId] = useState(null);
	const [expandedSettingsRequestIds, setExpandedSettingsRequestIds] = useState(
		() => new Set(),
	);

	const toggleSettingsRequestDetail = (requestId) => {
		setExpandedSettingsRequestIds((prev) => {
			const next = new Set(prev);
			if (next.has(requestId)) {
				next.delete(requestId);
			} else {
				next.add(requestId);
			}
			return next;
		});
	};

	const postApprovalAction = (requestId, action, opts = {}) => {
		if (actingOnRequestId) return;
		if (action === "reject") {
			const msg =
				opts.rejectMessage ??
				"¿Rechazar esta solicitud? Los cambios propuestos no se aplicarán.";
			const ok = window.confirm(msg);
			if (!ok) return;
		}
		const routeName =
			action === "approve"
				? "admin.coupons.approval-requests.approve"
				: "admin.coupons.approval-requests.reject";
		setActingOnRequestId(requestId);
		router.post(
			route(routeName, { approvalRequest: requestId }),
			{},
			{
				preserveScroll: true,
				onFinish: () => setActingOnRequestId(null),
			},
		);
	};

	const { data, setData, get, processing } = useForm({
		search: filters?.search ?? "",
		usage: filters?.usage ?? "all",
		user_email: filters?.user_email ?? "",
		date_from: filters?.date_from ?? "",
		date_to: filters?.date_to ?? "",
	});

	const activeFiltersCount = [
		filters?.search,
		filters?.usage && filters.usage !== "all",
		filters?.user_email,
		filters?.date_from,
		filters?.date_to,
	].filter(Boolean).length;

	const applyFilters = (e) => {
		e.preventDefault();
		get(route("admin.coupons.index"), { preserveState: true, replace: true });
	};

	const [revokeTarget, setRevokeTarget] = useState(null);
	const { delete: destroy, processing: revoking } = useForm({});
	const [deactivateTarget, setDeactivateTarget] = useState(null);
	const [deleteCampaignTarget, setDeleteCampaignTarget] = useState(null);
	const { post: postDeactivate, processing: deactivating } = useForm({});
	const { delete: destroyCampaign, processing: deletingCampaign } = useForm({});

	const confirmRevoke = () => {
		if (!revokeTarget || revoking) return;
		destroy(
			route("admin.coupons.assignments.destroy", {
				coupon: revokeTarget.couponId,
				couponUser: revokeTarget.assignmentId,
			}),
			{ preserveScroll: true },
		);
	};

	const confirmDeactivate = () => {
		if (!deactivateTarget || deactivating) return;
		postDeactivate(route("admin.coupons.deactivate", deactivateTarget.id), {
			preserveScroll: true,
			onFinish: () => setDeactivateTarget(null),
		});
	};

	const confirmDeleteCampaign = () => {
		if (!deleteCampaignTarget || deletingCampaign) return;
		destroyCampaign(route("admin.coupons.destroy", deleteCampaignTarget.id), {
			onFinish: () => setDeleteCampaignTarget(null),
		});
	};

	return (
		<AdminLayout title="Créditos a favor">
			<div className="space-y-8">
			<div className="flex flex-wrap items-end justify-between gap-8">
				<div className="max-w-2xl">
					<Heading>Créditos a favor</Heading>
					<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
						Administra campañas de saldo, beneficiarios, vigencia y aprobaciones desde un
						solo lugar.
					</Text>
				</div>
				<div className="flex flex-wrap items-center justify-end gap-2">
					<AuthorizationInboxLink />
					<Button href={route("admin.coupons.beneficiaries.index")} outline>
						Beneficiarios
					</Button>
					<Button href={route("admin.coupons.logs")} outline>
						Historial
					</Button>
					<Button href={route("admin.coupons.settings")} outline>
						Configuración
					</Button>
					<Button href={route("admin.coupons.assign", { focus: "new" })}>
						<PlusIcon />
						Crear crédito
					</Button>
				</div>
			</div>

			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
				<CouponMetricCard label="En esta página" value={metrics.total} />
				<CouponMetricCard label="Activos" value={metrics.active} tone="lime" />
				<CouponMetricCard
					label="Pend. autorización"
					value={metrics.pendingAuth}
					tone="amber"
				/>
				<CouponMetricCard label="Vencidos" value={metrics.expired} tone="red" />
				<CouponMetricCard
					label="Beneficiarios"
					value={metrics.totalAssigned}
					tone="sky"
					hint="Suma de hijos en la página actual"
				/>
			</div>
			<div className="space-y-4">
				{isAuthorizer ? (
					<Text className="text-zinc-600 dark:text-zinc-400">
						Aquí ves los cupones y las solicitudes que requieren tu visto bueno. Revisa el monto, la
						descripción y la lista de personas beneficiarias antes de aprobar o rechazar.
					</Text>
				) : (
					<>
						<Button
							outline
							onClick={() => setShowHelp(!showHelp)}
							title="Más información sobre cupones"
						>
							<InformationCircleIcon className="size-5" />
							{showHelp ? "Ocultar ayuda" : "Ver ayuda"}
						</Button>
						{showHelp && (
							<Card className="p-4 bg-blue-50 dark:bg-blue-950/20 border-blue-100 dark:border-blue-900">
								<Text className="!text-sm">
									Desde &quot;Crear y asignar créditos&quot; defines el crédito maestro, asignas por correo o
									por archivo, y ves las reglas vigentes. Cada asignación crea un crédito hijo con saldo
									propio. Si activas autorización por correo en Reglas, el crédito queda pendiente hasta que
									el autorizador ingrese el código recibido.
								</Text>
							</Card>
						)}
					</>
				)}
			</div>

			{pendingMultisigTotal > 0 && !isAuthorizer && (
				<div
					className="mt-6 flex flex-col gap-3 rounded-xl border border-sky-200/90 bg-sky-50 px-4 py-4 text-sm text-sky-950 shadow-sm dark:border-sky-500/35 dark:bg-sky-950/35 dark:text-sky-50 sm:flex-row sm:items-center"
					role="status"
				>
					<div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-200">
						<ClipboardDocumentCheckIcon className="size-5" />
					</div>
					<div className="min-w-0 flex-1">
						<p className="font-medium">Créditos pendientes de aprobación</p>
						<p className="mt-1 text-sky-900/90 dark:text-sky-100/85">
							Revisa las fichas de créditos a favor para validar y aprobar.
						</p>
					</div>
					<Badge color="sky" className="w-fit">
						{pendingMultisigTotal} pendiente{pendingMultisigTotal === 1 ? "" : "s"}
					</Badge>
				</div>
			)}

			{false &&
				authorizerContext.is_authorizer &&
				(authorizerContext.pending_assignment_approvals_count > 0 ||
					authorizerContext.pending_settings_approvals_count > 0) && (
					<div
						className="mt-6 flex flex-col gap-3 rounded-xl border border-amber-300/80 bg-amber-50 px-4 py-4 text-sm text-amber-950 shadow-sm dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-50"
						role="status"
					>
						<div className="flex flex-wrap items-center gap-2">
							<Badge color="amber">Tu rol: autorizador</Badge>
							<span className="font-medium">Tienes pendiente revisar lo siguiente</span>
						</div>
						<p className="text-amber-900/95 dark:text-amber-100/90">
							Lee con calma el monto, el motivo del cupón y las personas que recibirían el
							crédito. Si algo no coincide, usa <strong>Rechazar</strong>. Si todo está bien,
							pulsa <strong>Aprobar</strong> para registrar tu firma.
						</p>

						{pendingAssignmentCards.length > 0 && (
							<div className="mt-4 space-y-4">
								<Subheading level={3} className="text-base text-amber-950 dark:text-amber-50">
									Cupones y beneficiarios a revisar
								</Subheading>
								{pendingAssignmentCards.map((card) => {
									const busy = actingOnRequestId === card.id;
									const cp = card.coupon;
									const amountLabel = cp
										? formatMxnFromNumber(cp.amount_cents / 100)
										: "—";
									return (
										<div
											key={card.id}
											className="rounded-xl border border-amber-200/90 bg-white p-4 shadow-sm dark:border-amber-500/25 dark:bg-zinc-950/60"
										>
											<div className="flex flex-wrap items-start justify-between gap-3">
												<div className="min-w-0 flex-1 space-y-2">
													<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
														Solicitud #{card.id}
														{cp?.code ? (
															<span className="font-normal text-zinc-600 dark:text-zinc-400">
																{" "}
																· Cupón: {cp.code}
															</span>
														) : null}
													</p>
													{cp && (
														<>
															<p className="text-lg font-bold text-famedic-dark dark:text-famedic-lime">
																{amountLabel}{" "}
																<span className="text-sm font-normal text-zinc-600 dark:text-zinc-400">
																	por persona
																</span>
															</p>
															{cp.description ? (
																<p className="text-sm leading-snug text-zinc-700 dark:text-zinc-300">
																	{cp.description}
																</p>
															) : (
																<p className="text-sm italic text-zinc-500 dark:text-zinc-400">
																	Sin descripción adicional.
																</p>
															)}
															<p className="text-xs text-zinc-600 dark:text-zinc-400">
																Estado del cupón:{" "}
																<strong>
																	{approvalStatusPlainLabel(cp.approval_status)}
																</strong>
																{card.activate_parent_on_execute ? (
																	<>
																		{" "}
																		· Tras las firmas, el sistema lo activará y aplicará las
																		asignaciones.
																	</>
																) : null}
															</p>
														</>
													)}
													<div className="rounded-lg bg-zinc-100/80 px-3 py-2 text-sm dark:bg-zinc-900/80">
														<p className="font-medium text-zinc-800 dark:text-zinc-200">
															Quién lo solicitó
														</p>
														<p className="text-zinc-700 dark:text-zinc-300">
															{card.requested_by?.name ?? "—"}
															{card.requested_by?.email ? (
																<span className="block break-all text-xs text-zinc-600 dark:text-zinc-400">
																	{card.requested_by.email}
																</span>
															) : null}
														</p>
													</div>
													<p className="text-xs text-zinc-600 dark:text-zinc-400">
														Firmas registradas:{" "}
														<strong>
															{card.current_approvals} de {card.required_approvals}
														</strong>{" "}
														necesarias.
													</p>
												</div>
												<div className="flex shrink-0 flex-col gap-2 sm:items-end">
													<Button
														href={route("admin.coupons.show", card.coupon_id)}
														outline
														className="w-full sm:w-auto"
													>
														Ver cupón completo
													</Button>
													<Button
														type="button"
														outline
														disabled={busy}
														className="w-full sm:w-auto"
														onClick={() =>
															postApprovalAction(card.id, "reject", {
																rejectMessage:
																	"¿Rechazar esta solicitud? No se otorgarán los créditos a las personas listadas ni se activará el cupón de esta solicitud.",
															})
														}
													>
														{busy ? "Procesando…" : "Rechazar"}
													</Button>
													<Button
														type="button"
														disabled={busy}
														className="w-full sm:w-auto"
														onClick={() => postApprovalAction(card.id, "approve")}
													>
														{busy ? "Procesando…" : "Aprobar"}
													</Button>
												</div>
											</div>

											<div className="mt-4 border-t border-zinc-200 pt-3 dark:border-zinc-700">
												{card.pre_approval_only && card.beneficiary_emails_total === 0 ? (
													<p className="text-sm text-zinc-700 dark:text-zinc-300">
														Esta solicitud es solo para <strong>autorizar el cupón</strong> antes
														de que se puedan asignar beneficiarios. No hay lista de personas en
														este paso.
													</p>
												) : card.beneficiaries_preview?.length > 0 ? (
													<>
														<p className="text-sm font-medium text-zinc-900 dark:text-zinc-100">
															Personas que recibirían el crédito (
															{card.beneficiary_emails_total})
														</p>
														<ul className="mt-2 max-h-48 list-inside list-disc space-y-1 overflow-y-auto text-sm text-zinc-800 dark:text-zinc-200">
															{card.beneficiaries_preview.map((b) => (
																<li key={b.email} className="break-all">
																	{b.name ? (
																		<>
																			<strong>{b.name}</strong> ({b.email})
																		</>
																	) : (
																		<span className="font-mono text-xs">{b.email}</span>
																	)}
																</li>
															))}
														</ul>
														{card.beneficiaries_truncated > 0 && (
															<p className="mt-2 text-xs text-zinc-600 dark:text-zinc-400">
																Y {card.beneficiaries_truncated} correo(s) más (lista completa
																en &quot;Ver cupón completo&quot;).
															</p>
														)}
													</>
												) : (
													<p className="text-sm text-zinc-600 dark:text-zinc-400">
														No hay correos de beneficiarios en esta solicitud (revisa la ficha
														del cupón por si el detalle está allí).
													</p>
												)}
												{card.send_notification ? (
													<p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
														Se enviará aviso por correo y en la plataforma a quienes reciban el
														crédito.
													</p>
												) : (
													<p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
														Está marcado sin notificaciones automáticas a los beneficiarios.
													</p>
												)}
											</div>
										</div>
									);
								})}
							</div>
						)}

						{authorizerContext.pending_settings_approvals_count > 0 && (
							<p className="mt-4 text-amber-900/90 dark:text-amber-100/90">
								{pendingAssignmentCards.length > 0 ? "Además, " : ""}
								Hay{" "}
								<strong>{authorizerContext.pending_settings_approvals_count}</strong> solicitud
								(es) sobre <strong>cómo funcionan los cupones en el sistema</strong>. Si no eres
								familiar con tablas de montos, puedes pedir apoyo a administración antes de
								firmar. Usa <strong>Mostrar más</strong> solo si quieres ver el detalle técnico.
							</p>
						)}

						{pendingSettingsRequests.length > 0 && (
							<div className="mt-4 space-y-4">
								{pendingSettingsRequests.map((req) => {
									const busy = actingOnRequestId === req.id;
									const detailOpen = expandedSettingsRequestIds.has(req.id);
									return (
										<div
											key={req.id}
											className="rounded-lg border border-amber-200/90 bg-white/80 p-4 shadow-sm dark:border-amber-500/30 dark:bg-zinc-950/50"
										>
											<div className="flex flex-wrap items-center justify-between gap-3">
												<p className="min-w-0 flex-1 text-sm leading-snug text-amber-950 dark:text-amber-50">
													<span className="font-medium text-amber-900 dark:text-amber-100">
														Quién solicita los ajustes:{" "}
													</span>
													<strong>{req.requested_by?.name ?? "—"}</strong>
													{req.requested_by?.email ? (
														<span className="block truncate text-amber-900/90 dark:text-amber-100/90 sm:ml-1 sm:inline">
															{req.requested_by.email}
														</span>
													) : null}
												</p>
												<div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
													<Button
														type="button"
														plain
														className="text-sm font-medium text-amber-900 underline decoration-amber-600/60 underline-offset-2 hover:text-amber-950 dark:text-amber-100 dark:decoration-amber-300/50 dark:hover:text-white"
														disabled={busy}
														onClick={() => toggleSettingsRequestDetail(req.id)}
													>
														{detailOpen ? "Mostrar menos" : "Mostrar más"}
													</Button>
													<Button
														type="button"
														outline
														disabled={busy}
														onClick={() => postApprovalAction(req.id, "reject")}
													>
														{busy ? "Procesando…" : "Rechazar"}
													</Button>
													<Button
														type="button"
														disabled={busy}
														onClick={() => postApprovalAction(req.id, "approve")}
													>
														{busy ? "Procesando…" : "Aprobar"}
													</Button>
												</div>
											</div>

											{detailOpen && (
												<div className="mt-4 border-t border-amber-200/70 pt-4 dark:border-amber-600/30">
													<p className="text-xs text-amber-900/85 dark:text-amber-100/85">
														<strong>Solicitud #{req.id}</strong>
														{req.created_at
															? ` · ${formatShortDateTime(req.created_at)}`
															: ""}
														{" · "}
														Firmas:{" "}
														<strong>
															{req.current_approvals ?? 0} / {req.required_approvals ?? 0}
														</strong>
													</p>

											{req.amount_rules?.length > 0 && (
												<div className="mt-4">
													<p className="text-xs font-medium uppercase tracking-wide text-amber-950/80 dark:text-amber-100/80">
														Rangos por monto (propuestos)
													</p>
													<div className="mt-2 overflow-x-auto rounded-md border border-amber-200/60 dark:border-amber-500/25">
														<table className="min-w-full text-left text-xs">
															<thead className="bg-amber-100/60 dark:bg-amber-950/40">
																<tr>
																	<th className="px-2 py-1.5 font-medium">Desde (MXN)</th>
																	<th className="px-2 py-1.5 font-medium">Hasta (MXN)</th>
																	<th className="px-2 py-1.5 font-medium"># autorizadores</th>
																</tr>
															</thead>
															<tbody>
																{req.amount_rules.map((r, i) => (
																	<tr
																		key={i}
																		className="border-t border-amber-100/80 dark:border-amber-900/40"
																	>
																		<td className="px-2 py-1.5">
																			{formatMxnFromNumber(r.min_mxn)}
																		</td>
																		<td className="px-2 py-1.5">
																			{r.max_mxn === null || r.max_mxn === undefined
																				? "Sin tope"
																				: formatMxnFromNumber(r.max_mxn)}
																		</td>
																		<td className="px-2 py-1.5">{r.required_approvals}</td>
																	</tr>
																))}
															</tbody>
														</table>
													</div>
												</div>
											)}

											{req.beneficiary_rules?.length > 0 && (
												<div className="mt-4">
													<p className="text-xs font-medium uppercase tracking-wide text-amber-950/80 dark:text-amber-100/80">
														Rangos por beneficiarios (propuestos)
													</p>
													<div className="mt-2 overflow-x-auto rounded-md border border-amber-200/60 dark:border-amber-500/25">
														<table className="min-w-full text-left text-xs">
															<thead className="bg-amber-100/60 dark:bg-amber-950/40">
																<tr>
																	<th className="px-2 py-1.5 font-medium">Desde</th>
																	<th className="px-2 py-1.5 font-medium">Hasta</th>
																	<th className="px-2 py-1.5 font-medium"># autorizadores</th>
																</tr>
															</thead>
															<tbody>
																{req.beneficiary_rules.map((r, i) => (
																	<tr
																		key={i}
																		className="border-t border-amber-100/80 dark:border-amber-900/40"
																	>
																		<td className="px-2 py-1.5">{r.min}</td>
																		<td className="px-2 py-1.5">
																			{r.max === null || r.max === undefined
																				? "Sin tope"
																				: r.max}
																		</td>
																		<td className="px-2 py-1.5">{r.required_approvals}</td>
																	</tr>
																))}
															</tbody>
														</table>
													</div>
												</div>
											)}

												</div>
											)}
										</div>
									);
								})}
							</div>
						)}
					</div>
				)}

			<div className="mt-6 flex justify-end">
				<Button
					type="button"
					outline
					onClick={() => setShowFilters((value) => !value)}
				>
					{activeFiltersCount > 0 ? (
						<Badge color="sky">{activeFiltersCount}</Badge>
					) : (
						<FunnelIcon className="size-5" />
					)}
					Filtros
				</Button>
			</div>

			{showFilters && (
			<form
				onSubmit={applyFilters}
				className="mt-4 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-900/40"
			>
				<Field className="min-w-[10rem]">
					<Label>Buscar</Label>
					<Input
						placeholder="Código, descripción, correo…"
						value={data.search}
						onChange={(e) => setData("search", e.target.value)}
					/>
				</Field>
				<Field className="min-w-[12rem]">
					<Label>Uso</Label>
					<Select
						className="mt-1"
						value={data.usage}
						onChange={(e) => setData("usage", e.target.value)}
					>
						<option value="all">Todos</option>
						<option value="pending">Pendiente autorización</option>
						<option value="unassigned">Sin asignar</option>
						<option value="unused">Con saldo sin usar</option>
						<option value="used">Ya usado</option>
					</Select>
				</Field>
				<Field className="min-w-[11rem]">
					<Label>Correo beneficiario</Label>
					<Input
						type="email"
						placeholder="usuario@…"
						value={data.user_email}
						onChange={(e) => setData("user_email", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Desde</Label>
					<Input
						type="date"
						value={data.date_from}
						onChange={(e) => setData("date_from", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Hasta</Label>
					<Input
						type="date"
						value={data.date_to}
						onChange={(e) => setData("date_to", e.target.value)}
					/>
				</Field>
				<Button type="submit" disabled={processing}>
					Filtrar
				</Button>
			</form>
			)}

			<div className="mt-6">
				{coupons.data.length === 0 ? (
					<CouponEmptyState
						icon={CreditCardIcon}
						title="No hay créditos"
						description="Crea un crédito nuevo o ajusta los filtros para ver resultados."
						action={
							<Button href={route("admin.coupons.assign", { focus: "new" })}>
								<PlusIcon />
								Crear crédito
							</Button>
						}
					/>
				) : (
				<PaginatedTable paginatedData={coupons}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader className="min-w-[14rem]">Cupón</TableHeader>
								<TableHeader className="whitespace-nowrap">Saldo y cupos</TableHeader>
								<TableHeader className="min-w-[10rem]">Estado y actividad</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{coupons.data.map((c) => {
								const usage = couponUsageSummary(c);
								const assignments = c.coupon_users ?? c.couponUsers ?? [];
								const childCount = c.child_coupons_count ?? 0;
								const maxB = c.max_beneficiaries;
								const campaignActions = c.campaign_admin_actions ?? {};
								return (
									<TableRow key={c.id}>
										<TableCell className="max-w-[min(28rem,40vw)] align-top">
											<div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
												<span className="font-mono text-xs text-zinc-500 dark:text-zinc-400">
													#{c.id}
												</span>
												<Button
													href={route("admin.coupons.show", c.id)}
													plain
													className="font-semibold text-zinc-900 hover:text-famedic-darker dark:text-zinc-100 dark:hover:text-famedic-lime"
												>
													{c.code || "Sin código"}
												</Button>
											</div>
											{(c.concept?.title || c.concept_other) && (
												<div className="mt-1.5">
													<Badge color="orange">
														{c.concept?.title || c.concept_other}
													</Badge>
												</div>
											)}
											{c.description ? (
												<p className="mt-1 line-clamp-3 text-sm leading-snug text-zinc-700 dark:text-zinc-300">
													{c.description}
												</p>
											) : (
												<p className="mt-1 text-sm italic text-zinc-500 dark:text-zinc-400">
													Sin descripción
												</p>
											)}
											<p className="mt-2 text-xs text-zinc-600 dark:text-zinc-400">
												<span className="font-medium text-zinc-700 dark:text-zinc-300">
													Alta:
												</span>{" "}
												{formatShortDateTime(c.created_at)}
												<span className="mx-1.5 text-zinc-400">·</span>
												<span className="font-medium text-zinc-700 dark:text-zinc-300">
													Quien creó:
												</span>{" "}
												{creatorDisplayName(c.created_by_user ?? c.createdByUser)}
											</p>
											{childCount > 0 && (
												<p className="mt-1.5 text-xs text-zinc-600 dark:text-zinc-400">
													<strong className="text-zinc-800 dark:text-zinc-200">
														Campaña:
													</strong>{" "}
													{childCount} beneficiario(s) con cupón hijo —{" "}
													<Button
														href={route("admin.coupons.show", c.id)}
														plain
														className="text-xs font-semibold"
													>
														ver listado en ficha
													</Button>
												</p>
											)}
											{childCount === 0 && assignments.length > 0 && (
												<p className="mt-1.5 text-xs text-zinc-600 dark:text-zinc-400">
													<strong className="text-zinc-800 dark:text-zinc-200">
														Asignaciones directas:
													</strong>{" "}
													{assignments.length}{" "}
													<Button
														href={route("admin.coupons.show", c.id)}
														plain
														className="text-xs font-semibold"
													>
														ver en ficha
													</Button>
												</p>
											)}
										</TableCell>
										<TableCell className="align-top whitespace-nowrap">
											<div className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
												{(c.amount_cents / 100).toLocaleString("es-MX", {
													style: "currency",
													currency: "MXN",
												})}
											</div>
											<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
												por persona
											</p>
											<p className="mt-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
												{childCount > 0 ? (
													<>
														{childCount}
														{maxB != null ? (
															<span className="font-normal text-zinc-500">
																{" "}
																de {maxB} cupos
															</span>
														) : (
															<span className="font-normal text-zinc-500">
																{" "}
																beneficiarios (sin tope)
															</span>
														)}
													</>
												) : (
													<>
														{assignments.length}
														{maxB != null ? (
															<span className="font-normal text-zinc-500">
																{" "}
																de {maxB} asignaciones
															</span>
														) : (
															<span className="font-normal text-zinc-500">
																{" "}
																asignación(es)
															</span>
														)}
													</>
												)}
											</p>
											{c.formatted_min_purchase && (
												<p className="mt-2 text-xs text-zinc-600 dark:text-zinc-400">
													Compra mínima: {c.formatted_min_purchase}
												</p>
											)}
										</TableCell>
										<TableCell className="align-top">
											<div className="flex flex-col gap-2">
												<div className="flex flex-wrap gap-1.5">
													<Badge color={usage.color}>{usage.label}</Badge>
													{(() => {
														const validity = couponValiditySummary(c);
														return (
															<Badge color={validity.color}>{validity.label}</Badge>
														);
													})()}
													{c.is_active ? (
														<Badge color="emerald">Activo</Badge>
													) : (
														<Badge color="zinc">Inactivo</Badge>
													)}
													{c.approval_status === "pending_authorization" && (
														<Badge color="purple">Sin autorizar</Badge>
													)}
													{authorizerContext.is_authorizer &&
														pendingCouponIds.has(c.id) && (
															<Badge color="amber">Tu aprobación</Badge>
														)}
												</div>
												{c.assignment_approval_summary && (
													<div className="rounded-md border border-amber-200/80 bg-amber-50/60 px-2 py-1.5 text-xs text-amber-950 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-100">
														<span className="font-medium">Aprobaciones:</span>{" "}
														{c.assignment_approval_summary.current}/
														{c.assignment_approval_summary.required} firmas
														{c.assignment_approval_summary.remaining > 0
															? ` · faltan ${c.assignment_approval_summary.remaining}`
															: ""}
														{c.assignment_approval_summary.pre_approval_only && (
															<span className="block text-[0.7rem] text-amber-800/90 dark:text-amber-200/80">
																Pre-aprobación de crédito
															</span>
														)}
														<Button
															href={route("admin.coupons.show", c.id)}
															plain
															className="mt-1 text-xs font-semibold"
														>
															Abrir ficha
														</Button>
													</div>
												)}
											</div>
										</TableCell>
										<TableCell className="text-right align-top">
											<CouponActionMenu
												items={[
													{
														key: "view",
														label: "Ver ficha",
														href: route("admin.coupons.show", c.id),
													},
													...(isAuthorizer &&
													(c.approval_status === "pending_authorization" ||
														pendingCouponIds.has(c.id))
														? [
																{
																	key: "review-auth",
																	label: "Revisar autorización",
																	href: route("admin.coupons.authorizations.show", c.id),
																},
															]
														: []),
													{
														key: "edit",
														label: "Editar",
														href: route("admin.coupons.edit", c.id),
													},
													{
														key: "assign",
														label: "Asignar",
														href: route("admin.coupons.assign", {
															coupon_id: c.id,
														}),
													},
													{
														key: "logs",
														label: "Historial",
														href: route("admin.coupons.logs", {
															coupon_id: c.id,
														}),
													},
													...(campaignActions.can_deactivate
														? [
																{
																	key: "deactivate",
																	label: "Desactivar campaña",
																	danger: true,
																	onClick: () => setDeactivateTarget(c),
																},
															]
														: []),
													...(campaignActions.can_delete
														? [
																{
																	key: "delete-campaign",
																	label: "Eliminar campaña",
																	danger: true,
																	onClick: () => setDeleteCampaignTarget(c),
																},
															]
														: campaignActions.is_master_campaign &&
															  campaignActions.activity_summary?.has_activity
															? [
																	{
																		key: "delete-campaign-blocked",
																		label: "Eliminar campaña",
																		disabled: true,
																		title: campaignActions.delete_blocked_message,
																	},
																]
															: []),
													...assignments
														.filter((a) => !a.used_at)
														.map((a) => ({
															key: `revoke-${a.id}`,
															label: `Quitar: ${a.user?.email ?? "asignación"}`,
															danger: true,
															onClick: () =>
																setRevokeTarget({
																	couponId: c.id,
																	assignmentId: a.id,
																	email: a.user?.email,
																}),
														})),
												]}
											/>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</PaginatedTable>
				)}
			</div>
			</div>

			<DeleteConfirmationModal
				isOpen={!!revokeTarget}
				close={() => setRevokeTarget(null)}
				title="Quitar asignación"
				description={
					revokeTarget
						? `Se eliminará el vínculo del cupón con ${revokeTarget.email ?? "el usuario"}. El saldo dejará de mostrarse en su cuenta si aún no lo usó.`
						: ""
				}
				processing={revoking}
				destroy={confirmRevoke}
			/>
			<DeleteConfirmationModal
				isOpen={!!deactivateTarget}
				close={() => setDeactivateTarget(null)}
				title="Desactivar campaña"
				description={
					deactivateTarget?.campaign_admin_actions?.deactivate_message ??
					"La campaña se desactivará y ya no permitirá nuevas asignaciones. Los créditos ya asignados conservarán su estado actual."
				}
				processing={deactivating}
				destroy={confirmDeactivate}
				confirmLabel="Desactivar"
			/>
			<DeleteConfirmationModal
				isOpen={!!deleteCampaignTarget}
				close={() => setDeleteCampaignTarget(null)}
				title="Eliminar campaña"
				description="Se eliminará permanentemente esta campaña sin actividad. Esta acción no se puede deshacer."
				processing={deletingCampaign}
				destroy={confirmDeleteCampaign}
				confirmLabel="Eliminar"
			/>
		</AdminLayout>
	);
}
