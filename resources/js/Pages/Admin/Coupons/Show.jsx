import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Badge } from "@/Components/Catalyst/badge";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import Modal from "@/Components/Catalyst/modal";

function formatShortDateTime(iso) {
	if (!iso) return "—";
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "short",
		timeStyle: "short",
	});
}

function resolveApprovalsPreview(amountCents, beneficiaryCount, rules) {
	if (!rules) return 0;
	let byAmount = 0;
	if (
		rules.amount_threshold_cents != null &&
		amountCents >= rules.amount_threshold_cents
	) {
		byAmount = rules.required_approvals_by_amount ?? 0;
	}
	let byBen = 0;
	for (const r of rules.beneficiary_rules ?? []) {
		const min = r.min_beneficiaries;
		const max = r.max_beneficiaries;
		if (beneficiaryCount >= min && (max == null || beneficiaryCount <= max)) {
			byBen = Math.max(byBen, r.required_approvals ?? 0);
		}
	}
	return Math.max(byAmount, byBen);
}

function userLabel(user) {
	if (!user) return "—";
	const fullName = user.full_name?.trim();
	return fullName ? `${fullName} · ${user.email}` : user.email || "—";
}

function participantStatusBadge(status) {
	if (status === "approved") return { color: "emerald", label: "Aprobó" };
	if (status === "rejected") return { color: "red", label: "Rechazó" };
	return { color: "zinc", label: "Pendiente" };
}

function CollapsiblePanel({
	open,
	onToggle,
	title,
	summaryCollapsed,
	children,
	tone = "default",
	bodyClassName = "px-4 pb-4 pt-3",
}) {
	const shell =
		tone === "emerald"
			? "border-emerald-300/90 bg-emerald-50 dark:border-emerald-500/40 dark:bg-emerald-950/35"
			: "border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900";
	const divider =
		tone === "emerald"
			? "border-emerald-200/70 dark:border-emerald-500/20"
			: "border-zinc-100 dark:border-zinc-700";
	const summaryBox =
		tone === "emerald"
			? "bg-emerald-100/70 text-emerald-950 ring-1 ring-emerald-200/80 dark:bg-emerald-900/45 dark:text-emerald-100 dark:ring-emerald-500/20"
			: "bg-zinc-50 text-zinc-700 ring-1 ring-zinc-100 dark:bg-zinc-800/70 dark:text-zinc-300 dark:ring-zinc-700";

	return (
		<div className={`rounded-xl border shadow-sm ${shell}`}>
			<div className="flex items-start justify-between gap-3 p-3 sm:p-4">
				<div className="min-w-0 flex-1">
					<div className="flex flex-wrap items-center gap-2">{title}</div>
					{!open && summaryCollapsed ? (
						<div className={`mt-3 rounded-lg px-3 py-2 text-sm leading-relaxed ${summaryBox}`}>
							{summaryCollapsed}
						</div>
					) : null}
				</div>
				<Button
					type="button"
					plain
					className="shrink-0 text-sm font-semibold"
					onClick={() => onToggle(!open)}
					aria-expanded={open}
				>
					{open ? "Ocultar" : "Mostrar"}
				</Button>
			</div>
			{open ? (
				<div className={`border-t ${divider} ${bodyClassName}`}>{children}</div>
			) : null}
		</div>
	);
}

function csrfTokenFromMeta() {
	if (typeof document === "undefined") return "";
	return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? "";
}

export default function CouponsShow({
	coupon,
	beneficiaryRows,
	authorizationRecipientEmail,
	mailSetupHint,
	authorizers = [],
	rulesForUi,
	isSuperadmin = false,
	assignmentMultiSig = null,
	executedPreApprovalSummary = null,
}) {
	const {
		data: authData,
		setData: setAuthData,
		post: postAuth,
		errors: authErrors,
		processing: authProcessing,
	} = useForm({ code: "" });
	const { post: postResend, processing: resendProcessing } = useForm({});
	const [revokeTarget, setRevokeTarget] = useState(null);
	const { delete: destroy, processing: revoking } = useForm({});
	const [assignOpen, setAssignOpen] = useState(false);
	const [bulkRows, setBulkRows] = useState([]);
	const [bulkPreviewLoading, setBulkPreviewLoading] = useState(false);
	const [bulkPreviewError, setBulkPreviewError] = useState("");
	const bulkRowsRef = useRef([]);

	const [showPreApprovalDoneDetail, setShowPreApprovalDoneDetail] = useState(false);
	const [showCouponDataCard, setShowCouponDataCard] = useState(true);
	const [showAuditCard, setShowAuditCard] = useState(true);
	const [showStatusCard, setShowStatusCard] = useState(true);

	const {
		data: assignData,
		setData: setAssignData,
		post: postAssign,
		errors: assignErrors,
		processing: assigning,
		transform: transformAssign,
	} = useForm({
		coupon_mode: "existing",
		assignment_mode: "individual",
		coupon_id: coupon.id,
		email: "",
		file: null,
		send_notification: true,
		send_notifications: true,
		authorizer_ids: [],
	});

	bulkRowsRef.current = bulkRows;

	transformAssign((d) => {
		const out = {
			coupon_mode: d.coupon_mode,
			assignment_mode: d.assignment_mode,
			coupon_id: d.coupon_id,
			send_notification: d.send_notification,
			send_notifications: d.send_notifications,
			authorizer_ids: d.authorizer_ids,
		};
		if (d.assignment_mode === "individual") {
			out.email = d.email;
		}
		if (d.assignment_mode === "bulk") {
			const confirmed = bulkRowsRef.current
				.filter((r) => r.include)
				.map((r) => r.email);
			if (confirmed.length > 0) {
				out.bulk_emails = confirmed;
			} else if (d.file) {
				out.file = d.file;
			}
		}
		return out;
	});
	const [userLookup, setUserLookup] = useState({
		status: "idle",
		exists: null,
		user: null,
		message: "",
	});
	const { post: postApprovalDecision, processing: approvalDecisionProcessing } = useForm({});

	const submitAuth = (e) => {
		e.preventDefault();
		postAuth(route("admin.coupons.authorize", coupon.id));
	};

	const resendCode = () => {
		postResend(route("admin.coupons.resend-authorization", coupon.id));
	};

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

	const pending = coupon.approval_status === "pending_authorization";
	const assignedCount = beneficiaryRows.length;
	const availableSlots =
		coupon.max_beneficiaries == null
			? null
			: Math.max(coupon.max_beneficiaries - assignedCount, 0);
	const preApprovalMultisigPending = Boolean(assignmentMultiSig?.pre_approval_only);
	const assignBypassMultisig = Boolean(executedPreApprovalSummary);
	const canAssignInPlace =
		!pending &&
		coupon.is_active &&
		(availableSlots == null || availableSlots > 0) &&
		!preApprovalMultisigPending;

	const requiredApprovals = useMemo(
		() => resolveApprovalsPreview(coupon.amount_cents, 1, rulesForUi),
		[coupon.amount_cents, rulesForUi],
	);

	const needsAuthorizers =
		requiredApprovals > 0 &&
		!(isSuperadmin && rulesForUi?.superadmin_bypass_approvals) &&
		!assignBypassMultisig;

	const assignReady = useMemo(() => {
		if (assignData.assignment_mode === "bulk") {
			const selected = bulkRows.filter((r) => r.include).length;
			return bulkRows.length > 0 && selected > 0;
		}
		return (
			assignData.email.trim().length > 0 &&
			userLookup.exists === true &&
			(!needsAuthorizers || assignData.authorizer_ids.length > 0)
		);
	}, [
		assignData.assignment_mode,
		assignData.email,
		assignData.authorizer_ids.length,
		userLookup.exists,
		needsAuthorizers,
		bulkRows,
	]);

	const runBulkPreview = useCallback(async () => {
		if (!assignData.file) {
			setBulkPreviewError("Selecciona un archivo primero.");
			return;
		}
		setBulkPreviewLoading(true);
		setBulkPreviewError("");
		try {
			const fd = new FormData();
			fd.append("file", assignData.file);
			const res = await fetch(route("admin.coupons.assign.preview-bulk"), {
				method: "POST",
				headers: {
					Accept: "application/json",
					"X-Requested-With": "XMLHttpRequest",
					"X-CSRF-TOKEN": csrfTokenFromMeta(),
				},
				body: fd,
			});
			const json = await res.json().catch(() => ({}));
			if (!res.ok) {
				setBulkPreviewError(json.message ?? "No se pudo leer el archivo.");
				setBulkRows([]);
				return;
			}
			setBulkRows(
				(json.rows ?? []).map((r) => ({
					email: r.email,
					exists: !!r.exists,
					user_name: r.user_name ?? null,
					include: !!r.exists,
				})),
			);
		} catch {
			setBulkPreviewError("Error de red al analizar el archivo.");
			setBulkRows([]);
		} finally {
			setBulkPreviewLoading(false);
		}
	}, [assignData.file]);

	const approvalRealtimeText = useMemo(() => {
		if (requiredApprovals === 0) {
			return "Esta asignación se puede ejecutar sin aprobaciones adicionales.";
		}
		if (isSuperadmin && rulesForUi?.superadmin_bypass_approvals) {
			return `Las reglas pedirían ${requiredApprovals} aprobación(es), pero puedes omitirlas por excepción de superadmin.`;
		}
		if (assignData.authorizer_ids.length === 0) {
			return `Se requieren ${requiredApprovals} aprobación(es). Selecciona autorizadores para enviar la solicitud.`;
		}
		return `Se solicitarán ${Math.min(requiredApprovals, assignData.authorizer_ids.length)} aprobación(es).`;
	}, [
		requiredApprovals,
		isSuperadmin,
		rulesForUi?.superadmin_bypass_approvals,
		assignData.authorizer_ids.length,
	]);

	useEffect(() => {
		setAssignData("coupon_id", coupon.id);
	}, [coupon.id, setAssignData]);

	useEffect(() => {
		if (!assignOpen || assignData.assignment_mode === "bulk") {
			setUserLookup({
				status: "idle",
				exists: null,
				user: null,
				message: "",
			});
			return;
		}

		const email = assignData.email.trim();
		if (!email) {
			setUserLookup({
				status: "idle",
				exists: null,
				user: null,
				message: "Escribe un correo para validar si el usuario existe.",
			});
			return;
		}

		const basicEmailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
		if (!basicEmailOk) {
			setUserLookup({
				status: "invalid",
				exists: null,
				user: null,
				message: "Formato de correo inválido.",
			});
			return;
		}

		let cancelled = false;
		setUserLookup((prev) => ({
			...prev,
			status: "checking",
			message: "Validando usuario...",
		}));

		const timer = setTimeout(async () => {
			try {
				const response = await fetch(
					`${route("admin.coupons.users.lookup")}?email=${encodeURIComponent(email)}`,
					{
						headers: {
							Accept: "application/json",
							"X-Requested-With": "XMLHttpRequest",
						},
					},
				);
				if (!response.ok) {
					throw new Error("lookup_failed");
				}
				const payload = await response.json();
				if (cancelled) return;

				if (payload.exists) {
					setUserLookup({
						status: "found",
						exists: true,
						user: payload.user,
						message: `Usuario encontrado: ${payload.user?.name || payload.user?.email}`,
					});
					return;
				}

				setUserLookup({
					status: "missing",
					exists: false,
					user: null,
					message: "No existe un usuario registrado con ese correo.",
				});
			} catch (_) {
				if (cancelled) return;
				setUserLookup({
					status: "error",
					exists: null,
					user: null,
					message: "No se pudo validar el correo en este momento.",
				});
			}
		}, 350);

		return () => {
			cancelled = true;
			clearTimeout(timer);
		};
	}, [assignData.email, assignData.assignment_mode, assignOpen]);

	const openAssignModal = () => setAssignOpen(true);
	const closeAssignModal = () => {
		setAssignOpen(false);
		setAssignData("assignment_mode", "individual");
		setAssignData("email", "");
		setAssignData("file", null);
		setAssignData("authorizer_ids", []);
		setAssignData("coupon_id", coupon.id);
		setBulkRows([]);
		setBulkPreviewError("");
		setBulkPreviewLoading(false);
	};

	const submitAssign = (e) => {
		e.preventDefault();
		postAssign(route("admin.coupons.assign.store"), {
			preserveScroll: true,
			forceFormData: assignData.assignment_mode === "bulk",
			onSuccess: () => closeAssignModal(),
		});
	};

	const assignModalPillClass = (active) =>
		[
			"inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
			active
				? "bg-famedic-lime/15 text-famedic-dark ring-1 ring-famedic-lime/60 dark:bg-famedic-lime/10 dark:text-famedic-lime dark:ring-famedic-lime/50"
				: "text-zinc-600 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-400 dark:ring-zinc-600 dark:hover:bg-zinc-800",
		].join(" ");

	const approveMyPending = () => {
		if (!assignmentMultiSig?.i_can_approve) return;
		postApprovalDecision(
			route("admin.coupons.approval-requests.approve", {
				approvalRequest: assignmentMultiSig.id,
			}),
			{ preserveScroll: true },
		);
	};

	const rejectMyPending = () => {
		if (!assignmentMultiSig?.i_can_approve) return;
		if (
			typeof window !== "undefined" &&
			!window.confirm(
				"¿Rechazar esta solicitud? El solicitante deberá iniciar otro flujo si aplica.",
			)
		) {
			return;
		}
		postApprovalDecision(
			route("admin.coupons.approval-requests.reject", {
				approvalRequest: assignmentMultiSig.id,
			}),
			{ preserveScroll: true },
		);
	};

	return (
		<AdminLayout title={`Cupón #${coupon.id}`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Cupón #{coupon.id}</Heading>
						<p className="mt-1 text-base/6 text-zinc-700 dark:text-zinc-300 sm:text-sm/6">
							{coupon.description || "Sin descripción"}
						</p>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button href={route("admin.coupons.index")} plain>
							Volver al listado
						</Button>
						<Button
							type="button"
							outline
							onClick={openAssignModal}
							disabled={!canAssignInPlace}
						>
							Agregar beneficiario
						</Button>
						<Button href={route("admin.coupons.edit", coupon.id)} outline>
							Editar
						</Button>
					</div>
				</div>

				{false && assignmentMultiSig && (
					<div
						className="rounded-xl border border-amber-300/90 bg-amber-50 p-5 shadow-sm dark:border-amber-500/45 dark:bg-amber-950/35"
						role="region"
						aria-label="Autorizaciones multi-firma pendientes"
					>
						<div className="flex flex-wrap items-start justify-between gap-3">
							<div className="space-y-2">
								<div className="flex flex-wrap items-center gap-2">
									<Subheading className="text-amber-950 dark:text-amber-50">
										Autorizaciones requeridas
									</Subheading>
									<Badge color="amber">Solicitud #{assignmentMultiSig.id}</Badge>
									{assignmentMultiSig.i_can_approve && (
										<Badge color="lime">Tu firma pendiente</Badge>
									)}
								</div>
								<p className="text-sm text-amber-900/90 dark:text-amber-100/85">
									<strong>
										{assignmentMultiSig.current_approvals} de{" "}
										{assignmentMultiSig.required_approvals}
									</strong>{" "}
									aprobación(es) registrada(s).
									{assignmentMultiSig.remaining_approvals > 0 ? (
										<>
											{" "}
											Faltan{" "}
											<strong>{assignmentMultiSig.remaining_approvals}</strong> para
											completar la solicitud
											{assignmentMultiSig.pre_approval_only
												? " y poder asignar beneficiarios a este cupón."
												: " y ejecutar la asignación acordada."}
										</>
									) : (
										<> La solicitud está por completarse al registrar la última firma.</>
									)}
								</p>
								{assignmentMultiSig.pre_approval_only && (
									<p className="text-sm font-medium text-amber-950/90 dark:text-amber-100/90">
										Flujo de pre-aprobación: mientras falte alguna firma, el cupón no
										admite nuevos beneficiarios desde esta pantalla.
									</p>
								)}
								{assignmentMultiSig.requested_by && (
									<p className="text-sm text-amber-900/85 dark:text-amber-100/80">
										Solicitó:{" "}
										<strong>
											{assignmentMultiSig.requested_by.name ||
												assignmentMultiSig.requested_by.email}
										</strong>
										{assignmentMultiSig.requested_by.email &&
											assignmentMultiSig.requested_by.name && (
												<span className="mt-0.5 block break-all text-xs opacity-90">
													{assignmentMultiSig.requested_by.email}
												</span>
											)}
									</p>
								)}
							</div>
						</div>

						<div className="mt-4 overflow-x-auto rounded-lg border border-amber-200/80 bg-white/60 dark:border-amber-500/25 dark:bg-zinc-900/40">
							<Table>
								<TableHead>
									<TableRow>
										<TableHeader>Autorizador</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Registró la acción</TableHeader>
										<TableHeader>Fecha</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{assignmentMultiSig.participants.map((p, idx) => {
										const st = participantStatusBadge(p.status);
										return (
											<TableRow key={`${assignmentMultiSig.id}-p-${idx}-${p.administrator_id}`}>
												<TableCell>
													<div className="font-medium text-zinc-900 dark:text-zinc-100">
														{p.label}
														{p.is_me && (
															<span className="ml-2 text-xs font-normal text-amber-800 dark:text-amber-200">
																(tú)
															</span>
														)}
													</div>
													{p.email && (
														<div className="break-all text-xs text-zinc-600 dark:text-zinc-400">
															{p.email}
														</div>
													)}
												</TableCell>
												<TableCell>
													<Badge color={st.color}>{st.label}</Badge>
												</TableCell>
												<TableCell className="max-w-[14rem] text-sm text-zinc-800 dark:text-zinc-200">
													{p.acted_by ? (
														<>
															<div className="font-medium">
																{p.acted_by.name || p.acted_by.email}
															</div>
															{p.acted_by.email && p.acted_by.name && (
																<div className="break-all text-xs text-zinc-500 dark:text-zinc-400">
																	{p.acted_by.email}
																</div>
															)}
														</>
													) : (
														<span className="text-zinc-500 dark:text-zinc-400">—</span>
													)}
												</TableCell>
												<TableCell className="whitespace-nowrap text-sm text-zinc-700 dark:text-zinc-300">
													{p.acted_at ? formatShortDateTime(p.acted_at) : "—"}
												</TableCell>
											</TableRow>
										);
									})}
								</TableBody>
							</Table>
						</div>

						<div className="mt-4 flex flex-wrap items-center gap-2">
							{assignmentMultiSig.i_can_approve ? (
								<>
									<Button
										type="button"
										color="emerald"
										disabled={approvalDecisionProcessing}
										onClick={approveMyPending}
									>
										{approvalDecisionProcessing ? "Procesando…" : "Aprobar solicitud"}
									</Button>
									<Button
										type="button"
										outline
										className="border-red-300 text-red-700 hover:bg-red-50 dark:border-red-500/50 dark:text-red-300 dark:hover:bg-red-950/40"
										disabled={approvalDecisionProcessing}
										onClick={rejectMyPending}
									>
										Rechazar
									</Button>
								</>
							) : (
								<p className="text-sm text-amber-900/85 dark:text-amber-100/75">
									{assignmentMultiSig.participants.some((p) => p.is_me && p.status === "approved")
										? "Ya registraste tu aprobación en esta solicitud."
										: "Aquí solo pueden firmar los autorizadores designados en la solicitud."}
								</p>
							)}
							<Button href={route("admin.coupons.logs")} plain>
								Ver registro
							</Button>
						</div>
					</div>
				)}

				{executedPreApprovalSummary && !assignmentMultiSig && (
					<div role="region" aria-label="Pre-aprobación completada">
						<CollapsiblePanel
							open={showPreApprovalDoneDetail}
							onToggle={setShowPreApprovalDoneDetail}
							tone="emerald"
							summaryCollapsed={`${executedPreApprovalSummary.approvers.length}/${executedPreApprovalSummary.required_approvals} firma(s)${
								executedPreApprovalSummary.executed_at
									? ` · Cerrada ${formatShortDateTime(executedPreApprovalSummary.executed_at)}`
									: ""
							}${
								executedPreApprovalSummary.requested_by
									? ` · Solicitó: ${executedPreApprovalSummary.requested_by.name || executedPreApprovalSummary.requested_by.email}`
									: ""
							}`}
							bodyClassName="space-y-3 px-4 pb-4 pt-3 text-emerald-950 dark:text-emerald-50"
							title={
								<>
									<Subheading className="text-emerald-950 dark:text-emerald-50">
										Pre-aprobación completada
									</Subheading>
									<Badge color="emerald">
										Solicitud #{executedPreApprovalSummary.request_id}
									</Badge>
								</>
							}
						>
							<p className="text-sm text-emerald-900/90 dark:text-emerald-100/85">
								Este cupón ya cuenta con las autorizaciones multi-firma necesarias para operar.
								{executedPreApprovalSummary.executed_at && (
									<>
										{" "}
										Cerrada el{" "}
										<strong>
											{formatShortDateTime(executedPreApprovalSummary.executed_at)}
										</strong>
									</>
								)}
								{executedPreApprovalSummary.requested_by && (
									<>
										{" "}
										· Solicitó{" "}
										<strong>
											{executedPreApprovalSummary.requested_by.name ||
												executedPreApprovalSummary.requested_by.email}
										</strong>
									</>
								)}
							</p>
							<p className="text-xs font-medium uppercase tracking-wide text-emerald-800 dark:text-emerald-200">
								Autorizadores que firmaron ({executedPreApprovalSummary.approvers.length}/
								{executedPreApprovalSummary.required_approvals})
							</p>
							<ul className="space-y-2 text-sm">
								{executedPreApprovalSummary.approvers.map((a, idx) => (
									<li
										key={`apr-${idx}-${a.email ?? a.label}`}
										className="rounded-lg border border-emerald-200/70 bg-white/70 px-3 py-2 dark:border-emerald-500/25 dark:bg-zinc-900/40"
									>
										<span className="font-medium">{a.label}</span>
										{a.email && (
											<span className="ml-1 break-all text-xs text-emerald-800/90 dark:text-emerald-200/90">
												({a.email})
											</span>
										)}
										{a.acted_at && (
											<span className="mt-1 block text-xs text-emerald-800/80 dark:text-emerald-200/80">
												Firmó: {formatShortDateTime(a.acted_at)}
												{a.acted_by?.name && (
													<> · Usuario que registró: {a.acted_by.name}</>
												)}
											</span>
										)}
									</li>
								))}
							</ul>
						</CollapsiblePanel>
					</div>
				)}

				<div className="grid gap-4 lg:grid-cols-3">
					<CollapsiblePanel
						open={showCouponDataCard}
						onToggle={setShowCouponDataCard}
						title={
							<p className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
								Datos del cupón
							</p>
						}
						summaryCollapsed={
							<div className="space-y-1">
								<p>
									<span className="font-semibold">Código:</span> {coupon.code || "—"}
								</p>
								<p>
									<span className="font-semibold">Crédito:</span>{" "}
									{(coupon.amount_cents / 100).toLocaleString("es-MX", {
										style: "currency",
										currency: "MXN",
									})}
								</p>
							</div>
						}
					>
						<dl className="space-y-2 text-sm">
							<div>
								<dt className="text-zinc-500 dark:text-zinc-400">Descripción</dt>
								<dd className="mt-0.5 text-zinc-900 dark:text-zinc-100">
									{coupon.description || "Sin descripción"}
								</dd>
							</div>
							<div className="flex justify-between gap-3">
								<dt className="text-zinc-500 dark:text-zinc-400">Código</dt>
								<dd className="font-medium text-zinc-900 dark:text-zinc-100">
									{coupon.code || "—"}
								</dd>
							</div>
							<div className="flex justify-between gap-3">
								<dt className="text-zinc-500 dark:text-zinc-400">Monto por beneficiario</dt>
								<dd className="font-medium text-zinc-900 dark:text-zinc-100">
									{(coupon.amount_cents / 100).toLocaleString("es-MX", {
										style: "currency",
										currency: "MXN",
									})}
								</dd>
							</div>
							<div className="flex justify-between gap-3">
								<dt className="text-zinc-500 dark:text-zinc-400">Máx. beneficiarios</dt>
								<dd className="font-medium text-zinc-900 dark:text-zinc-100">
									{coupon.max_beneficiaries != null ? coupon.max_beneficiaries : "Sin límite"}
								</dd>
							</div>
						</dl>
					</CollapsiblePanel>

					<CollapsiblePanel
						open={showAuditCard}
						onToggle={setShowAuditCard}
						title={
							<p className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
								Auditoría
							</p>
						}
						summaryCollapsed={
							<div className="space-y-1">
								<p className="font-semibold">Creado Por:</p>
								<p className="break-all">
									{coupon.created_by_user?.email || userLabel(coupon.created_by_user)}
								</p>
							</div>
						}
					>
						<dl className="space-y-3 text-sm">
							<div>
								<dt className="text-zinc-500 dark:text-zinc-400">Creado</dt>
								<dd className="text-zinc-900 dark:text-zinc-100">
									{formatShortDateTime(coupon.created_at)}
								</dd>
								<dd className="text-xs text-zinc-500 dark:text-zinc-400">
									{userLabel(coupon.created_by_user)}
								</dd>
							</div>
							<div>
								<dt className="text-zinc-500 dark:text-zinc-400">Última edición por</dt>
								<dd className="text-zinc-900 dark:text-zinc-100">
									{userLabel(coupon.updated_by_user)}
								</dd>
							</div>
							<div>
								<dt className="text-zinc-500 dark:text-zinc-400">Autorizado por</dt>
								<dd className="text-zinc-900 dark:text-zinc-100">
									{coupon.authorized_by_user
										? userLabel(coupon.authorized_by_user)
										: pending
											? "Pendiente"
											: "—"}
								</dd>
							</div>
						</dl>
					</CollapsiblePanel>

					<CollapsiblePanel
						open={showStatusCard}
						onToggle={setShowStatusCard}
						title={
							<p className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
								Disponibilidad
							</p>
						}
						summaryCollapsed={
							<div className="space-y-1">
								<p className="font-semibold">Max Beneficiarios:</p>
								<p>{coupon.max_beneficiaries != null ? coupon.max_beneficiaries : "Sin límite"}</p>
							</div>
						}
					>
						<div className="flex flex-wrap gap-2">
							{pending ? (
								<Badge color="purple">Pendiente de autorización</Badge>
							) : (
								<Badge color="emerald">Autorizado</Badge>
							)}
							{coupon.is_active ? (
								<Badge color="emerald">Activo</Badge>
							) : (
								<Badge color="zinc">Inactivo</Badge>
							)}
							{assignmentMultiSig && (
								<Badge color="amber">
									Multi-firma: {assignmentMultiSig.current_approvals}/
									{assignmentMultiSig.required_approvals}
								</Badge>
							)}
						</div>
						<p className="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
							Beneficiarios asignados: <strong>{assignedCount}</strong>
							{coupon.max_beneficiaries != null ? ` / ${coupon.max_beneficiaries}` : " (sin límite)"}
						</p>
						{availableSlots != null && (
							<p className="text-sm text-zinc-700 dark:text-zinc-300">
								Disponibles: <strong>{availableSlots}</strong>
							</p>
						)}
						{!canAssignInPlace && (
							<p className="mt-2 text-sm text-amber-700 dark:text-amber-300">
								{preApprovalMultisigPending
									? "No puedes agregar beneficiarios hasta completar las autorizaciones de pre-aprobación indicadas arriba."
									: "No puedes agregar beneficiarios hasta que el cupón esté activo y con disponibilidad."}
							</p>
						)}
					</CollapsiblePanel>
				</div>

				{assignmentMultiSig && (
					<div
						className="rounded-xl border border-amber-300/90 bg-amber-50 p-5 shadow-sm dark:border-amber-500/45 dark:bg-amber-950/35"
						role="region"
						aria-label="Autorizaciones requeridas"
					>
						<div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
							<div className="min-w-0 space-y-2">
								<div className="flex flex-wrap items-center gap-2">
									<Subheading className="text-amber-950 dark:text-amber-50">
										Autorizaciones requeridas
									</Subheading>
									<Badge color="amber">Solicitud #{assignmentMultiSig.id}</Badge>
									{assignmentMultiSig.i_can_approve && (
										<Badge color="lime">Tu firma pendiente</Badge>
									)}
								</div>
								<p className="text-sm text-amber-900/90 dark:text-amber-100/85">
									<strong>
										{assignmentMultiSig.current_approvals} de{" "}
										{assignmentMultiSig.required_approvals}
									</strong>{" "}
									aprobación(es) registrada(s).
									{assignmentMultiSig.remaining_approvals > 0 && (
										<>
											{" "}
											Faltan{" "}
											<strong>{assignmentMultiSig.remaining_approvals}</strong> para
											completar la solicitud y poder asignar beneficiarios a este cupón.
										</>
									)}
								</p>
							</div>
							<div className="flex shrink-0 flex-wrap items-center gap-2">
								{assignmentMultiSig.i_can_approve ? (
									<>
										<Button
											type="button"
											color="emerald"
											disabled={approvalDecisionProcessing}
											onClick={approveMyPending}
										>
											{approvalDecisionProcessing ? "Procesando..." : "Aprobar crédito"}
										</Button>
										<Button
											type="button"
											outline
											className="border-red-300 text-red-700 hover:bg-red-50 dark:border-red-500/50 dark:text-red-300 dark:hover:bg-red-950/40"
											disabled={approvalDecisionProcessing}
											onClick={rejectMyPending}
										>
											Rechazar
										</Button>
									</>
								) : (
									<Text className="text-sm text-amber-900/85 dark:text-amber-100/75">
										{assignmentMultiSig.participants.some((p) => p.is_me && p.status === "approved")
											? "Tu aprobación ya fue registrada."
											: "Pendiente de firmas de los autorizadores."}
									</Text>
								)}
								<Button href={route("admin.coupons.logs")} plain>
									Ver registro
								</Button>
							</div>
						</div>
					</div>
				)}

				{pending && mailSetupHint && (
					<div
						className="rounded-lg border border-amber-300/80 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-950/50 dark:text-amber-100"
						role="status"
					>
						<p className="font-medium">Entorno de correo</p>
						<p className="mt-1 leading-relaxed">{mailSetupHint}</p>
					</div>
				)}

				{pending && (
					<form
						onSubmit={submitAuth}
						className="max-w-lg space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/90 p-5 shadow-sm dark:border-zinc-600 dark:bg-zinc-800/60"
					>
						<div className="flex flex-wrap items-start justify-between gap-3">
							<Subheading className="text-famedic-darker dark:text-white">
								Autorizar cupón
							</Subheading>
							<Button
								type="button"
								outline
								disabled={resendProcessing || authProcessing}
								onClick={resendCode}
							>
								{resendProcessing ? "Enviando…" : "Reenviar código"}
							</Button>
						</div>
						{authorizationRecipientEmail && (
							<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
								Correo del autorizador:{" "}
								<span className="break-all font-normal text-zinc-700 dark:text-zinc-300">
									{authorizationRecipientEmail}
								</span>
							</p>
						)}
						{coupon.authorization_code_expires_at && (
							<p className="text-sm text-zinc-600 dark:text-zinc-400">
								Válido hasta:{" "}
								<span className="font-medium text-zinc-800 dark:text-zinc-200">
									{formatShortDateTime(coupon.authorization_code_expires_at)}
								</span>
							</p>
						)}
						<Field>
							<Label>Código</Label>
							<Input
								value={authData.code}
								onChange={(e) => setAuthData("code", e.target.value)}
								maxLength={32}
								autoComplete="one-time-code"
								className="font-mono text-lg tracking-widest"
							/>
							{authErrors.code && (
								<p className="text-sm text-red-600 dark:text-red-400">{authErrors.code}</p>
							)}
						</Field>
						<Button type="submit" disabled={authProcessing} color="emerald">
							Confirmar autorización
						</Button>
					</form>
				)}

				<Subheading className="text-famedic-darker dark:text-white">
					Beneficiarios y uso
				</Subheading>
				<Text className="!text-zinc-600 dark:!text-zinc-400">
					Cada fila corresponde a un cupón hijo asignado a un usuario.
				</Text>

				<div className="overflow-x-auto">
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Usuario</TableHeader>
								<TableHeader>Correo</TableHeader>
								<TableHeader>Cliente</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader>Asignado</TableHeader>
								<TableHeader>Usado</TableHeader>
								<TableHeader>Compra</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{beneficiaryRows.length === 0 ? (
								<TableRow>
									<TableCell colSpan={8} className="text-zinc-500 dark:text-zinc-400">
										Aún no hay beneficiarios.
									</TableCell>
								</TableRow>
							) : (
								beneficiaryRows.map((row, idx) => (
									<TableRow key={`${row.coupon_id}-${row.assignment_id ?? idx}`}>
										<TableCell className="text-zinc-900 dark:text-zinc-100">
											{row.user?.full_name?.trim() || "—"}
										</TableCell>
										<TableCell className="max-w-[12rem] break-all text-sm text-zinc-800 dark:text-zinc-200">
											{row.user?.email || "—"}
										</TableCell>
										<TableCell>
											{row.customer_admin_url ? (
												<Button href={row.customer_admin_url} plain>
													Ver cliente
												</Button>
											) : (
												<span className="text-zinc-500 dark:text-zinc-400">—</span>
											)}
										</TableCell>
										<TableCell>
											{row.used_at ? <Badge color="blue">Usado</Badge> : <Badge color="amber">Pendiente</Badge>}
										</TableCell>
										<TableCell className="text-xs text-zinc-600 dark:text-zinc-400">
											{formatShortDateTime(row.assigned_at)}
										</TableCell>
										<TableCell className="text-xs text-zinc-600 dark:text-zinc-400">
											{formatShortDateTime(row.used_at)}
										</TableCell>
										<TableCell>
											{row.transaction?.purchase_url ? (
												<Button href={row.transaction.purchase_url} plain>
													{row.transaction.purchase_type === "lab" ? "Laboratorio" : "Farmacia"}
												</Button>
											) : (
												<span className="text-zinc-500 dark:text-zinc-400">—</span>
											)}
										</TableCell>
										<TableCell className="text-right">
											{!row.used_at && row.assignment_id ? (
												<Button
													plain
													className="text-red-600 dark:text-red-400"
													onClick={() =>
														setRevokeTarget({
															couponId: row.coupon_id,
															assignmentId: row.assignment_id,
															email: row.user?.email,
														})
													}
												>
													Quitar
												</Button>
											) : null}
										</TableCell>
									</TableRow>
								))
							)}
						</TableBody>
					</Table>
				</div>
			</div>

			<Modal
				open={assignOpen}
				onClose={closeAssignModal}
				size={assignData.assignment_mode === "bulk" ? "4xl" : "lg"}
			>
				<form onSubmit={submitAssign} className="space-y-4">
					<div className="flex items-start justify-between gap-3">
						<div>
							<h3 className="text-lg font-semibold text-zinc-900 dark:text-white">
								Agregar beneficiario al cupón #{coupon.id}
							</h3>
							<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
								Esta acción se ejecuta aquí mismo sin salir del detalle del cupón.
							</p>
						</div>
						<Button type="button" plain onClick={closeAssignModal}>
							Cerrar
						</Button>
					</div>

					{assignBypassMultisig && (
						<div
							className="rounded-lg border border-emerald-300/80 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 dark:border-emerald-500/35 dark:bg-emerald-950/35 dark:text-emerald-50"
							role="status"
						>
							<p className="font-medium">Permisos ya cubiertos</p>
							<p className="mt-1 text-emerald-900/90 dark:text-emerald-100/85">
								Este cupón completó la pre-aprobación multi-firma; puedes asignar por correo o
								carga masiva sin solicitar nuevas aprobaciones para estas operaciones.
							</p>
						</div>
					)}

					{assignBypassMultisig ? (
						<div className="flex flex-wrap gap-2" role="tablist" aria-label="Tipo de asignación">
							<button
								type="button"
								className={assignModalPillClass(assignData.assignment_mode === "individual")}
								aria-selected={assignData.assignment_mode === "individual"}
								onClick={() => {
									setAssignData("assignment_mode", "individual");
									setAssignData("file", null);
									setBulkRows([]);
									setBulkPreviewError("");
								}}
							>
								Correo individual
							</button>
							<button
								type="button"
								className={assignModalPillClass(assignData.assignment_mode === "bulk")}
								aria-selected={assignData.assignment_mode === "bulk"}
								onClick={() => {
									setAssignData("assignment_mode", "bulk");
									setAssignData("email", "");
								}}
							>
								Carga masiva (archivo)
							</button>
						</div>
					) : null}

					{assignData.assignment_mode === "individual" && (
						<Field>
							<Label>Correo del usuario (registrado)</Label>
							<Input
								type="email"
								value={assignData.email}
								onChange={(e) => setAssignData("email", e.target.value)}
								placeholder="usuario@correo.com"
								autoComplete="off"
							/>
							<p
								className={[
									"mt-1 text-sm",
									userLookup.status === "found"
										? "text-emerald-700 dark:text-emerald-300"
										: userLookup.status === "missing" ||
											  userLookup.status === "invalid" ||
											  userLookup.status === "error"
											? "text-red-600 dark:text-red-400"
											: "text-zinc-500 dark:text-zinc-400",
								].join(" ")}
							>
								{assignBypassMultisig && userLookup.status === "idle" && !assignData.email.trim()
									? "Escribe un correo registrado en la plataforma."
									: userLookup.message}
							</p>
							{assignErrors.email && (
								<p className="text-sm text-red-600 dark:text-red-400">{assignErrors.email}</p>
							)}
						</Field>
					)}

					{assignData.assignment_mode === "bulk" && assignBypassMultisig && (
						<div className="space-y-4">
							<Field>
								<Label>Archivo de correos</Label>
								<p className="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
									Columna <strong>email</strong> o <strong>correo</strong>. Primero analiza el archivo para
									ver quién tiene cuenta; solo entonces podrás asignar.
								</p>
								<div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
									<Button
										href={route("admin.coupons.assign.bulk-template")}
										outline
										className="text-sm"
									>
										Descargar plantilla CSV de ejemplo
									</Button>
									<span className="text-xs text-zinc-500 dark:text-zinc-400">
										Misma plantilla que en &quot;Crear y asignar cupones&quot;: encabezado{" "}
										<strong>email</strong> y filas de muestra.
									</span>
								</div>
								<Input
									type="file"
									accept=".xlsx,.xls,.csv"
									className="mt-1 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:file:bg-zinc-700"
									onChange={(e) => {
										setAssignData("file", e.target.files?.[0] ?? null);
										setBulkRows([]);
										setBulkPreviewError("");
									}}
								/>
								<div className="mt-3 flex flex-wrap gap-2">
									<Button
										type="button"
										outline
										disabled={!assignData.file || bulkPreviewLoading}
										onClick={() => runBulkPreview()}
									>
										{bulkPreviewLoading ? "Analizando…" : "Analizar archivo y validar usuarios"}
									</Button>
								</div>
								{bulkPreviewError && (
									<p className="mt-2 text-sm text-red-600 dark:text-red-400">{bulkPreviewError}</p>
								)}
								{assignErrors.file && (
									<p className="mt-1 text-sm text-red-600 dark:text-red-400">{assignErrors.file}</p>
								)}
							</Field>

							{bulkRows.length > 0 && (
								<div className="rounded-lg border border-zinc-200 bg-zinc-50/90 p-4 dark:border-zinc-600 dark:bg-zinc-900/50">
									<div className="flex flex-wrap items-start justify-between gap-3">
										<div>
											<p className="text-sm font-semibold text-zinc-900 dark:text-white">
												Vista previa de beneficiarios
											</p>
											<p className="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
												Marca quién recibirá el cupón. Por defecto solo usuarios registrados.
											</p>
										</div>
										<div className="flex flex-wrap gap-2">
											<Button
												type="button"
												plain
												className="text-sm"
												onClick={() =>
													setBulkRows((rows) =>
														rows.map((r) => ({
															...r,
															include: r.exists ? r.include : false,
														})),
													)
												}
											>
												Quitar selección sin cuenta
											</Button>
											<Button
												type="button"
												plain
												className="text-sm text-red-700 dark:text-red-400"
												onClick={() =>
													setBulkRows((rows) => rows.filter((r) => r.exists))
												}
											>
												Eliminar filas sin usuario
											</Button>
										</div>
									</div>
									<p className="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
										Seleccionados:{" "}
										<strong>{bulkRows.filter((r) => r.include).length}</strong> de{" "}
										{bulkRows.length}
									</p>
									<div className="mt-3 max-h-[min(20rem,45vh)] overflow-auto rounded-md border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
										<Table dense>
											<TableHead>
												<TableRow>
													<TableHeader>Incluir</TableHeader>
													<TableHeader>Correo</TableHeader>
													<TableHeader>Usuario</TableHeader>
													<TableHeader>Estado</TableHeader>
													<TableHeader />
												</TableRow>
											</TableHead>
											<TableBody>
												{bulkRows.map((row, idx) => (
													<TableRow key={`${row.email}-${idx}`}>
														<TableCell>
															<Checkbox
																checked={row.include}
																onChange={(v) =>
																	setBulkRows((rows) =>
																		rows.map((r, i) =>
																			i === idx ? { ...r, include: v } : r,
																		),
																	)
																}
															/>
														</TableCell>
														<TableCell className="max-w-[12rem] break-all font-mono text-xs">
															{row.email}
														</TableCell>
														<TableCell className="text-sm text-zinc-700 dark:text-zinc-300">
															{row.user_name ?? "—"}
														</TableCell>
														<TableCell>
															{row.exists ? (
																<Badge color="emerald">Registrado</Badge>
															) : (
																<Badge color="red">Sin cuenta</Badge>
															)}
														</TableCell>
														<TableCell className="text-right">
															<Button
																type="button"
																plain
																className="text-red-600 dark:text-red-400"
																onClick={() =>
																	setBulkRows((rows) =>
																		rows.filter((_, i) => i !== idx),
																	)
																}
															>
																Quitar fila
															</Button>
														</TableCell>
													</TableRow>
												))}
											</TableBody>
										</Table>
									</div>
								</div>
							)}
						</div>
					)}

					<CheckboxField>
						<Checkbox
							checked={assignData.send_notification}
							onChange={(v) => {
								setAssignData("send_notification", v);
								setAssignData("send_notifications", v);
							}}
						/>
						<Label>
							{assignData.assignment_mode === "bulk"
								? "Enviar notificación a cada beneficiario"
								: "Enviar notificación al beneficiario"}
						</Label>
					</CheckboxField>

					{!assignBypassMultisig && (
						<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/40">
							<p className="font-medium text-zinc-900 dark:text-white">
								Validación en tiempo real
							</p>
							<p className="mt-1 text-zinc-700 dark:text-zinc-300">{approvalRealtimeText}</p>
						</div>
					)}

					{needsAuthorizers && (
						<Field>
							<Label>Autorizadores</Label>
							<div className="mt-3 max-h-40 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
								{authorizers.length === 0 ? (
									<p className="text-sm text-zinc-500 dark:text-zinc-400">
										No hay autorizadores disponibles.
									</p>
								) : (
									authorizers.map((authorizer) => {
										const checked = assignData.authorizer_ids.includes(authorizer.id);
										return (
											<CheckboxField key={authorizer.id}>
												<Checkbox
													checked={checked}
													onChange={(v) => {
														if (v) {
															setAssignData("authorizer_ids", [
																...assignData.authorizer_ids,
																authorizer.id,
															]);
															return;
														}
														setAssignData(
															"authorizer_ids",
															assignData.authorizer_ids.filter((id) => id !== authorizer.id),
														);
													}}
												/>
												<Label>
													{authorizer.name} ({authorizer.email || "sin correo"})
												</Label>
											</CheckboxField>
										);
									})
								)}
							</div>
							{assignErrors.authorizer_ids && (
								<p className="text-sm text-red-600 dark:text-red-400">
									{assignErrors.authorizer_ids}
								</p>
							)}
						</Field>
					)}

					<div className="flex justify-end gap-2 pt-2">
						<Button type="button" outline onClick={closeAssignModal}>
							Cancelar
						</Button>
						<Button type="submit" disabled={assigning || !assignReady}>
							{assignData.assignment_mode === "bulk"
								? "Asignar desde archivo"
								: needsAuthorizers
									? "Enviar solicitud"
									: "Asignar beneficiario"}
						</Button>
					</div>
				</form>
			</Modal>

			<DeleteConfirmationModal
				isOpen={!!revokeTarget}
				close={() => setRevokeTarget(null)}
				title="Quitar asignación"
				description={
					revokeTarget
						? `Se eliminará la asignación para ${revokeTarget.email ?? "el usuario"}.`
						: ""
				}
				processing={revoking}
				destroy={confirmRevoke}
			/>
		</AdminLayout>
	);
}
