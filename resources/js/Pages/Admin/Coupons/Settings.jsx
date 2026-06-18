import { Fragment, useCallback, useEffect, useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import Modal from "@/Components/Catalyst/modal";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { router, useForm, usePage } from "@inertiajs/react";
import { AnimatePresence, motion } from "framer-motion";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import CouponEmptyState from "@/Components/Admin/Coupon/CouponEmptyState";
import { PlusIcon, TrashIcon, PencilSquareIcon } from "@heroicons/react/16/solid";
import { TagIcon } from "@heroicons/react/24/outline";

// Temporal: pestaña "Límites de créditos" oculta; los valores siguen yendo en el PUT con los actuales.
const TABS = [
	{ id: "approval", label: "Reglas de aprobación" },
	{ id: "concepts", label: "Conceptos" },
	{ id: "history", label: "Historial de cambios" },
	{ id: "authorizers", label: "Autorizadores" },
];

function centsToMxnString(cents) {
	if (cents === null || cents === undefined) return "";
	const n = Number(cents);
	if (Number.isNaN(n)) return "";
	return String(n / 100);
}

/** @param {Record<string, string|string[]>} errs */
function errorsForTab(errs, tabId) {
	if (!errs || typeof errs !== "object") return false;
	const keys = Object.keys(errs);
	const match = (prefix) =>
		keys.some((k) => k === prefix || k.startsWith(`${prefix}.`));

	switch (tabId) {
		case "approval":
			return (
				match("amount_rules") ||
				match("beneficiary_rules") ||
				match("superadmin_bypass_approvals") ||
				match("mass_campaign_threshold") ||
				match("base_amount_mxn") ||
				match("max_assignment_amount_mxn") ||
				match("max_assignments_per_day") ||
				match("authorization_email") ||
				match("require_authorization")
			);
		case "authorizers":
			return false;
		case "history":
			return false;
		case "concepts":
			return false;
		default:
			return false;
	}
}

function formatShortDateTime(iso) {
	if (!iso) return "—";
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "short",
		timeStyle: "short",
	});
}

function settingsRequestStatusLabel(status) {
	switch (status) {
		case "pending":
			return "Pendiente";
		case "executed":
			return "Aplicada";
		case "rejected":
			return "Rechazada";
		default:
			return status ?? "—";
	}
}

export default function Settings() {
	const {
		settings,
		authorizers = [],
		initialTab = "approval",
		amountRules = [],
		beneficiaryRules = [],
		settingsApprovalHistory = [],
		concepts = [],
	} = usePage().props;

	const allowed = new Set(TABS.map((t) => t.id));
	const rawTab = initialTab === "limits" ? "approval" : initialTab;
	const normalizedInitial = allowed.has(rawTab) ? rawTab : "approval";

	const [activeTab, setActiveTabState] = useState(normalizedInitial);

	const initialAmountRules = useMemo(
		() =>
			(Array.isArray(amountRules) ? amountRules : []).map((r) => ({
				min_amount_mxn: centsToMxnString(r.min_amount_cents) || "0",
				max_amount_mxn:
					r.max_amount_cents != null
						? centsToMxnString(r.max_amount_cents)
						: "",
				required_approvals: r.required_approvals ?? 0,
			})),
		[amountRules],
	);

	const initialBeneficiaryRules = useMemo(
		() =>
			(Array.isArray(beneficiaryRules) ? beneficiaryRules : []).map((r) => ({
				min: r.min_beneficiaries ?? 1,
				max:
					r.max_beneficiaries != null && r.max_beneficiaries !== ""
						? String(r.max_beneficiaries)
						: "",
				required_approvals: r.required_approvals ?? 0,
			})),
		[beneficiaryRules],
	);

	const { data, setData, put, processing, errors } = useForm({
		base_amount_mxn:
			settings.base_amount_cents != null
				? String(settings.base_amount_cents / 100)
				: "500",
		max_assignment_amount_mxn:
			settings.max_assignment_amount_cents != null
				? String(settings.max_assignment_amount_cents / 100)
				: "",
		max_assignments_per_day: settings.max_assignments_per_day ?? "",
		authorization_email: settings.authorization_email ?? "",
		require_authorization: !!settings.require_authorization,
		mass_campaign_threshold:
			settings.mass_campaign_threshold != null
				? String(settings.mass_campaign_threshold)
				: "",
		superadmin_bypass_approvals: !!settings.superadmin_bypass_approvals,
		amount_rules: initialAmountRules,
		beneficiary_rules: initialBeneficiaryRules,
	});

	const setActiveTab = useCallback((id) => {
		setActiveTabState(id);
		if (typeof window === "undefined") return;
		const url = new URL(window.location.href);
		url.searchParams.set("tab", id);
		window.history.replaceState({}, "", url.toString());
	}, []);

	useEffect(() => {
		setActiveTabState(normalizedInitial);
	}, [normalizedInitial]);

	useEffect(() => {
		if (typeof window === "undefined") return;
		const url = new URL(window.location.href);
		if (url.searchParams.get("tab") === "limits") {
			url.searchParams.set("tab", "approval");
			window.history.replaceState({}, "", url.toString());
		}
	}, []);

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

	const tabErrorFlags = useMemo(() => {
		const o = {};
		for (const t of TABS) {
			o[t.id] = errorsForTab(errors, t.id);
		}
		return o;
	}, [errors]);

	const submit = (e) => {
		e.preventDefault();
		put(route("admin.coupons.settings.update"));
	};

	const [conceptModalOpen, setConceptModalOpen] = useState(false);
	const [conceptDeleteTarget, setConceptDeleteTarget] = useState(null);
	const {
		data: conceptForm,
		setData: setConceptForm,
		post: postConcept,
		put: putConcept,
		processing: conceptProcessing,
		errors: conceptErrors,
		reset: resetConceptForm,
		clearErrors: clearConceptErrors,
	} = useForm({
		id: null,
		title: "",
		description: "",
	});

	const openConceptModal = (concept = null) => {
		clearConceptErrors();
		if (concept) {
			setConceptForm({
				id: concept.id,
				title: concept.title ?? "",
				description: concept.description ?? "",
			});
		} else {
			setConceptForm({ id: null, title: "", description: "" });
		}
		setConceptModalOpen(true);
	};

	const closeConceptModal = () => {
		setConceptModalOpen(false);
		resetConceptForm();
		clearConceptErrors();
	};

	const submitConcept = (e) => {
		e.preventDefault();
		const onSuccess = () => closeConceptModal();
		if (conceptForm.id) {
			putConcept(
				route("admin.coupons.concepts.update", {
					couponConcept: conceptForm.id,
				}),
				{ preserveScroll: true, onSuccess },
			);
			return;
		}
		postConcept(route("admin.coupons.concepts.store"), {
			preserveScroll: true,
			onSuccess,
		});
	};

	const {
		delete: destroyConcept,
		processing: deletingConcept,
	} = useForm({});

	const confirmDeleteConcept = () => {
		if (!conceptDeleteTarget) return;
		destroyConcept(
			route("admin.coupons.concepts.destroy", {
				couponConcept: conceptDeleteTarget.id,
			}),
			{
				preserveScroll: true,
				onSuccess: () => setConceptDeleteTarget(null),
			},
		);
	};

	const decideApproval = (requestId, approve) => {
		const routeName = approve
			? "admin.coupons.approval-requests.approve"
			: "admin.coupons.approval-requests.reject";

		router.post(
			route(routeName, { approvalRequest: requestId }),
			{},
			{ preserveScroll: true },
		);
	};

	const addAmountRule = () => {
		setData("amount_rules", [
			...(data.amount_rules ?? []),
			{ min_amount_mxn: "0", max_amount_mxn: "", required_approvals: 0 },
		]);
	};

	const removeAmountRule = (idx) => {
		setData(
			"amount_rules",
			(data.amount_rules ?? []).filter((_, i) => i !== idx),
		);
	};

	const updateAmountRule = (idx, key, value) => {
		const next = [...(data.amount_rules ?? [])];
		next[idx] = { ...next[idx], [key]: value };
		setData("amount_rules", next);
	};

	const addBeneficiaryRule = () => {
		setData("beneficiary_rules", [
			...(data.beneficiary_rules ?? []),
			{ min: 1, max: "", required_approvals: 0 },
		]);
	};

	const removeBeneficiaryRule = (idx) => {
		setData(
			"beneficiary_rules",
			(data.beneficiary_rules ?? []).filter((_, i) => i !== idx),
		);
	};

	const updateBeneficiaryRule = (idx, key, value) => {
		const next = [...(data.beneficiary_rules ?? [])];
		next[idx] = { ...next[idx], [key]: value };
		setData("beneficiary_rules", next);
	};

	const tabBtnClass = (id) =>
		[
			"relative inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
			activeTab === id
				? "bg-famedic-lime/15 text-famedic-dark ring-1 ring-famedic-lime/60 dark:bg-famedic-lime/10 dark:text-famedic-lime dark:ring-famedic-lime/50"
				: "text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white",
		].join(" ");
	const highlightedCardClass =
		"rounded-lg border border-famedic-dark/20 bg-famedic-dark/5 p-6 shadow-sm dark:border-famedic-lime/20 dark:bg-famedic-darker/40";

	return (
		<AdminLayout title="Reglas de cupones">
			<div className="flex flex-wrap items-end justify-between gap-8">
				<div>
					<Heading>Reglas y seguridad - créditos a favor</Heading>
					<Text className="mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">
						Configura límites, reglas de aprobación por rangos y autorización de
						créditos a favor. Los cambios pueden requerir validación según tu rol.
					</Text>
				</div>
				<Button href={route("admin.coupons.index")} outline>
					Ir a créditos
				</Button>
			</div>

			<div className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,24rem)]">
				<form
					onSubmit={submit}
					className="flex min-h-[min(70vh,42rem)] flex-col rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<div className="border-b border-zinc-200 px-4 pt-4 dark:border-zinc-700 sm:px-6">
						<nav
							className="flex flex-wrap gap-1"
							role="tablist"
							aria-label="Secciones de configuración"
						>
							{TABS.map((t) => (
								<button
									key={t.id}
									type="button"
									role="tab"
									id={`tab-${t.id}`}
									aria-selected={activeTab === t.id}
									aria-controls={`panel-${t.id}`}
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
								id={`panel-${activeTab}`}
								aria-labelledby={`tab-${activeTab}`}
								initial={{ opacity: 0, y: 6 }}
								animate={{ opacity: 1, y: 0 }}
								exit={{ opacity: 0, y: -6 }}
								transition={{ duration: 0.18 }}
								className="space-y-6"
							>
								{activeTab === "approval" && (
									<>
										<div className={highlightedCardClass}>
											<Text className="text-sm text-zinc-700 dark:text-zinc-300">
												Define rangos por monto (MXN) y por número de beneficiarios.
												El motor usa el máximo entre ambas reglas. Si no eres
												superadmin, al guardar se notifica a todos los autorizadores y
												se requieren al menos 2 aprobaciones para aplicar cambios.
											</Text>
										</div>

										<div className={highlightedCardClass}>
											<div className="mb-4 flex flex-wrap items-center justify-between gap-2">
												<Subheading level={3}>Rangos por monto (MXN)</Subheading>
												<Button type="button" outline onClick={addAmountRule}>
													Agregar rango
													<PlusIcon />
												</Button>
											</div>
											{(data.amount_rules ?? []).length === 0 ? (
												<p className="text-sm text-zinc-600 dark:text-zinc-400">
													No hay rangos. Agrega al menos uno (p. ej. 0–199 con 0
													autorizadores).
												</p>
											) : (
												<div className="space-y-4">
													{(data.amount_rules ?? []).map((r, idx) => (
														<div
															key={idx}
															className="grid gap-3 border-b border-zinc-200 pb-4 last:border-0 last:pb-0 dark:border-zinc-700 sm:grid-cols-12 sm:items-end"
														>
															<Field className="sm:col-span-3">
																<Label>Inicio</Label>
																<Input
																	type="number"
																	step="0.01"
																	min="0"
																	value={r.min_amount_mxn}
																	onChange={(e) =>
																		updateAmountRule(
																			idx,
																			"min_amount_mxn",
																			e.target.value,
																		)
																	}
																/>
															</Field>
															<Field className="sm:col-span-3">
																<Label>Hasta</Label>
																<Input
																	type="number"
																	step="0.01"
																	min="0"
																	placeholder="Sin límite"
																	value={r.max_amount_mxn}
																	onChange={(e) =>
																		updateAmountRule(
																			idx,
																			"max_amount_mxn",
																			e.target.value,
																		)
																	}
																/>
															</Field>
															<Field className="sm:col-span-3">
																<Label># autorizadores</Label>
																<Input
																	type="number"
																	min="0"
																	value={r.required_approvals}
																	onChange={(e) =>
																		updateAmountRule(
																			idx,
																			"required_approvals",
																			e.target.value,
																		)
																	}
																/>
															</Field>
															<div className="flex justify-end sm:col-span-3">
																<Button
																	type="button"
																	outline
																	onClick={() => removeAmountRule(idx)}
																>
																	Quitar
																	<TrashIcon />
																</Button>
															</div>
															{errors[`amount_rules.${idx}.min_amount_mxn`] && (
																<p className="text-sm text-red-600 sm:col-span-12 dark:text-red-400">
																	{errors[`amount_rules.${idx}.min_amount_mxn`]}
																</p>
															)}
															{errors[`amount_rules.${idx}.max_amount_mxn`] && (
																<p className="text-sm text-red-600 sm:col-span-12 dark:text-red-400">
																	{errors[`amount_rules.${idx}.max_amount_mxn`]}
																</p>
															)}
															{errors[`amount_rules.${idx}.required_approvals`] && (
																<p className="text-sm text-red-600 sm:col-span-12 dark:text-red-400">
																	{
																		errors[
																			`amount_rules.${idx}.required_approvals`
																		]
																	}
																</p>
															)}
														</div>
													))}
												</div>
											)}
										</div>

										<div className={highlightedCardClass}>
											<div className="mb-4 flex flex-wrap items-center justify-between gap-2">
												<Subheading level={3}>
													Umbrales por beneficiarios
												</Subheading>
												<Button type="button" outline onClick={addBeneficiaryRule}>
													Agregar umbral
													<PlusIcon />
												</Button>
											</div>
											{(data.beneficiary_rules ?? []).length === 0 ? (
												<p className="text-sm text-zinc-600 dark:text-zinc-400">
													No hay umbrales. Agrega rangos (p. ej. 1–9 con 0
													autorizadores).
												</p>
											) : (
												<div className="space-y-4">
													{(data.beneficiary_rules ?? []).map((r, idx) => (
														<div
															key={idx}
															className="grid gap-3 border-b border-zinc-200 pb-4 last:border-0 last:pb-0 dark:border-zinc-700 sm:grid-cols-12 sm:items-end"
														>
															<Field className="sm:col-span-3">
																<Label>Inicio</Label>
																<Input
																	type="number"
																	min="1"
																	value={r.min}
																	onChange={(e) =>
																		updateBeneficiaryRule(
																			idx,
																			"min",
																			e.target.value,
																		)
																	}
																/>
															</Field>
															<Field className="sm:col-span-3">
																<Label>Hasta</Label>
																<Input
																	type="number"
																	min="1"
																	placeholder="Sin límite"
																	value={r.max}
																	onChange={(e) =>
																		updateBeneficiaryRule(
																			idx,
																			"max",
																			e.target.value,
																		)
																	}
																/>
															</Field>
															<Field className="sm:col-span-3">
																<Label># autorizadores</Label>
																<Input
																	type="number"
																	min="0"
																	value={r.required_approvals}
																	onChange={(e) =>
																		updateBeneficiaryRule(
																			idx,
																			"required_approvals",
																			e.target.value,
																		)
																	}
																/>
															</Field>
															<div className="flex justify-end sm:col-span-3">
																<Button
																	type="button"
																	outline
																	onClick={() => removeBeneficiaryRule(idx)}
																>
																	Quitar
																	<TrashIcon />
																</Button>
															</div>
															{errors[`beneficiary_rules.${idx}.min`] && (
																<p className="text-sm text-red-600 sm:col-span-12 dark:text-red-400">
																	{errors[`beneficiary_rules.${idx}.min`]}
																</p>
															)}
															{errors[`beneficiary_rules.${idx}.max`] && (
																<p className="text-sm text-red-600 sm:col-span-12 dark:text-red-400">
																	{errors[`beneficiary_rules.${idx}.max`]}
																</p>
															)}
															{errors[`beneficiary_rules.${idx}.required_approvals`] && (
																<p className="text-sm text-red-600 sm:col-span-12 dark:text-red-400">
																	{
																		errors[
																			`beneficiary_rules.${idx}.required_approvals`
																		]
																	}
																</p>
															)}
														</div>
													))}
												</div>
											)}
										</div>

										<div className={highlightedCardClass}>
											<Field>
												<Label>
													Umbral de campaña masiva (beneficiarios, opcional)
												</Label>
												<Input
													type="number"
													min="1"
													placeholder="Sin umbral"
													value={data.mass_campaign_threshold}
													onChange={(e) =>
														setData(
															"mass_campaign_threshold",
															e.target.value,
														)
													}
												/>
												{errors.mass_campaign_threshold && (
													<p className="mt-1 text-sm text-red-600 dark:text-red-400">
														{errors.mass_campaign_threshold}
													</p>
												)}
											</Field>
											<CheckboxField>
												<Checkbox
													checked={data.superadmin_bypass_approvals}
													onChange={(v) =>
														setData("superadmin_bypass_approvals", v)
													}
												/>
												<Label>Superadmin omite aprobaciones</Label>
											</CheckboxField>
										</div>
									</>
								)}

								{activeTab === "history" && (
									<>
										<div className={highlightedCardClass}>
											<Text className="text-sm text-zinc-700 dark:text-zinc-300">
												Solicitudes de cambio en <strong>reglas de cupones</strong>{" "}
												(quien las pidió, firmas registradas y resultado). Se muestran
												las últimas 100.
											</Text>
										</div>
										<div className={highlightedCardClass}>
											{(settingsApprovalHistory ?? []).length === 0 ? (
												<p className="text-sm text-zinc-600 dark:text-zinc-400">
													No hay solicitudes de este tipo registradas aún.
												</p>
											) : (
												<div className="overflow-x-auto">
													<table className="min-w-full text-left text-sm">
														<thead>
															<tr className="border-b border-zinc-200 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
																<th className="whitespace-nowrap py-2 pr-3">#</th>
																<th className="whitespace-nowrap py-2 pr-3">Estado</th>
																<th className="whitespace-nowrap py-2 pr-3">
																	Solicitó
																</th>
																<th className="whitespace-nowrap py-2 pr-3">Fecha</th>
																<th className="min-w-[14rem] py-2 pr-3">
																	Aprobaciones (firmas)
																</th>
																<th className="min-w-[10rem] py-2">Cierre</th>
																<th className="min-w-[12rem] py-2">Acciones</th>
															</tr>
														</thead>
														<tbody>
															{(settingsApprovalHistory ?? []).map((row) => (
																<Fragment key={row.id}>
																	<tr
																	className="border-b border-zinc-100 align-top dark:border-zinc-800"
																>
																	<td className="whitespace-nowrap py-3 pr-3 font-medium text-zinc-900 dark:text-zinc-100">
																		{row.id}
																	</td>
																	<td className="whitespace-nowrap py-3 pr-3">
																		<Badge
																			color={
																				row.status === "executed"
																					? "emerald"
																					: row.status === "rejected"
																						? "red"
																						: "amber"
																			}
																		>
																			{settingsRequestStatusLabel(row.status)}
																		</Badge>
																	</td>
																	<td className="py-3 pr-3">
																		<div className="font-medium text-zinc-900 dark:text-zinc-100">
																			{row.requested_by?.name ?? "—"}
																		</div>
																		{row.requested_by?.email ? (
																			<div className="mt-0.5 break-all text-xs text-zinc-500 dark:text-zinc-400">
																				{row.requested_by.email}
																			</div>
																		) : null}
																	</td>
																	<td className="whitespace-nowrap py-3 pr-3 text-zinc-600 dark:text-zinc-300">
																		{formatShortDateTime(row.created_at)}
																	</td>
																	<td className="py-3 pr-3 text-zinc-700 dark:text-zinc-300">
																		{row.approvers?.length ? (
																			<ul className="list-inside list-disc space-y-1 text-xs">
																				{row.approvers.map((a, i) => (
																					<li key={i}>
																						<strong>{a.name}</strong>
																						{a.email ? ` · ${a.email}` : ""}
																						{a.acted_at
																							? ` · ${formatShortDateTime(a.acted_at)}`
																							: ""}
																					</li>
																				))}
																			</ul>
																		) : (
																			<span className="text-xs text-zinc-500">
																				Sin firmas aún
																			</span>
																		)}
																		<div className="mt-1 text-xs text-zinc-500">
																			Requeridas: {row.required_approvals ?? "—"} ·
																			Registradas: {row.current_approvals ?? 0}
																		</div>
																	</td>
																	<td className="py-3 text-xs text-zinc-600 dark:text-zinc-300">
																		{row.status === "executed" && row.executed_at ? (
																			<>
																				<span className="font-medium text-zinc-800 dark:text-zinc-200">
																					Aplicada
																				</span>
																				<br />
																				{formatShortDateTime(row.executed_at)}
																			</>
																		) : null}
																		{row.status === "rejected" ? (
																			<>
																				<span className="font-medium text-red-700 dark:text-red-300">
																					Rechazada
																				</span>
																				{row.rejected_at ? (
																					<>
																						<br />
																						{formatShortDateTime(row.rejected_at)}
																					</>
																				) : null}
																				{row.rejected_by?.name ? (
																					<>
																						<br />
																						<span className="mt-1 inline-block text-zinc-600 dark:text-zinc-400">
																							Por: {row.rejected_by.name}
																							{row.rejected_by.email
																								? ` (${row.rejected_by.email})`
																								: ""}
																						</span>
																					</>
																				) : null}
																			</>
																		) : null}
																		{row.status === "pending" ? (
																			<span className="text-zinc-500">En curso</span>
																		) : null}
																	</td>
																	<td className="py-3">
																		{row.can_approve ? (
																			<div className="flex flex-wrap gap-2">
																				<Button
																					type="button"
																					color="emerald"
																					onClick={() => decideApproval(row.id, true)}
																				>
																					Aprobar
																				</Button>
																				<Button
																					type="button"
																					outline
																					className="border-red-300 text-red-700 hover:bg-red-50 dark:border-red-500/50 dark:text-red-300 dark:hover:bg-red-950/40"
																					onClick={() => decideApproval(row.id, false)}
																				>
																					Rechazar
																				</Button>
																			</div>
																		) : (
																			<span className="text-xs text-zinc-500">—</span>
																		)}
																	</td>
																</tr>
																<tr className="border-b border-zinc-100 dark:border-zinc-800">
																	<td colSpan={7} className="pb-4 pr-3">
																		<details className="rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-300">
																			<summary className="cursor-pointer font-semibold">
																				Ver parámetros propuestos
																			</summary>
																			<pre className="mt-2 max-h-72 overflow-auto whitespace-pre-wrap rounded-md bg-white p-3 font-mono text-[11px] text-zinc-700 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-zinc-700">
																				{JSON.stringify(row.after_state ?? {}, null, 2)}
																			</pre>
																		</details>
																	</td>
																</tr>
															</Fragment>
															))}
														</tbody>
													</table>
												</div>
											)}
											<div className="mt-4">
												<Button href={route("admin.coupons.logs")} plain className="text-sm">
													Ver registro completo de actividad (todos los tipos)
												</Button>
											</div>
										</div>
									</>
								)}

								{activeTab === "authorizers" && (
									<>
										<div className={highlightedCardClass}>
											<Text className="text-sm text-zinc-700 dark:text-zinc-300">
												Los cambios de configuración que requieran aprobación se envían
												por correo a todos los usuarios con rol autorizador. Se
												necesitan al menos dos aprobaciones para aplicar los cambios.
												No es necesario seleccionar destinatarios aquí.
											</Text>
										</div>
									</>
								)}

								{activeTab === "concepts" && (
									<>
										<CouponSectionCard
											title="Conceptos"
											description="Etiquetas reutilizables para clasificar créditos. «Otro» crea un concepto al guardar."
											actions={
												<Button type="button" onClick={() => openConceptModal()}>
													<PlusIcon />
													Nuevo concepto
												</Button>
											}
											bodyClassName="!p-0"
										>
											{concepts.length === 0 ? (
												<CouponEmptyState
													icon={TagIcon}
													title="Sin conceptos"
													description="Crea el primero para organizar tus campañas de crédito."
													action={
														<Button type="button" onClick={() => openConceptModal()}>
															<PlusIcon />
															Nuevo concepto
														</Button>
													}
												/>
											) : (
											<div className="overflow-x-auto">
												<Table>
													<TableHead>
														<TableRow>
															<TableHeader className="w-16">ID</TableHeader>
															<TableHeader>Título</TableHeader>
															<TableHeader>Descripción</TableHeader>
															<TableHeader className="text-right">Acciones</TableHeader>
														</TableRow>
													</TableHead>
													<TableBody>
														{concepts.map((concept) => (
																<TableRow key={concept.id}>
																	<TableCell className="font-mono text-xs text-zinc-500 dark:text-zinc-400">
																		#{concept.id}
																	</TableCell>
																	<TableCell className="font-medium text-zinc-900 dark:text-zinc-100">
																		{concept.title}
																	</TableCell>
																	<TableCell className="max-w-md text-sm text-zinc-700 dark:text-zinc-300">
																		{concept.description ? (
																			<span className="line-clamp-3">
																				{concept.description}
																			</span>
																		) : (
																			<span className="text-zinc-400 dark:text-zinc-500">
																				—
																			</span>
																		)}
																	</TableCell>
																	<TableCell className="text-right">
																		<div className="flex flex-wrap justify-end gap-2">
																			<Button
																				type="button"
																				plain
																				aria-label="Editar concepto"
																				title="Editar"
																				onClick={() => openConceptModal(concept)}
																			>
																				<PencilSquareIcon />
																			</Button>
																			<Button
																				type="button"
																				plain
																				aria-label="Eliminar concepto"
																				title="Eliminar"
																				className="text-red-600 dark:text-red-400"
																				onClick={() =>
																					setConceptDeleteTarget(concept)
																				}
																			>
																				<TrashIcon />
																			</Button>
																		</div>
																	</TableCell>
																</TableRow>
															))}
													</TableBody>
												</Table>
											</div>
											)}
										</CouponSectionCard>
									</>
								)}
							</motion.div>
						</AnimatePresence>
					</div>

					<div className="shrink-0 border-t border-zinc-200 px-4 py-4 dark:border-zinc-700 sm:px-6">
						{activeTab === "history" ? (
							<Text className="text-xs text-zinc-500 dark:text-zinc-400">
								El historial es solo lectura. Cambia a &quot;Reglas de aprobación&quot; para
								editar y guardar.
							</Text>
						) : activeTab === "concepts" ? (
							<Text className="text-xs text-zinc-500 dark:text-zinc-400">
								Los conceptos se guardan al confirmar el formulario del modal. No es
								necesario presionar &quot;Guardar cambios&quot; en esta pestaña.
							</Text>
						) : (
							<>
								<Button
									type="submit"
									disabled={processing}
									className="max-md:w-full"
								>
									Guardar cambios
								</Button>
								<Text className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
									Guarda todos los valores del formulario; los campos de otras pestañas no
									se pierden al cambiar de tab.
								</Text>
							</>
						)}
					</div>
				</form>

				<div className="space-y-6">
					<div className={highlightedCardClass}>
						<div className="flex items-start justify-between gap-3">
							<div>
								<Subheading>Autorizadores</Subheading>
								<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
									Administradores con rol autorizador.
								</Text>
							</div>
							<Badge color="emerald">{authorizers.length}</Badge>
						</div>

						{authorizers.length > 0 ? (
							<div className="mt-5 divide-y divide-zinc-200 dark:divide-zinc-800">
								{authorizers.map((authorizer) => (
									<div
										key={authorizer.id}
										className="py-3 first:pt-0 last:pb-0"
									>
										<p className="font-medium text-zinc-950 dark:text-white">
											{authorizer.name}
										</p>
										<p className="mt-0.5 break-all text-sm text-zinc-600 dark:text-zinc-400">
											{authorizer.email || "Sin correo"}
										</p>
									</div>
								))}
							</div>
						) : (
							<div className="mt-5 rounded-lg border border-dashed border-zinc-300 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-400">
								No hay administradores con rol autorizador.
							</div>
						)}
					</div>

					<div className="rounded-lg border border-famedic-dark/20 bg-famedic-dark/5 p-6 shadow-sm dark:border-famedic-lime/20 dark:bg-famedic-darker/40">
						<Subheading
							level={3}
							className="text-famedic-dark dark:!text-famedic-lime"
						>
							Información importante
						</Subheading>
						<ul className="mt-3 list-inside list-disc space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
							<li>
								Si no eres superadmin, los cambios generan una solicitud de aprobación y
								no aplican hasta completar las firmas requeridas.
							</li>
							<li>
								El correo de autorización se usa para el código de nuevos cupones cuando
								está activada la autorización por correo.
							</li>
							<li>
								Las reglas por monto y por beneficiarios se combinan: se aplica el máximo
								de autorizaciones requeridas entre ambas.
							</li>
							<li>
								Enlace con pestaña:{" "}
								<code className="rounded bg-zinc-200 px-1 text-xs dark:bg-zinc-700">
									?tab=
								</code>{" "}
								approval, concepts, history, authorizers (la pestaña de límites está oculta
								por ahora).
							</li>
						</ul>
					</div>
				</div>
			</div>

			<Modal open={conceptModalOpen} onClose={closeConceptModal} size="lg">
				<form onSubmit={submitConcept} className="space-y-4">
					<div className="flex items-start justify-between gap-3">
						<div>
							<h3 className="text-lg font-semibold text-zinc-900 dark:text-white">
								{conceptForm.id ? "Editar concepto" : "Nuevo concepto"}
							</h3>
							<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
								Estos conceptos estarán disponibles al registrar un crédito a favor.
							</p>
						</div>
						<Button type="button" plain onClick={closeConceptModal}>
							Cerrar
						</Button>
					</div>

					<Field>
						<Label>Título</Label>
						<Input
							autoFocus
							value={conceptForm.title}
							onChange={(e) => setConceptForm("title", e.target.value)}
							maxLength={255}
							placeholder="Ej. Reembolso por cancelación"
						/>
						{conceptErrors.title && (
							<p className="mt-1 text-sm text-red-600 dark:text-red-400">
								{conceptErrors.title}
							</p>
						)}
					</Field>

					<Field>
						<Label>Descripción</Label>
						<Textarea
							rows={4}
							value={conceptForm.description ?? ""}
							onChange={(e) => setConceptForm("description", e.target.value)}
							maxLength={2000}
							placeholder="Información adicional o instrucciones internas (opcional)."
						/>
						{conceptErrors.description && (
							<p className="mt-1 text-sm text-red-600 dark:text-red-400">
								{conceptErrors.description}
							</p>
						)}
					</Field>

					<div className="flex justify-end gap-2 pt-2">
						<Button type="button" outline onClick={closeConceptModal}>
							Cancelar
						</Button>
						<Button type="submit" disabled={conceptProcessing}>
							{conceptForm.id ? "Guardar cambios" : "Crear concepto"}
						</Button>
					</div>
				</form>
			</Modal>

			<DeleteConfirmationModal
				isOpen={!!conceptDeleteTarget}
				close={() => setConceptDeleteTarget(null)}
				title="Eliminar concepto"
				description={
					conceptDeleteTarget
						? `Se eliminará el concepto «${conceptDeleteTarget.title}». Los créditos que ya lo referencian conservarán el registro, pero quedará desvinculado de futuras asignaciones.`
						: ""
				}
				processing={deletingConcept}
				destroy={confirmDeleteConcept}
			/>
		</AdminLayout>
	);
}
