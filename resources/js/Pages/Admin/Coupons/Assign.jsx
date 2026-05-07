import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { useForm } from "@inertiajs/react";
import { AnimatePresence, motion } from "framer-motion";
import {
	Listbox,
	ListboxDescription,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Badge } from "@/Components/Catalyst/badge";

function csrfTokenFromMeta() {
	if (typeof document === "undefined") return "";
	return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? "";
}

const TABS = [
	{ id: "coupon", label: "Cupón" },
	{ id: "assignment", label: "Asignación de beneficiarios" },
	{ id: "summary", label: "Resumen" },
];

function formatMxFromCents(cents) {
	if (cents == null || cents === "") return "—";
	const n = Number(cents);
	if (Number.isNaN(n)) return "—";
	return (n / 100).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
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
		if (
			beneficiaryCount >= min &&
			(max == null || beneficiaryCount <= max)
		) {
			byBen = Math.max(byBen, r.required_approvals ?? 0);
		}
	}
	return Math.max(byAmount, byBen);
}

/** @param {Record<string, string|string[]>} errs */
function errorsForTab(errs, tabId) {
	if (!errs || typeof errs !== "object") return false;
	const keys = Object.keys(errs);
	const match = (prefix) =>
		keys.some((k) => k === prefix || k.startsWith(`${prefix}.`));

	switch (tabId) {
		case "coupon":
			return (
				match("coupon_mode") ||
				match("coupon_id") ||
				match("amount_cents") ||
				match("code") ||
				match("description") ||
				match("max_beneficiaries") ||
				match("is_active")
			);
		case "assignment":
			return (
				match("assignment_mode") ||
				match("email") ||
				match("file") ||
				match("send_notification") ||
				match("send_notifications") ||
				match("authorizer_ids")
			);
		default:
			return false;
	}
}

const ASSIGNMENT_LABELS = {
	none: "Solo guardar cupón",
	individual: "Un correo",
	bulk: "Archivo masivo",
};

export default function CouponsAssign({
	assignableCoupons,
	authorizers = [],
	settings,
	rulesForUi,
	focus = "",
	initialTab = "coupon",
	isSuperadmin = false,
}) {
	const requireAuth = !!settings?.require_authorization;

	const [search, setSearch] = useState("");
	const [userLookup, setUserLookup] = useState({
		status: "idle",
		exists: null,
		user: null,
		message: "",
	});

	const allowedTabs = new Set(TABS.map((t) => t.id));
	const normalizedInitialTab = useMemo(() => {
		if (focus === "bulk") return "assignment";
		return allowedTabs.has(initialTab) ? initialTab : "coupon";
	}, [focus, initialTab]);

	const [activeTab, setActiveTabState] = useState(normalizedInitialTab);

	const setActiveTab = useCallback((id) => {
		setActiveTabState(id);
		if (typeof window === "undefined") return;
		const url = new URL(window.location.href);
		url.searchParams.set("tab", id);
		window.history.replaceState({}, "", url.toString());
	}, []);

	useEffect(() => {
		setActiveTabState(normalizedInitialTab);
	}, [normalizedInitialTab]);

	const filtered = useMemo(() => {
		const list = assignableCoupons ?? [];
		const s = search.trim().toLowerCase();
		if (!s) {
			return list;
		}
		return list.filter((c) => {
			const hay = `${c.id} ${c.code ?? ""} ${c.description ?? ""}`.toLowerCase();
			return hay.includes(s);
		});
	}, [assignableCoupons, search]);

	const firstId = filtered[0]?.id ?? assignableCoupons?.[0]?.id ?? "";

	const defaultAmount =
		settings?.base_amount_cents != null
			? String(settings.base_amount_cents / 100)
			: "500";

	const [bulkRows, setBulkRows] = useState([]);
	const [bulkPreviewLoading, setBulkPreviewLoading] = useState(false);
	const [bulkPreviewError, setBulkPreviewError] = useState("");
	const bulkRowsRef = useRef([]);

	const { data, setData, post, processing, errors, transform } = useForm({
		coupon_mode: "new",
		assignment_mode:
			focus === "bulk" ? "bulk" : focus === "new" ? "none" : "individual",
		coupon_id: firstId,
		amount_mxn: defaultAmount,
		code: "",
		description: "",
		max_beneficiaries: "",
		is_active: true,
		email: "",
		file: null,
		send_notification: true,
		send_notifications: true,
		authorizer_ids: [],
	});

	bulkRowsRef.current = bulkRows;

	transform((d) => {
		const out = {
			coupon_mode: d.coupon_mode,
			assignment_mode: d.assignment_mode,
			send_notification: d.send_notification,
			send_notifications: d.send_notifications,
			authorizer_ids: d.authorizer_ids,
		};
		if (d.coupon_mode === "existing") {
			out.coupon_id = d.coupon_id;
		}
		if (d.coupon_mode === "new") {
			const cents = Math.round(
				parseFloat(String(d.amount_mxn).replace(",", "")) * 100,
			);
			out.amount_cents = cents;
			out.code = d.code?.trim() ? d.code.trim() : null;
			out.description = d.description?.trim() ? d.description.trim() : null;
			const maxB = String(d.max_beneficiaries ?? "").trim();
			out.max_beneficiaries = maxB === "" ? null : parseInt(maxB, 10);
			out.is_active = d.is_active;
		}
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

	useEffect(() => {
		if (!filtered.length) {
			return;
		}
		if (
			data.coupon_mode === "existing" &&
			!filtered.some((c) => c.id === data.coupon_id)
		) {
			setData("coupon_id", filtered[0].id);
		}
	}, [filtered, data.coupon_id, data.coupon_mode, setData]);

	useEffect(() => {
		for (const t of TABS) {
			if (errorsForTab(errors, t.id)) {
				setActiveTabState(t.id);
				if (typeof window !== "undefined") {
					const url = new URL(window.location.href);
					url.searchParams.set("tab", t.id);
					window.history.replaceState({}, "", url.toString());
				}
				break;
			}
		}
	}, [errors]);

	useEffect(() => {
		if (data.assignment_mode !== "individual") {
			setUserLookup({
				status: "idle",
				exists: null,
				user: null,
				message: "",
			});
			return;
		}

		const email = data.email.trim();
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
	}, [data.assignment_mode, data.email]);

	const amountCentsPreview = useMemo(() => {
		if (data.coupon_mode === "new") {
			const v = parseFloat(String(data.amount_mxn).replace(",", ""));
			if (Number.isNaN(v)) return 0;
			return Math.round(v * 100);
		}
		const sel = (assignableCoupons ?? []).find((c) => c.id === data.coupon_id);
		return sel?.amount_cents ?? 0;
	}, [data.coupon_mode, data.amount_mxn, data.coupon_id, assignableCoupons]);

	const beneficiaryCountPreview = useMemo(() => {
		if (data.assignment_mode === "individual") return 1;
		if (data.assignment_mode === "none") return 0;
		if (data.assignment_mode === "bulk") {
			const n = bulkRows.filter((r) => r.include).length;
			return n > 0 ? n : 0;
		}
		return 0;
	}, [data.assignment_mode, bulkRows]);

	const beneficiariesForPreApprovalDraft = useMemo(() => {
		if (data.coupon_mode !== "new") return 1;
		const parsed = parseInt(String(data.max_beneficiaries ?? "").trim(), 10);
		return Number.isNaN(parsed) || parsed < 1 ? 1 : parsed;
	}, [data.coupon_mode, data.max_beneficiaries]);

	const approvalsPreview = useMemo(
		() =>
			resolveApprovalsPreview(
				amountCentsPreview,
				beneficiaryCountPreview,
				rulesForUi,
			),
		[amountCentsPreview, beneficiaryCountPreview, rulesForUi],
	);

	const preApprovalRequiredForNewCoupon = useMemo(() => {
		if (data.coupon_mode !== "new") return 0;
		return resolveApprovalsPreview(
			amountCentsPreview,
			beneficiariesForPreApprovalDraft,
			rulesForUi,
		);
	}, [
		data.coupon_mode,
		amountCentsPreview,
		beneficiariesForPreApprovalDraft,
		rulesForUi,
	]);

	const mustPreApproveCouponBeforeAssignment =
		data.coupon_mode === "new" &&
		preApprovalRequiredForNewCoupon > 0 &&
		!(isSuperadmin && rulesForUi?.superadmin_bypass_approvals);

	useEffect(() => {
		if (mustPreApproveCouponBeforeAssignment && data.assignment_mode !== "none") {
			setData("assignment_mode", "none");
		}
	}, [mustPreApproveCouponBeforeAssignment, data.assignment_mode, setData]);

	useEffect(() => {
		if (data.assignment_mode !== "bulk") {
			setBulkRows([]);
			setBulkPreviewError("");
			setBulkPreviewLoading(false);
		}
	}, [data.assignment_mode]);

	const runBulkPreview = useCallback(async () => {
		if (!data.file) {
			setBulkPreviewError("Selecciona un archivo primero.");
			return;
		}
		setBulkPreviewLoading(true);
		setBulkPreviewError("");
		try {
			const fd = new FormData();
			fd.append("file", data.file);
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
	}, [data.file]);

	const preApprovalReasons = useMemo(() => {
		if (!mustPreApproveCouponBeforeAssignment) return [];
		const reasons = [];
		if (
			rulesForUi?.amount_threshold_cents != null &&
			amountCentsPreview >= rulesForUi.amount_threshold_cents
		) {
			reasons.push(
				`Monto del cupón (${formatMxFromCents(amountCentsPreview)}) supera el umbral (${formatMxFromCents(rulesForUi.amount_threshold_cents)}).`,
			);
		}
		const matchedRule = (rulesForUi?.beneficiary_rules ?? []).find((r) => {
			const min = r.min_beneficiaries;
			const max = r.max_beneficiaries;
			return (
				beneficiariesForPreApprovalDraft >= min &&
				(max == null || beneficiariesForPreApprovalDraft <= max)
			);
		});
		if (matchedRule && (matchedRule.required_approvals ?? 0) > 0) {
			reasons.push(
				`Rango de beneficiarios (${beneficiariesForPreApprovalDraft}) coincide con regla ${matchedRule.min_beneficiaries}-${matchedRule.max_beneficiaries ?? "∞"} que exige ${matchedRule.required_approvals} aprobación(es).`,
			);
		}
		return reasons;
	}, [
		mustPreApproveCouponBeforeAssignment,
		rulesForUi,
		amountCentsPreview,
		beneficiariesForPreApprovalDraft,
	]);

	const selectedCoupon = useMemo(
		() => (assignableCoupons ?? []).find((c) => c.id === data.coupon_id),
		[assignableCoupons, data.coupon_id],
	);

	const selectedAuthorizerNames = useMemo(() => {
		const ids = new Set(data.authorizer_ids);
		return (authorizers ?? []).filter((a) => ids.has(a.id));
	}, [authorizers, data.authorizer_ids]);

	const amountOk = useMemo(() => {
		if (data.coupon_mode !== "new") return true;
		const v = parseFloat(String(data.amount_mxn).replace(",", ""));
		return !Number.isNaN(v) && v > 0;
	}, [data.coupon_mode, data.amount_mxn]);

	const existingCouponOk = useMemo(() => {
		if (data.coupon_mode !== "existing") return true;
		return filtered.length > 0;
	}, [data.coupon_mode, filtered.length]);

	const assignmentFieldsOk = useMemo(() => {
		if (data.assignment_mode === "individual") {
			return data.email.trim().length > 0 && userLookup.exists === true;
		}
		if (data.assignment_mode === "bulk") {
			const selected = bulkRows.filter((r) => r.include).length;
			return bulkRows.length > 0 && selected > 0;
		}
		return true;
	}, [data.assignment_mode, data.email, bulkRows, userLookup.exists]);

	const authorizersSelectionOk = useMemo(() => {
		if (data.assignment_mode === "none") {
			return true;
		}
		const required = resolveApprovalsPreview(
			amountCentsPreview,
			data.assignment_mode === "individual"
				? 1
				: data.assignment_mode === "none"
					? beneficiariesForPreApprovalDraft
					: 0,
			rulesForUi,
		);
		if (required === 0) return true;
		if (isSuperadmin && rulesForUi?.superadmin_bypass_approvals) return true;
		return data.authorizer_ids.length > 0;
	}, [
		data.assignment_mode,
		data.authorizer_ids.length,
		amountCentsPreview,
		rulesForUi,
		isSuperadmin,
		mustPreApproveCouponBeforeAssignment,
		beneficiariesForPreApprovalDraft,
	]);

	const canSubmit =
		amountOk &&
		existingCouponOk &&
		assignmentFieldsOk &&
		authorizersSelectionOk &&
		!(
			data.coupon_mode === "existing" &&
			data.assignment_mode !== "none" &&
			filtered.length === 0
		);

	const approvalRealtime = useMemo(() => {
		if (data.assignment_mode === "none") {
			return {
				variant: "neutral",
				title: "Sin asignación en esta operación",
				detail:
					"Solo se guardará el cupón maestro. No se generan aprobaciones de asignación.",
			};
		}

		const requiredByRules = approvalsPreview;
		if (requiredByRules === 0) {
			return {
				variant: "ok",
				title: "Se puede otorgar sin aprobaciones",
				detail:
					"Con la configuración actual no se requieren aprobaciones adicionales para esta asignación.",
			};
		}

		if (isSuperadmin && rulesForUi?.superadmin_bypass_approvals) {
			return {
				variant: "ok",
				title: "Superadmin con omisión de aprobaciones",
				detail: `Las reglas pedirían ${requiredByRules} aprobación(es), pero esta operación se puede ejecutar sin aprobación por la excepción de superadmin.`,
			};
		}

		if (data.authorizer_ids.length === 0) {
			return {
				variant: "warn",
				title: `Se requieren ${requiredByRules} aprobación(es)`,
				detail:
					"Selecciona uno o más autorizadores para poder enviar la solicitud de aprobación.",
			};
		}

		const effectiveApprovals = Math.min(requiredByRules, data.authorizer_ids.length);
		return {
			variant: "warn",
			title: `Se solicitarán ${effectiveApprovals} aprobación(es)`,
			detail: `Las reglas actuales requieren ${requiredByRules}. Con ${data.authorizer_ids.length} autorizador(es) seleccionado(s), la solicitud se enviará con ${effectiveApprovals} aprobación(es).`,
		};
	}, [
		data.assignment_mode,
		data.authorizer_ids.length,
		approvalsPreview,
		isSuperadmin,
		rulesForUi?.superadmin_bypass_approvals,
	]);

	const tabErrorFlags = useMemo(() => {
		const o = {};
		for (const t of TABS) {
			o[t.id] = errorsForTab(errors, t.id);
		}
		return o;
	}, [errors]);

	const tabBtnClass = (id) =>
		[
			"relative inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
			activeTab === id
				? "bg-famedic-lime/15 text-famedic-dark ring-1 ring-famedic-lime/60 dark:bg-famedic-lime/10 dark:text-famedic-lime dark:ring-famedic-lime/50"
				: "text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white",
		].join(" ");

	const pillClass = (active) =>
		[
			"inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
			active
				? "bg-famedic-lime/15 text-famedic-dark ring-1 ring-famedic-lime/60 dark:bg-famedic-lime/10 dark:text-famedic-lime dark:ring-famedic-lime/50"
				: "text-zinc-600 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-400 dark:ring-zinc-600 dark:hover:bg-zinc-800",
		].join(" ");

	const couponModeBtnClass = (active) =>
		[
			"inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
			active
				? "border-famedic-lime/70 bg-famedic-lime/10 text-famedic-dark ring-1 ring-famedic-lime/50 hover:bg-famedic-lime/20 dark:text-famedic-lime"
				: "text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-300 dark:ring-zinc-600 dark:hover:bg-zinc-800",
		].join(" ");

	const submit = (e) => {
		e.preventDefault();
		post(route("admin.coupons.assign.store"), {
			forceFormData: data.assignment_mode === "bulk",
		});
	};

	return (
		<AdminLayout title="Crear y asignar cupones">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-8">
					<div className="max-w-3xl">
						<Heading>Crear y asignar cupones</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Define el cupón y la asignación en pasos. Las reglas del sistema siguen
							visibles a la derecha en todo momento.
						</Text>
					</div>
					<Button href={route("admin.coupons.index")} outline>
						Volver al listado
					</Button>
				</div>

				<div className="space-y-6">
					<form
						onSubmit={submit}
						className="flex min-h-[min(72vh,44rem)] flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
					>
						<div className="border-b border-zinc-200 px-4 pt-4 dark:border-zinc-700 sm:px-6">
							<nav
								className="-mx-1 flex gap-1 overflow-x-auto pb-1"
								role="tablist"
								aria-label="Pasos del flujo"
							>
								{TABS.map((t) => (
									<button
										key={t.id}
										type="button"
										role="tab"
										id={`assign-tab-${t.id}`}
										aria-selected={activeTab === t.id}
										aria-controls={`assign-panel-${t.id}`}
										className={tabBtnClass(t.id)}
										onClick={() => setActiveTab(t.id)}
									>
										{t.label}
										{tabErrorFlags[t.id] && (
											<span
												className="size-2 shrink-0 rounded-full bg-red-500"
												title="Hay errores en esta sección"
												aria-hidden
											/>
										)}
									</button>
								))}
							</nav>
						</div>

						<div className="relative flex-1 overflow-hidden px-4 py-6 sm:px-6">
							<AnimatePresence mode="wait">
								<motion.div
									key={activeTab}
									role="tabpanel"
									id={`assign-panel-${activeTab}`}
									aria-labelledby={`assign-tab-${activeTab}`}
									initial={{ opacity: 0, y: 6 }}
									animate={{ opacity: 1, y: 0 }}
									exit={{ opacity: 0, y: -6 }}
									transition={{ duration: 0.18 }}
									className="space-y-6"
								>
									{activeTab === "coupon" && (
										<>
											<Text className="text-sm text-zinc-600 dark:text-zinc-400">
												Elige si usas un cupón maestro ya activo o creas uno nuevo.
											</Text>
											<div className="flex flex-wrap gap-2">
												<Button
													type="button"
													onClick={() => setData("coupon_mode", "existing")}
													outline
													className={couponModeBtnClass(
														data.coupon_mode === "existing",
													)}
												>
													Ya tengo un cupón activo
												</Button>
												<Button
													type="button"
													onClick={() => setData("coupon_mode", "new")}
													outline
													className={couponModeBtnClass(
														data.coupon_mode === "new",
													)}
												>
													Crear cupón maestro nuevo
												</Button>
											</div>

											{data.coupon_mode === "existing" ? (
												<>
													<Field>
														<Label>Buscar cupón</Label>
														<Input
															placeholder="ID, código o descripción…"
															value={search}
															onChange={(e) => setSearch(e.target.value)}
														/>
													</Field>
													<Field>
														<Label>Cupón maestro</Label>
														{filtered.length === 0 ? (
															<p className="text-sm text-amber-700 dark:text-amber-300">
																No hay cupones disponibles. Crea uno nuevo o autoriza un
																cupón pendiente.
															</p>
														) : (
															<Listbox
																value={data.coupon_id}
																onChange={(v) => setData("coupon_id", v)}
																placeholder="Selecciona…"
															>
																{filtered.map((c) => (
																	<ListboxOption key={c.id} value={c.id}>
																		<ListboxLabel>
																			#{c.id}
																			{c.code ? ` · ${c.code}` : ""} —{" "}
																			{(c.amount_cents / 100).toLocaleString("es-MX", {
																				style: "currency",
																				currency: "MXN",
																			})}
																		</ListboxLabel>
																		<ListboxDescription>
																			{c.child_coupons_count ?? 0}
																			{c.max_beneficiaries != null
																				? ` / ${c.max_beneficiaries}`
																				: ""}{" "}
																			beneficiarios
																		</ListboxDescription>
																	</ListboxOption>
																))}
															</Listbox>
														)}
														{errors.coupon_id && (
															<p className="mt-1 text-sm text-red-600 dark:text-red-400">
																{errors.coupon_id}
															</p>
														)}
													</Field>
												</>
											) : (
												<div className="space-y-4">
													<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
														<Field>
															<Label>Monto por beneficiario (MXN)</Label>
															<Input
																type="number"
																step="0.01"
																min="0.01"
																value={data.amount_mxn}
																onChange={(e) => setData("amount_mxn", e.target.value)}
															/>
															{errors.amount_cents && (
																<p className="mt-1 text-sm text-red-600 dark:text-red-400">
																	{errors.amount_cents}
																</p>
															)}
														</Field>
														<Field>
															<Label>Número de beneficiarios (opcional)</Label>
															<Input
																type="number"
																min="1"
																placeholder="Sin límite"
																value={data.max_beneficiaries}
																onChange={(e) =>
																	setData("max_beneficiaries", e.target.value)
																}
															/>
															{errors.max_beneficiaries && (
																<p className="mt-1 text-sm text-red-600 dark:text-red-400">
																	{errors.max_beneficiaries}
																</p>
															)}
														</Field>
														<Field>
															<Label>Código (opcional)</Label>
															<Input
																value={data.code}
																onChange={(e) => setData("code", e.target.value)}
															/>
														</Field>
													</div>
													<Field>
														<Label>Descripción del crédito a otorgar (opcional)</Label>
														<Textarea
															rows={2}
															value={data.description}
															onChange={(e) => setData("description", e.target.value)}
														/>
													</Field>
													{!requireAuth && (
														<SwitchField>
															<Label>Activo al crear</Label>
															<Switch
																checked={data.is_active}
																onChange={(v) => setData("is_active", v)}
															/>
														</SwitchField>
													)}
													{requireAuth && (
														<p className="text-sm text-amber-800 dark:text-amber-200">
															Con la política actual, el cupón nuevo quedará pendiente hasta
															que el autorizador ingrese el código por correo. Las
															asignaciones se podrán hacer cuando el cupón esté activo.
														</p>
													)}
													<div className="pt-2">
														<Button
															type="button"
															outline
															onClick={() => setActiveTab("assignment")}
														>
															Paso 2
														</Button>
													</div>
												</div>
											)}
										</>
									)}

									{activeTab === "assignment" && (
										<>
											<Text className="text-sm text-zinc-600 dark:text-zinc-400">
												Define cómo quieres aplicar el cupón: solo guardarlo, un beneficiario
												o un archivo.
											</Text>
											<div>
												<p className="mb-2 font-poppins text-base/6 font-medium text-zinc-950 sm:text-sm/6 dark:text-white">
													Tipo de asignación
												</p>
												<div className="flex flex-wrap gap-2">
													<button
														type="button"
														className={pillClass(data.assignment_mode === "none")}
														onClick={() => setData("assignment_mode", "none")}
													>
														Solo guardar cupón
													</button>
													<button
														type="button"
														className={pillClass(data.assignment_mode === "individual")}
														onClick={() => setData("assignment_mode", "individual")}
														disabled={mustPreApproveCouponBeforeAssignment}
													>
														Un correo
													</button>
													<button
														type="button"
														className={pillClass(data.assignment_mode === "bulk")}
														onClick={() => setData("assignment_mode", "bulk")}
														disabled={mustPreApproveCouponBeforeAssignment}
													>
														Archivo masivo
													</button>
												</div>
											</div>

											{mustPreApproveCouponBeforeAssignment && (
												<div className="rounded-lg border border-amber-200 bg-amber-50/80 p-3 dark:border-amber-900 dark:bg-amber-950/40">
													<p className="text-sm font-medium text-amber-900 dark:text-amber-100">
														Este cupón requiere aprobación previa antes de asignar beneficiarios.
													</p>
													<p className="mt-1 text-sm text-amber-800 dark:text-amber-200">
														Se requieren al menos {preApprovalRequiredForNewCoupon} aprobación(es).
														Por ahora solo puedes usar &quot;Solo guardar cupón&quot; para enviar la
														solicitud a autorizadores.
													</p>
													{preApprovalReasons.length > 0 && (
														<ul className="mt-2 list-inside list-disc space-y-1 text-sm text-amber-900 dark:text-amber-100">
															{preApprovalReasons.map((reason) => (
																<li key={reason}>{reason}</li>
															))}
														</ul>
													)}
												</div>
											)}

											{data.assignment_mode === "individual" && (
												<Field>
													<Label>Correo del usuario (registrado en Famedic)</Label>
													<Input
														type="email"
														value={data.email}
														onChange={(e) => setData("email", e.target.value)}
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
														{userLookup.message}
													</p>
													{errors.email && (
														<p className="mt-1 text-sm text-red-600 dark:text-red-400">
															{errors.email}
														</p>
													)}
												</Field>
											)}

											{data.assignment_mode === "bulk" && (
												<div className="space-y-4">
													<Field>
														<Label>Archivo (.xlsx, .xls, .csv)</Label>
														<Text className="mb-2 text-sm text-zinc-500 dark:text-zinc-400">
															Columna <strong>email</strong> o <strong>correo</strong>. Se
															asignará el mismo monto del cupón maestro a cada fila con
															cuenta registrada que incluyas abajo.
														</Text>
														<div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
															<Button
																href={route("admin.coupons.assign.bulk-template")}
																outline
																className="text-sm"
															>
																Descargar plantilla CSV de ejemplo
															</Button>
															<Text className="!text-xs !text-zinc-500 dark:!text-zinc-400">
																CSV con encabezado <strong>email</strong> y correos de muestra;
																puedes guardarlo como Excel (.xlsx) si prefieres.
															</Text>
														</div>
														<Input
															type="file"
															accept=".xlsx,.xls,.csv"
															onChange={(e) => {
																setData("file", e.target.files?.[0] ?? null);
																setBulkRows([]);
																setBulkPreviewError("");
															}}
														/>
														<div className="mt-3 flex flex-wrap gap-2">
															<Button
																type="button"
																outline
																disabled={!data.file || bulkPreviewLoading}
																onClick={() => runBulkPreview()}
															>
																{bulkPreviewLoading
																	? "Analizando…"
																	: "Analizar archivo y validar usuarios"}
															</Button>
														</div>
														{bulkPreviewError && (
															<p className="mt-2 text-sm text-red-600 dark:text-red-400">
																{bulkPreviewError}
															</p>
														)}
														{errors.file && (
															<p className="mt-1 text-sm text-red-600 dark:text-red-400">
																{errors.file}
															</p>
														)}
													</Field>

													{bulkRows.length > 0 && (
														<div className="rounded-lg border border-zinc-200 bg-zinc-50/90 p-4 dark:border-zinc-600 dark:bg-zinc-900/50">
															<div className="flex flex-wrap items-start justify-between gap-3">
																<div>
																	<Subheading className="text-base">
																		Vista previa de beneficiarios
																	</Subheading>
																	<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
																		Marca quién recibirá el cupón. Por defecto solo se
																		incluyen correos con usuario en la plataforma.
																	</Text>
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
																			setBulkRows((rows) =>
																				rows.filter((r) => r.exists),
																			)
																		}
																	>
																		Eliminar filas sin usuario
																	</Button>
																</div>
															</div>
															<p className="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
																Seleccionados para asignar:{" "}
																<strong>
																	{bulkRows.filter((r) => r.include).length}
																</strong>{" "}
																de {bulkRows.length} en archivo.
															</p>
															<div className="mt-3 max-h-[min(24rem,50vh)] overflow-auto rounded-md border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
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
																				<TableCell className="max-w-[14rem] break-all font-mono text-sm">
																					{row.email}
																				</TableCell>
																				<TableCell className="text-sm text-zinc-700 dark:text-zinc-300">
																					{row.user_name ?? "—"}
																				</TableCell>
																				<TableCell>
																					{row.exists ? (
																						<Badge color="emerald">
																							Registrado
																						</Badge>
																					) : (
																						<Badge color="red">
																							Sin cuenta
																						</Badge>
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

											{(data.assignment_mode !== "none" ||
												mustPreApproveCouponBeforeAssignment) && (
												<>
													{data.assignment_mode !== "none" && (
														<CheckboxField>
															<Checkbox
																checked={data.send_notification}
																onChange={(v) => {
																	setData("send_notification", v);
																	setData("send_notifications", v);
																}}
															/>
															<Label>
																Enviar notificaciones (correo y aviso en plataforma)
															</Label>
														</CheckboxField>
													)}

													<Field>
														<Label>Autorizadores (si las reglas exigen aprobación)</Label>
														<div className="mt-3 max-h-48 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
															{authorizers.length === 0 ? (
																<p className="text-sm text-zinc-500 dark:text-zinc-400">
																	No hay usuarios con rol autorizador. Configura roles en
																	administración.
																</p>
															) : (
																authorizers.map((authorizer) => {
																	const checked = data.authorizer_ids.includes(
																		authorizer.id,
																	);
																	return (
																		<CheckboxField key={authorizer.id}>
																			<Checkbox
																				checked={checked}
																				onChange={(v) => {
																					if (v) {
																						setData("authorizer_ids", [
																							...data.authorizer_ids,
																							authorizer.id,
																						]);
																					} else {
																						setData(
																							"authorizer_ids",
																							data.authorizer_ids.filter(
																								(id) => id !== authorizer.id,
																							),
																						);
																					}
																				}}
																			/>
																			<Label>
																				{authorizer.name} (
																				{authorizer.email || "sin correo"})
																			</Label>
																		</CheckboxField>
																	);
																})
															)}
														</div>
													</Field>
												</>
											)}

											<div
												className={[
													"rounded-lg border p-3",
													approvalRealtime.variant === "ok"
														? "border-emerald-200 bg-emerald-50/80 dark:border-emerald-900 dark:bg-emerald-950/40"
														: approvalRealtime.variant === "warn"
															? "border-amber-200 bg-amber-50/80 dark:border-amber-900 dark:bg-amber-950/40"
															: "border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/40",
												].join(" ")}
											>
												<p className="text-sm font-semibold text-zinc-900 dark:text-white">
													Validación en tiempo real
												</p>
												<p className="mt-1 text-sm text-zinc-800 dark:text-zinc-200">
													{approvalRealtime.title}
												</p>
												<p className="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
													{approvalRealtime.detail}
												</p>
											</div>
										</>
									)}

									{activeTab === "summary" && (
										<>
											<Text className="text-sm text-zinc-600 dark:text-zinc-400">
												Comprueba los datos antes de continuar.
											</Text>
											<dl className="space-y-3 text-sm text-zinc-800 dark:text-zinc-200">
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Tipo de cupón
													</dt>
													<dd>
														{data.coupon_mode === "new"
															? "Nuevo (maestro)"
															: "Existente"}
													</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Monto por beneficiario
													</dt>
													<dd>
														{data.coupon_mode === "new"
															? formatMxFromCents(amountCentsPreview)
															: formatMxFromCents(selectedCoupon?.amount_cents)}
													</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Máximo de beneficiarios
													</dt>
													<dd>
														{data.coupon_mode === "new"
															? String(data.max_beneficiaries || "").trim() || "Sin límite"
															: selectedCoupon?.max_beneficiaries ?? "Sin límite"}
													</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Código
													</dt>
													<dd>
														{data.coupon_mode === "new"
															? data.code?.trim() || "—"
															: selectedCoupon?.code || "—"}
													</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Tipo de asignación
													</dt>
													<dd>{ASSIGNMENT_LABELS[data.assignment_mode]}</dd>
												</div>
												{data.assignment_mode === "individual" && (
													<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
														<dt className="font-medium text-zinc-500 dark:text-zinc-400">
															Correo
														</dt>
														<dd className="break-all">{data.email || "—"}</dd>
													</div>
												)}
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Notificaciones
													</dt>
													<dd>
														{data.assignment_mode === "none"
															? "No aplica"
															: data.send_notification
																? "Sí"
																: "No"}
													</dd>
												</div>
												{data.assignment_mode !== "none" && (
													<div className="flex flex-col gap-1 sm:flex-row sm:justify-between">
														<dt className="shrink-0 font-medium text-zinc-500 dark:text-zinc-400">
															Autorizadores seleccionados
														</dt>
														<dd className="text-right">
															{selectedAuthorizerNames.length === 0
																? "Ninguno"
																: selectedAuthorizerNames
																		.map(
																			(a) =>
																				`${a.name} (${a.email || "sin correo"})`,
																		)
																		.join(" · ")}
														</dd>
													</div>
												)}
											</dl>

											<div className="rounded-lg border border-famedic-dark/25 bg-famedic-dark/10 p-4 dark:border-famedic-lime/20 dark:bg-famedic-darker/80">
												<p className="text-sm font-semibold text-famedic-dark dark:text-famedic-lime">
													Importante
												</p>
												<ul className="mt-2 list-inside list-disc space-y-1 text-sm text-zinc-800 dark:text-zinc-200">
													<li>
														Si el cupón nuevo queda pendiente de autorización por código, no
														podrás asignar beneficiarios hasta activarlo.
													</li>
													<li>
														En archivo masivo, la cantidad de filas puede aumentar las
														aprobaciones requeridas según las reglas por beneficiarios.
													</li>
													<li>
														Si las reglas exigen aprobación y no eres superadmin con omisión,
														debes elegir autorizadores en la pestaña Asignación.
													</li>
												</ul>
											</div>
										</>
									)}
								</motion.div>
							</AnimatePresence>
						</div>

						<div className="mt-auto border-t border-zinc-200 px-4 py-4 dark:border-zinc-700 sm:px-6">
							<Button
								type="submit"
								className="w-full sm:w-auto"
								disabled={processing || !canSubmit}
							>
								{data.assignment_mode === "none" &&
								mustPreApproveCouponBeforeAssignment
									? "Guardar y solicitar aprobación"
									: data.assignment_mode === "none"
									? "Guardar cupón"
									: "Ejecutar asignación"}
							</Button>
							{!canSubmit && !processing && (
								<p className="mt-2 text-sm text-amber-800 dark:text-amber-200">
									Completa los datos requeridos en las pestañas Cupón y Asignación (incluye
									autorizadores si las reglas lo indican).
								</p>
							)}
						</div>
					</form>

					<aside className="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-950 lg:min-w-0">
						<Subheading level={3}>Reglas y validaciones</Subheading>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Resumen de &quot;Reglas y seguridad&quot;. El sistema toma el máximo entre
							aprobaciones por monto y por número de beneficiarios.
						</Text>
						<ul className="space-y-2 text-sm text-zinc-800 dark:text-zinc-200">
							<li>
								<strong>Monto base referencia:</strong>{" "}
								{formatMxFromCents(rulesForUi?.base_amount_cents)}
							</li>
							<li>
								<strong>Monto máximo por asignación:</strong>{" "}
								{rulesForUi?.max_assignment_amount_cents != null
									? formatMxFromCents(rulesForUi.max_assignment_amount_cents)
									: "Sin límite"}
							</li>
							<li>
								<strong>Máx. asignaciones al día:</strong>{" "}
								{rulesForUi?.max_assignments_per_day ?? "Sin límite"}
							</li>
							<li>
								<strong>Umbral de aprobación (monto):</strong>{" "}
								{rulesForUi?.amount_threshold_cents != null
									? formatMxFromCents(rulesForUi.amount_threshold_cents)
									: "No definido"}
								{rulesForUi?.amount_threshold_cents != null && (
									<>
										{" "}
										→{" "}
										<strong>
											{rulesForUi.required_approvals_by_amount ?? 0} aprobación(es)
										</strong>
									</>
								)}
							</li>
							<li>
								<strong>Requiere autorización por código al crear:</strong>{" "}
								{requireAuth ? "Sí" : "No"}
							</li>
							<li>
								<strong>Superadmin omite aprobaciones multi-firma:</strong>{" "}
								{rulesForUi?.superadmin_bypass_approvals ? "Sí" : "No"}
							</li>
						</ul>
						{(rulesForUi?.beneficiary_rules?.length ?? 0) > 0 && (
							<div>
								<p className="text-sm font-medium text-zinc-900 dark:text-white">
									Aprobaciones por cantidad de beneficiarios
								</p>
								<ul className="mt-2 list-inside list-disc text-sm text-zinc-700 dark:text-zinc-300">
									{rulesForUi.beneficiary_rules.map((r, i) => (
										<li key={i}>
											De {r.min_beneficiaries} a {r.max_beneficiaries ?? "∞"} personas →{" "}
											<strong>{r.required_approvals}</strong> aprobación(es)
										</li>
									))}
								</ul>
							</div>
						)}
						<div className="rounded-lg border border-emerald-200 bg-emerald-50/80 p-3 dark:border-emerald-900 dark:bg-emerald-950/40">
							<p className="text-sm font-medium text-emerald-900 dark:text-emerald-100">
								Vista previa
							</p>
							<p className="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
								Aprobaciones estimadas: <strong>{approvalsPreview}</strong>
								{data.assignment_mode === "bulk" && (
									<>
										{" "}
										{bulkRows.length > 0
											? `(masivo: ${bulkRows.filter((r) => r.include).length} beneficiario(s) seleccionado(s))`
											: "(masivo: usa «Analizar archivo» para estimar por cantidad)"}
									</>
								)}
							</p>
							<p className="mt-2 text-sm text-emerald-800 dark:text-emerald-200">
								<strong>{approvalRealtime.title}.</strong> {approvalRealtime.detail}
							</p>
						</div>
					</aside>
				</div>
			</div>
		</AdminLayout>
	);
}
