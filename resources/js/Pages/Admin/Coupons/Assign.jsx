import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { useForm } from "@inertiajs/react";
import { AnimatePresence, motion } from "framer-motion";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Badge } from "@/Components/Catalyst/badge";
import { InformationCircleIcon } from "@heroicons/react/24/outline";

function csrfTokenFromMeta() {
	if (typeof document === "undefined") return "";
	return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? "";
}

const TABS = [
	{ id: "coupon", label: "Crédito" },
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

/**
 * Alineado con CouponService::resolveRequiredApprovals: rangos por monto (amount_rules),
 * reglas por beneficiarios y umbral legado si no hay rangos.
 */
function resolveApprovalsPreview(amountCents, beneficiaryCount, rules) {
	if (!rules) return 0;
	let byAmount = 0;
	const amountRules = rules.amount_rules ?? [];
	for (const r of amountRules) {
		const min = r.min_amount_cents ?? 0;
		const max = r.max_amount_cents;
		if (amountCents >= min && (max == null || amountCents <= max)) {
			byAmount = Math.max(byAmount, r.required_approvals ?? 0);
		}
	}
	if (
		amountRules.length === 0 &&
		rules.amount_threshold_cents != null &&
		amountCents >= rules.amount_threshold_cents
	) {
		byAmount = Math.max(byAmount, rules.required_approvals_by_amount ?? 0);
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
				match("bulk_emails") ||
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
	none: "Solo guardar",
	individual: "Asignar ahora",
	bulk: "Archivo masivo",
};

/** Oculto temporalmente en la UI; el flujo masivo permanece en el código por si se reactiva. */
const SHOW_BULK_ASSIGNMENT_UI = false;

function createMatrixRow() {
	const id =
		typeof crypto !== "undefined" && crypto.randomUUID
			? crypto.randomUUID()
			: `r_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;
	return {
		id,
		email: "",
		lookup: {
			status: "idle",
			exists: null,
			user: null,
			message: "",
		},
	};
}

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

	const allowedTabs = new Set(TABS.map((t) => t.id));
	const normalizedInitialTab = useMemo(() => {
		if (focus === "bulk") return "assignment";
		return allowedTabs.has(initialTab) ? initialTab : "coupon";
	}, [focus, initialTab]);

	const firstId = assignableCoupons?.[0]?.id ?? "";

	const [activeTab, setActiveTabState] = useState(normalizedInitialTab);
	const assignmentHelpRef = useRef(null);
	const [assignmentApprovalsHelpOpen, setAssignmentApprovalsHelpOpen] =
		useState(false);

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

	useEffect(() => {
		if (activeTab !== "assignment") {
			setAssignmentApprovalsHelpOpen(false);
		}
	}, [activeTab]);

	useEffect(() => {
		if (!assignmentApprovalsHelpOpen) return undefined;
		const onMouseDown = (e) => {
			const el = assignmentHelpRef.current;
			if (el && !el.contains(e.target)) {
				setAssignmentApprovalsHelpOpen(false);
			}
		};
		document.addEventListener("mousedown", onMouseDown);
		return () => document.removeEventListener("mousedown", onMouseDown);
	}, [assignmentApprovalsHelpOpen]);

	const defaultAmount =
		settings?.base_amount_cents != null
			? String(settings.base_amount_cents / 100)
			: "500";

	const [bulkRows, setBulkRows] = useState([]);
	const [bulkPreviewLoading, setBulkPreviewLoading] = useState(false);
	const [bulkPreviewError, setBulkPreviewError] = useState("");
	const [rulesSidebarExpanded, setRulesSidebarExpanded] = useState(false);
	const bulkRowsRef = useRef([]);
	const [matrixRows, setMatrixRows] = useState(() => [createMatrixRow()]);
	const matrixRowsRef = useRef(matrixRows);
	const matrixLookupTimersRef = useRef({});

	const { data, setData, post, processing, errors, transform } = useForm({
		coupon_mode: "new",
		assignment_mode:
			focus === "bulk" && SHOW_BULK_ASSIGNMENT_UI
				? "bulk"
				: focus === "new"
					? "none"
					: "individual",
		coupon_id: firstId,
		amount_mxn: defaultAmount,
		code: "",
		description: "",
		max_beneficiaries: "",
		is_active: true,
		file: null,
		send_notification: true,
		send_notifications: true,
		authorizer_ids: [],
	});

	bulkRowsRef.current = bulkRows;
	matrixRowsRef.current = matrixRows;

	useEffect(() => {
		if (!SHOW_BULK_ASSIGNMENT_UI && data.assignment_mode === "bulk") {
			setData("assignment_mode", "individual");
		}
	}, [data.assignment_mode, setData]);

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
			const emails = [];
			const seen = new Set();
			const counts = {};
			for (const r of matrixRowsRef.current) {
				const k = r.email.trim().toLowerCase();
				if (!k) continue;
				counts[k] = (counts[k] || 0) + 1;
			}
			for (const r of matrixRowsRef.current) {
				const k = r.email.trim().toLowerCase();
				if (!k || r.lookup.status !== "found") continue;
				if ((counts[k] ?? 0) > 1) continue;
				if (seen.has(k)) continue;
				seen.add(k);
				emails.push(r.email.trim());
			}
			if (emails.length > 0) {
				out.bulk_emails = emails;
			}
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

	const applyMatrixLookup = useCallback((rowId, nextLookup) => {
		setMatrixRows((rows) =>
			rows.map((r) => (r.id === rowId ? { ...r, lookup: nextLookup } : r)),
		);
	}, []);

	const validateMatrixRowEmail = useCallback(
		async (rowId, rawEmail) => {
			const email = rawEmail.trim();
			const snap = email.toLowerCase();
			if (!email) {
				applyMatrixLookup(rowId, {
					status: "idle",
					exists: null,
					user: null,
					message: "",
				});
				return;
			}
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				applyMatrixLookup(rowId, {
					status: "invalid",
					exists: false,
					user: null,
					message: "Formato de correo inválido.",
				});
				return;
			}
			applyMatrixLookup(rowId, {
				status: "checking",
				exists: null,
				user: null,
				message: "Validando usuario...",
			});
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
				const row = matrixRowsRef.current.find((r) => r.id === rowId);
				if (!row || row.email.trim().toLowerCase() !== snap) {
					return;
				}
				if (payload.exists) {
					applyMatrixLookup(rowId, {
						status: "found",
						exists: true,
						user: payload.user,
						message:
							payload.user?.name || payload.user?.email
								? `Registrado: ${payload.user?.name || payload.user?.email}`
								: "Usuario registrado.",
					});
					return;
				}
				applyMatrixLookup(rowId, {
					status: "missing",
					exists: false,
					user: null,
					message: "No existe un usuario con ese correo.",
				});
			} catch {
				const row = matrixRowsRef.current.find((r) => r.id === rowId);
				if (!row || row.email.trim().toLowerCase() !== snap) {
					return;
				}
				applyMatrixLookup(rowId, {
					status: "error",
					exists: null,
					user: null,
					message: "No se pudo validar el correo en este momento.",
				});
			}
		},
		[applyMatrixLookup],
	);

	const scheduleMatrixRowLookup = useCallback(
		(rowId, rawEmail) => {
			const prev = matrixLookupTimersRef.current[rowId];
			if (prev) {
				clearTimeout(prev);
			}
			matrixLookupTimersRef.current[rowId] = setTimeout(() => {
				void validateMatrixRowEmail(rowId, rawEmail);
				delete matrixLookupTimersRef.current[rowId];
			}, 400);
		},
		[validateMatrixRowEmail],
	);

	useEffect(() => {
		if (data.assignment_mode === "individual") {
			return;
		}
		for (const k of Object.keys(matrixLookupTimersRef.current)) {
			clearTimeout(matrixLookupTimersRef.current[k]);
			delete matrixLookupTimersRef.current[k];
		}
	}, [data.assignment_mode]);

	const amountCentsPreview = useMemo(() => {
		const v = parseFloat(String(data.amount_mxn).replace(",", ""));
		if (Number.isNaN(v)) return 0;
		return Math.round(v * 100);
	}, [data.amount_mxn]);

	const emailLowerCounts = useMemo(() => {
		const m = {};
		for (const r of matrixRows) {
			const k = r.email.trim().toLowerCase();
			if (!k) continue;
			m[k] = (m[k] || 0) + 1;
		}
		return m;
	}, [matrixRows]);

	const individualReadyEmails = useMemo(() => {
		if (data.assignment_mode !== "individual") return [];
		const out = [];
		const seen = new Set();
		for (const r of matrixRows) {
			const k = r.email.trim().toLowerCase();
			if (!k || r.lookup.status !== "found") continue;
			if ((emailLowerCounts[k] ?? 0) > 1) continue;
			if (seen.has(k)) continue;
			seen.add(k);
			out.push(k);
		}
		return out;
	}, [data.assignment_mode, matrixRows, emailLowerCounts]);

	const beneficiaryCountPreview = useMemo(() => {
		if (data.assignment_mode === "individual") return individualReadyEmails.length;
		if (data.assignment_mode === "none") return 0;
		if (data.assignment_mode === "bulk") {
			const n = bulkRows.filter((r) => r.include).length;
			return n > 0 ? n : 0;
		}
		return 0;
	}, [data.assignment_mode, bulkRows, individualReadyEmails.length]);

	const beneficiariesForPreApprovalDraft = useMemo(() => {
		const parsed = parseInt(String(data.max_beneficiaries ?? "").trim(), 10);
		return Number.isNaN(parsed) || parsed < 1 ? 1 : parsed;
	}, [data.max_beneficiaries]);

	const approvalsPreview = useMemo(
		() =>
			resolveApprovalsPreview(
				amountCentsPreview,
				beneficiaryCountPreview,
				rulesForUi,
			),
		[amountCentsPreview, beneficiaryCountPreview, rulesForUi],
	);

	const preApprovalRequiredForNewCoupon = useMemo(
		() =>
			resolveApprovalsPreview(
				amountCentsPreview,
				beneficiariesForPreApprovalDraft,
				rulesForUi,
			),
		[amountCentsPreview, beneficiariesForPreApprovalDraft, rulesForUi],
	);

	const mustPreApproveCouponBeforeAssignment =
		preApprovalRequiredForNewCoupon > 0 &&
		!(isSuperadmin && rulesForUi?.superadmin_bypass_approvals);

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

	const beneficiarySlotsLimit = useMemo(() => {
		const maxB = String(data.max_beneficiaries ?? "").trim();
		if (maxB === "") return null;
		const n = parseInt(maxB, 10);
		return Number.isNaN(n) || n < 1 ? null : n;
	}, [data.max_beneficiaries]);

	const matrixCapacity = beneficiarySlotsLimit ?? 5000;

	const amountOk = useMemo(() => {
		const v = parseFloat(String(data.amount_mxn).replace(",", ""));
		return !Number.isNaN(v) && v > 0;
	}, [data.amount_mxn]);

	const assignmentFieldsOk = useMemo(() => {
		if (data.assignment_mode === "individual") {
			const filled = matrixRows.filter((r) => r.email.trim() !== "");
			if (filled.length === 0) return false;
			for (const r of filled) {
				const k = r.email.trim().toLowerCase();
				if ((emailLowerCounts[k] ?? 0) > 1) return false;
				if (r.lookup.status !== "found") return false;
			}
			return individualReadyEmails.length >= 1;
		}
		if (data.assignment_mode === "bulk") {
			const selected = bulkRows.filter((r) => r.include).length;
			return bulkRows.length > 0 && selected > 0;
		}
		return true;
	}, [
		data.assignment_mode,
		matrixRows,
		emailLowerCounts,
		individualReadyEmails.length,
		bulkRows,
	]);

	const authorizersSelectionOk = useMemo(() => {
		if (data.assignment_mode === "none") {
			return true;
		}
		const beneficiariesForRule =
			data.assignment_mode === "individual"
				? beneficiaryCountPreview
				: data.assignment_mode === "bulk"
					? Math.max(beneficiaryCountPreview, 1)
					: beneficiariesForPreApprovalDraft;
		const required = resolveApprovalsPreview(
			amountCentsPreview,
			beneficiariesForRule,
			rulesForUi,
		);
		if (required === 0) return true;
		if (isSuperadmin && rulesForUi?.superadmin_bypass_approvals) return true;
		return (authorizers ?? []).length > 0;
	}, [
		data.assignment_mode,
		amountCentsPreview,
		rulesForUi,
		isSuperadmin,
		beneficiariesForPreApprovalDraft,
		beneficiaryCountPreview,
		authorizers,
	]);

	const couponStepComplete = useMemo(() => {
		if (!amountOk) return false;
		if (data.assignment_mode === "none") {
			return true;
		}
		const maxB = String(data.max_beneficiaries ?? "").trim();
		if (maxB === "") return false;
		const n = parseInt(maxB, 10);
		return !Number.isNaN(n) && n >= 1;
	}, [data.max_beneficiaries, data.assignment_mode, amountOk]);

	const assignmentStepComplete = useMemo(() => {
		if (data.assignment_mode === "none") return true;
		return assignmentFieldsOk;
	}, [data.assignment_mode, assignmentFieldsOk]);

	const summaryUnlocked = useMemo(
		() => couponStepComplete && assignmentStepComplete,
		[couponStepComplete, assignmentStepComplete],
	);

	const trySetTab = useCallback(
		(id) => {
			if (id === "assignment" && !couponStepComplete) return;
			if (
				id === "summary" &&
				!(couponStepComplete && assignmentStepComplete)
			) {
				return;
			}
			setActiveTab(id);
		},
		[couponStepComplete, assignmentStepComplete, setActiveTab],
	);

	const canSubmit =
		activeTab === "summary" &&
		summaryUnlocked &&
		amountOk &&
		assignmentFieldsOk &&
		authorizersSelectionOk;

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

		const authorizerPool = (authorizers ?? []).length;
		if (authorizerPool === 0) {
			return {
				variant: "warn",
				title: `Se requieren ${requiredByRules} aprobación(es)`,
				detail:
					"No hay usuarios con rol autorizador en el sistema. Configura autorizadores para poder enviar solicitudes.",
			};
		}

		return {
			variant: "warn",
			title: `Se requerirán hasta ${requiredByRules} aprobación(es)`,
			detail: `Se notificará a todos los autorizadores (${authorizerPool}). No necesitas elegirlos en una lista: al confirmar en el resumen, quedará la solicitud registrada y las asignaciones se activarán solas cuando se alcance el número de firmas exigido. Tú no tendrás que dar un paso adicional después de enviar.`,
		};
	}, [
		data.assignment_mode,
		approvalsPreview,
		isSuperadmin,
		rulesForUi?.superadmin_bypass_approvals,
		authorizers,
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

	const summaryBulkEmails = useMemo(
		() => bulkRows.filter((r) => r.include).map((r) => r.email),
		[bulkRows],
	);

	const summaryApprovalsRequired = useMemo(() => {
		if (data.assignment_mode === "none") return 0;
		return resolveApprovalsPreview(
			amountCentsPreview,
			beneficiaryCountPreview,
			rulesForUi,
		);
	}, [
		data.assignment_mode,
		amountCentsPreview,
		beneficiaryCountPreview,
		rulesForUi,
	]);

	const handleFormSubmit = (e) => {
		e.preventDefault();
		if (activeTab !== "summary") return;
		post(route("admin.coupons.assign.store"), {
			forceFormData: data.assignment_mode === "bulk",
		});
	};

	return (
		<AdminLayout title="Crear y asignar créditos">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-8">
					<div className="max-w-3xl">
						<Heading>Crear y asignar créditos</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Define el crédito y la asignación en pasos.
						</Text>
					</div>
					<Button href={route("admin.coupons.index")} outline>
						Ir a créditos
					</Button>
				</div>

				<div className="space-y-6">
					<form
						onSubmit={handleFormSubmit}
						className="flex min-h-[min(72vh,44rem)] flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
					>
						<div className="border-b border-zinc-200 px-4 pt-4 dark:border-zinc-700 sm:px-6">
							<nav
								className="-mx-1 flex gap-1 overflow-x-auto pb-1"
								role="tablist"
								aria-label="Pasos del flujo"
							>
								{TABS.map((t) => {
									const lockedToAssignment =
										t.id === "assignment" && !couponStepComplete;
									const lockedToSummary =
										t.id === "summary" && !summaryUnlocked;
									const tabLocked = lockedToAssignment || lockedToSummary;
									return (
									<button
										key={t.id}
										type="button"
										role="tab"
										disabled={tabLocked}
										id={`assign-tab-${t.id}`}
										aria-selected={activeTab === t.id}
										aria-controls={`assign-panel-${t.id}`}
										className={[
											tabBtnClass(t.id),
											tabLocked
												? "cursor-not-allowed opacity-45"
												: "",
										].join(" ")}
										onClick={() =>
											tabLocked ? undefined : trySetTab(t.id)
										}
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
									);
								})}
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
														<Label>Número de beneficiarios</Label>
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
												{requireAuth && (
													<p className="text-sm text-amber-800 dark:text-amber-200">
														Con la política actual, el cupón nuevo quedará pendiente hasta que
														el autorizador ingrese el código por correo. Las asignaciones se
														podrán hacer cuando el cupón esté activo.
													</p>
												)}
											</div>
										</>
									)}

									{activeTab === "assignment" && (
										<>
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
														Solo guardar
													</button>
													<button
														type="button"
														className={pillClass(data.assignment_mode === "individual")}
														onClick={() => setData("assignment_mode", "individual")}
													>
														{ASSIGNMENT_LABELS.individual}
													</button>
													{SHOW_BULK_ASSIGNMENT_UI && (
														<button
															type="button"
															className={pillClass(data.assignment_mode === "bulk")}
															onClick={() => setData("assignment_mode", "bulk")}
														>
															Archivo masivo
														</button>
													)}
												</div>
												{data.assignment_mode === "none" && (
													<p className="mt-3 rounded-lg border border-zinc-200 bg-zinc-50/90 px-3 py-2.5 text-sm leading-relaxed text-zinc-600 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-300">
														<strong className="font-medium text-zinc-800 dark:text-zinc-100">
															Guardar cupón y asignar más tarde:
														</strong>{" "}
														se crea el cupón maestro sin beneficiarios en este envío; podrás
														añadir correos cuando lo necesites.
													</p>
												)}
											</div>

											{data.assignment_mode === "individual" && (
												<div className="space-y-3">
													<div>
														<p className="font-poppins text-base/6 font-medium text-zinc-950 sm:text-sm/6 dark:text-white">
															Beneficiarios (uno por fila)
														</p>
														{/*
														<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
															Cada correo se valida contra usuarios registrados. No se
															pueden repetir correos en la lista. Máximo{" "}
															<strong>{matrixCapacity}</strong> filas según el cupón
															{String(data.max_beneficiaries ?? "").trim() === ""
																? " (sin tope definido; límite técnico 5000)"
																: ""}
															.
														</p>
														*/}
													</div>
													<div className="max-h-[min(28rem,55vh)] overflow-auto rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
														<Table dense>
															<TableHead>
																<TableRow>
																	<TableHeader>Correo</TableHeader>
																	<TableHeader>Usuario</TableHeader>
																	<TableHeader>Estado</TableHeader>
																	<TableHeader />
																</TableRow>
															</TableHead>
															<TableBody>
																{matrixRows.map((row) => {
																	const k = row.email.trim().toLowerCase();
																	const isDup =
																		k !== "" && (emailLowerCounts[k] ?? 0) > 1;
																	const lk = row.lookup;
																	return (
																		<TableRow key={row.id}>
																			<TableCell className="max-w-[16rem] align-top">
																				<Input
																					type="email"
																					autoComplete="off"
																					value={row.email}
																					placeholder="correo@ejemplo.com"
																					onChange={(e) => {
																						const v = e.target.value;
																						setMatrixRows((rows) =>
																							rows.map((r) =>
																								r.id === row.id
																									? {
																											...r,
																											email: v,
																											lookup: {
																												status:
																													"idle",
																												exists: null,
																												user: null,
																												message: "",
																											},
																										}
																									: r,
																							),
																						);
																						scheduleMatrixRowLookup(
																							row.id,
																							v,
																						);
																					}}
																					onBlur={(e) => {
																						const v = e.target.value;
																						const prevT =
																							matrixLookupTimersRef
																								.current[row.id];
																						if (prevT) {
																							clearTimeout(prevT);
																							delete matrixLookupTimersRef
																								.current[row.id];
																						}
																						void validateMatrixRowEmail(
																							row.id,
																							v,
																						);
																					}}
																				/>
																				{isDup && (
																					<p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
																						Correo duplicado en la tabla;
																						deja una sola fila.
																					</p>
																				)}
																			</TableCell>
																			<TableCell className="align-top text-sm text-zinc-700 dark:text-zinc-300">
																				{lk.user?.name ||
																					lk.user?.email ||
																					"—"}
																			</TableCell>
																			<TableCell className="align-top">
																				{lk.status === "found" && !isDup ? (
																					<Badge color="emerald">
																						Registrado
																					</Badge>
																				) : lk.status === "checking" ? (
																					<Badge color="zinc">
																						Validando…
																					</Badge>
																				) : lk.status === "missing" ||
																					  lk.status === "invalid" ||
																					  lk.status === "error" ||
																					  (lk.status === "found" && isDup) ? (
																					<Badge color="red">
																						{isDup ? "Duplicado" : "No válido"}
																					</Badge>
																				) : (
																					<Badge color="zinc">—</Badge>
																				)}
																			</TableCell>
																			<TableCell className="align-top text-right">
																				<Button
																					type="button"
																					plain
																					className="text-red-600 dark:text-red-400"
																					onClick={() => {
																						const prevT =
																							matrixLookupTimersRef
																								.current[row.id];
																						if (prevT) {
																							clearTimeout(prevT);
																							delete matrixLookupTimersRef
																								.current[row.id];
																						}
																						setMatrixRows((rows) => {
																							if (rows.length <= 1) {
																								return [createMatrixRow()];
																							}
																							return rows.filter(
																								(r) => r.id !== row.id,
																							);
																						});
																					}}
																				>
																					Quitar
																				</Button>
																			</TableCell>
																		</TableRow>
																	);
																})}
																<TableRow className="border-t border-zinc-200 bg-zinc-50/60 dark:border-zinc-700 dark:bg-zinc-900/40">
																	<TableCell className="align-top py-3" colSpan={1}>
																		<Button
																			type="button"
																			outline
																			disabled={matrixRows.length >= matrixCapacity}
																			onClick={() => {
																				if (matrixRows.length >= matrixCapacity) return;
																				setMatrixRows((rows) => [
																					...rows,
																					createMatrixRow(),
																				]);
																			}}
																		>
																			Agregar fila
																		</Button>
																	</TableCell>
																	<TableCell colSpan={3} className="py-3" />
																</TableRow>
															</TableBody>
														</Table>
													</div>
													<p className="text-sm text-zinc-600 dark:text-zinc-400">
														Listos para enviar:{" "}
														<strong>{individualReadyEmails.length}</strong> correo(s)
														único(s) validado(s).
													</p>
													{errors.bulk_emails && (
														<p className="text-sm text-red-600 dark:text-red-400">
															{Array.isArray(errors.bulk_emails)
																? errors.bulk_emails[0]
																: errors.bulk_emails}
														</p>
													)}
												</div>
											)}

											{SHOW_BULK_ASSIGNMENT_UI && data.assignment_mode === "bulk" && (
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
												</>
											)}

											<div ref={assignmentHelpRef} className="relative flex justify-end">
												<button
													type="button"
													className="inline-flex shrink-0 items-center justify-center rounded-full p-1.5 text-zinc-500 transition hover:bg-zinc-200/80 hover:text-zinc-800 focus-visible:outline focus-visible:ring-2 focus-visible:ring-famedic-lime dark:text-zinc-400 dark:hover:bg-zinc-700/80 dark:hover:text-zinc-100"
													aria-expanded={assignmentApprovalsHelpOpen}
													aria-controls="assignment-approvals-help-panel"
													aria-label="Información sobre aprobaciones y validación"
													onClick={() =>
														setAssignmentApprovalsHelpOpen((o) => !o)
													}
												>
													<InformationCircleIcon className="size-5" aria-hidden />
												</button>
												{assignmentApprovalsHelpOpen && (
													<div
														id="assignment-approvals-help-panel"
														role="region"
														aria-label="Ayuda de aprobaciones y validación"
														className="absolute right-0 top-full z-30 mt-2 w-[min(100vw-2.5rem,24rem)] max-h-[min(70vh,28rem)] overflow-y-auto rounded-lg border border-zinc-200 bg-white p-4 text-sm shadow-lg ring-1 ring-black/5 dark:border-zinc-600 dark:bg-zinc-900 dark:ring-white/10"
													>
														{(data.assignment_mode !== "none" ||
															mustPreApproveCouponBeforeAssignment) && (
															<div className="border-b border-zinc-200 pb-4 dark:border-zinc-700">
																<p className="font-medium text-zinc-900 dark:text-white">
																	Aprobaciones
																</p>
																<p className="mt-1 text-zinc-700 dark:text-zinc-300">
																	Si las reglas exigen firmas, el sistema notifica a{" "}
																	<strong>todos</strong> los usuarios con rol
																	autorizador. No hace falta marcar una lista
																	manualmente en esta pantalla.
																</p>
																{authorizers.length === 0 && (
																	<p className="mt-2 text-amber-800 dark:text-amber-200">
																		Aún no hay autorizadores configurados: no se
																		podrán completar solicitudes que requieran
																		aprobación.
																	</p>
																)}
															</div>
														)}
														<div
															className={
																data.assignment_mode !== "none" ||
																mustPreApproveCouponBeforeAssignment
																	? "pt-4"
																	: ""
															}
														>
															<p className="font-semibold text-zinc-900 dark:text-white">
																Validación en tiempo real
															</p>
															<div
																className={[
																	"mt-2 rounded-md border p-3",
																	approvalRealtime.variant === "ok"
																		? "border-emerald-200 bg-emerald-50/80 dark:border-emerald-900 dark:bg-emerald-950/40"
																		: approvalRealtime.variant === "warn"
																			? "border-amber-200 bg-amber-50/80 dark:border-amber-900 dark:bg-amber-950/40"
																			: "border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/40",
																].join(" ")}
															>
																<p className="text-sm font-medium text-zinc-900 dark:text-zinc-100">
																	{approvalRealtime.title}
																</p>
																<p className="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
																	{approvalRealtime.detail}
																</p>
															</div>
														</div>
													</div>
												)}
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
													<dd>Nuevo (maestro)</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Monto por beneficiario
													</dt>
													<dd>{formatMxFromCents(amountCentsPreview)}</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Máximo de beneficiarios
													</dt>
													<dd>
														{String(data.max_beneficiaries || "").trim() || "Sin límite"}
													</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Código
													</dt>
													<dd>{data.code?.trim() || "—"}</dd>
												</div>
												<div className="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
													<dt className="font-medium text-zinc-500 dark:text-zinc-400">
														Tipo de asignación
													</dt>
													<dd>{ASSIGNMENT_LABELS[data.assignment_mode]}</dd>
												</div>
												{data.assignment_mode === "individual" &&
													individualReadyEmails.length > 0 && (
														<div className="flex flex-col gap-1 sm:flex-row sm:justify-between sm:items-start">
															<dt className="shrink-0 font-medium text-zinc-500 dark:text-zinc-400">
																Beneficiarios ({individualReadyEmails.length})
															</dt>
															<dd className="max-w-full text-right">
																<ul className="ml-auto max-h-40 max-w-md list-inside list-disc overflow-y-auto break-all text-left font-mono text-xs text-zinc-800 dark:text-zinc-200">
																	{individualReadyEmails.slice(0, 40).map((em) => (
																		<li key={em}>{em}</li>
																	))}
																</ul>
																{individualReadyEmails.length > 40 && (
																	<p className="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
																		Y {individualReadyEmails.length - 40} correo(s)
																		más.
																	</p>
																)}
															</dd>
														</div>
													)}
												{data.assignment_mode === "bulk" &&
													summaryBulkEmails.length > 0 && (
														<div className="flex flex-col gap-1 sm:flex-row sm:justify-between sm:items-start">
															<dt className="shrink-0 font-medium text-zinc-500 dark:text-zinc-400">
																Beneficiarios ({summaryBulkEmails.length})
															</dt>
															<dd className="max-w-full text-right">
																<ul className="ml-auto max-h-40 max-w-md list-inside list-disc overflow-y-auto break-all text-left font-mono text-xs text-zinc-800 dark:text-zinc-200">
																	{summaryBulkEmails.slice(0, 40).map((em) => (
																		<li key={em}>{em}</li>
																	))}
																</ul>
																{summaryBulkEmails.length > 40 && (
																	<p className="mt-1 text-xs text-zinc-600 dark:text-zinc-400">
																		Y {summaryBulkEmails.length - 40} correo(s) más.
																	</p>
																)}
															</dd>
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
													<div className="flex flex-col gap-1 rounded-lg border border-zinc-200 bg-zinc-50/90 p-3 dark:border-zinc-600 dark:bg-zinc-900/50">
														<dt className="font-semibold text-zinc-900 dark:text-white">
															Aprobaciones según reglas
														</dt>
														<dd className="text-sm text-zinc-800 dark:text-zinc-200">
															{summaryApprovalsRequired === 0 ? (
																<>
																	Con el monto y la cantidad de beneficiarios de esta
																	operación,{" "}
																	<strong>
																		no se requieren aprobaciones adicionales
																	</strong>
																	: los créditos pueden confirmarse al enviar.
																</>
															) : isSuperadmin &&
																rulesForUi?.superadmin_bypass_approvals ? (
																<>
																	Las reglas pedirían{" "}
																	<strong>{summaryApprovalsRequired}</strong>{" "}
																	aprobación(es), pero con tu rol de superadmin y la
																	omisión activa la operación puede ejecutarse sin
																	esperar firmas.
																</>
															) : (
																<>
																	Esta operación requiere hasta{" "}
																	<strong>{summaryApprovalsRequired}</strong>{" "}
																	aprobación(es). Al confirmar, quedará registrada la
																	solicitud; los beneficiarios{" "}
																	<strong>no estarán activos</strong> hasta que los
																	autorizadores completen las firmas necesarias.{" "}
																	<strong>
																		No tendrás que hacer ningún paso adicional
																	</strong>
																	después de enviar: el sistema activará las
																	asignaciones cuando corresponda.
																</>
															)}
														</dd>
													</div>
												)}
											</dl>

											{/*
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
														En archivo masivo, la cantidad de beneficiarios incluidos puede
														subir el número de aprobaciones requeridas según las reglas
														configuradas.
													</li>
													<li>
														Cuando haya aprobaciones pendientes, se notifica al conjunto de
														autorizadores; no hace falta elegirlos uno a uno en esta pantalla.
													</li>
												</ul>
											</div>
											*/}
										</>
									)}
								</motion.div>
							</AnimatePresence>
						</div>

						<div className="mt-auto flex flex-col gap-3 border-t border-zinc-200 px-4 py-4 dark:border-zinc-700 sm:flex-row sm:flex-wrap sm:items-center sm:px-6">
							{activeTab === "coupon" && (
								<div className="flex w-full justify-end">
									<Button
										type="button"
										className="w-full sm:w-auto"
										disabled={!couponStepComplete}
										onClick={() => trySetTab("assignment")}
									>
										Siguiente: asignación
									</Button>
								</div>
							)}
							{activeTab === "assignment" && (
								<div className="flex w-full flex-row flex-wrap items-center justify-between gap-3">
									<Button
										type="button"
										outline
										className="shrink-0"
										onClick={() => setActiveTab("coupon")}
									>
										Anterior: cupón
									</Button>
									<Button
										type="button"
										className="shrink-0"
										disabled={!summaryUnlocked}
										onClick={() => trySetTab("summary")}
									>
										Siguiente: resumen
									</Button>
								</div>
							)}
							{activeTab === "summary" && (
								<>
									<Button
										type="button"
										outline
										className="w-full sm:w-auto"
										onClick={() => setActiveTab("assignment")}
									>
										Anterior: asignación
									</Button>
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
												: mustPreApproveCouponBeforeAssignment
													? "Confirmar: solicitud con asignaciones"
													: "Confirmar y enviar"}
									</Button>
								</>
							)}
							{activeTab === "summary" && !canSubmit && !processing && (
								<p className="w-full text-sm text-amber-800 dark:text-amber-200">
									Revisa el resumen: faltan datos o no se cumplen las reglas (por ejemplo,
									beneficiarios no registrados o sin autorizadores en el sistema si hiciera
									falta aprobación).
								</p>
							)}
						</div>
					</form>

					<aside className="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-950 lg:min-w-0">
						<div className="flex flex-wrap items-start justify-between gap-2">
							<Subheading level={3} className="mb-0">
								Reglas y validaciones
							</Subheading>
							<Button
								type="button"
								plain
								className="shrink-0 text-sm font-semibold text-famedic-dark underline decoration-famedic-lime/60 underline-offset-2 hover:text-famedic-dark dark:text-famedic-lime dark:hover:text-famedic-lime"
								onClick={() => setRulesSidebarExpanded((v) => !v)}
							>
								{rulesSidebarExpanded ? "Mostrar menos" : "Mostrar más"}
							</Button>
						</div>
						{!rulesSidebarExpanded && (
							<div className="rounded-lg border border-zinc-200 bg-white/80 p-3 text-sm text-zinc-700 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-300">
								<p>
									Aprobaciones estimadas para esta operación:{" "}
									<strong>{approvalsPreview}</strong>
									{data.assignment_mode === "bulk" && bulkRows.length > 0 && (
										<>
											{" "}
											(
											{bulkRows.filter((r) => r.include).length} beneficiario(s) en
											masivo)
										</>
									)}
								</p>
								<p className="mt-1 line-clamp-2 text-zinc-600 dark:text-zinc-400">
									{approvalRealtime.title}
								</p>
							</div>
						)}
						{rulesSidebarExpanded && (
							<>
								<Text className="text-sm text-zinc-600 dark:text-zinc-400">
									Resumen de &quot;Reglas y seguridad&quot;. El sistema toma el máximo entre
									aprobaciones por monto y por número de beneficiarios.
								</Text>
								<ul className="space-y-2 text-sm text-zinc-800 dark:text-zinc-200">
									{/*
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
										<strong>Umbral de aprobación (monto, legado):</strong>{" "}
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
									*/}
									{(rulesForUi?.amount_rules?.length ?? 0) > 0 && (
										<li className="list-none">
											<p className="font-medium text-zinc-900 dark:text-white">
												Aprobaciones por rango de monto
											</p>
											<ul className="mt-1 list-inside list-disc text-zinc-700 dark:text-zinc-300">
												{rulesForUi.amount_rules.map((r, i) => (
													<li key={i}>
														{formatMxFromCents(r.min_amount_cents ?? 0)} —{" "}
														{r.max_amount_cents != null
															? formatMxFromCents(r.max_amount_cents)
															: "∞"}{" "}
														→ <strong>{r.required_approvals ?? 0}</strong> aprobación(es)
													</li>
												))}
											</ul>
										</li>
									)}
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
							</>
						)}
					</aside>
				</div>
			</div>
		</AdminLayout>
	);
}
