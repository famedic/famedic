import { useCallback, useEffect, useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Badge } from "@/Components/Catalyst/badge";
import { useForm } from "@inertiajs/react";
import { AnimatePresence, motion } from "framer-motion";

const TABS = [
	{ id: "limits", label: "Límites de créditos" },
	{ id: "approval", label: "Reglas de aprobación" },
	{ id: "authorizers", label: "Autorizadores" },
];

/** @param {Record<string, string|string[]>} errs */
function errorsForTab(errs, tabId) {
	if (!errs || typeof errs !== "object") return false;
	const keys = Object.keys(errs);
	const match = (prefix) =>
		keys.some((k) => k === prefix || k.startsWith(`${prefix}.`));

	switch (tabId) {
		case "limits":
			return (
				match("base_amount_mxn") ||
				match("max_assignment_amount_mxn") ||
				match("max_assignments_per_day")
			);
		case "approval":
			return (
				match("amount_threshold_mxn") ||
				match("required_approvals_by_amount") ||
				match("superadmin_bypass_approvals") ||
				match("mass_campaign_threshold") ||
				match("beneficiary_rules")
			);
		case "authorizers":
			return match("authorizer_ids");
		default:
			return false;
	}
}

export default function CouponsSettings({
	settings,
	authorizers = [],
	initialTab = "limits",
}) {
	const allowed = new Set(TABS.map((t) => t.id));
	const normalizedInitial = allowed.has(initialTab) ? initialTab : "limits";

	const [activeTab, setActiveTabState] = useState(normalizedInitial);

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
		amount_threshold_mxn:
			settings.amount_threshold_cents != null
				? String(settings.amount_threshold_cents / 100)
				: "",
		required_approvals_by_amount:
			settings.required_approvals_by_amount ?? 0,
		mass_campaign_threshold:
			settings.mass_campaign_threshold != null
				? String(settings.mass_campaign_threshold)
				: "",
		superadmin_bypass_approvals: !!settings.superadmin_bypass_approvals,
		beneficiary_rules: [],
		authorizer_ids: [],
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
						Configura límites, reglas de aprobación y autorización de créditos a favor.
						Los cambios pueden requerir validación según tu rol.
					</Text>
				</div>
				<Button href={route("admin.coupons.index")} outline>
					Volver al listado
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
								{activeTab === "limits" && (
									<>
										<div className={highlightedCardClass}>
											<Text className="text-sm text-zinc-700 dark:text-zinc-300">
												Valores que limitan montos y volumen de asignaciones en el sistema.
											</Text>
										</div>
										<div className={highlightedCardClass}>
											<Field>
												<Label>Monto base por usuario (MXN)</Label>
												<Input
													type="number"
													step="0.01"
													min="0"
													value={data.base_amount_mxn}
													onChange={(e) =>
														setData("base_amount_mxn", e.target.value)
													}
												/>
												{errors.base_amount_mxn && (
													<p className="mt-1 text-sm text-red-600 dark:text-red-400">
														{errors.base_amount_mxn}
													</p>
												)}
											</Field>
											<Field>
												<Label>Monto máximo por cupón / asignación (MXN)</Label>
												<Input
													type="number"
													step="0.01"
													min="0"
													placeholder="Sin límite"
													value={data.max_assignment_amount_mxn}
													onChange={(e) =>
														setData(
															"max_assignment_amount_mxn",
															e.target.value,
														)
													}
												/>
												{errors.max_assignment_amount_mxn && (
													<p className="mt-1 text-sm text-red-600 dark:text-red-400">
														{errors.max_assignment_amount_mxn}
													</p>
												)}
											</Field>
											<Field>
												<Label>Máximo de asignaciones por día (total sistema)</Label>
												<Input
													type="number"
													min="1"
													placeholder="Sin límite"
													value={data.max_assignments_per_day}
													onChange={(e) =>
														setData(
															"max_assignments_per_day",
															e.target.value,
														)
													}
												/>
												{errors.max_assignments_per_day && (
													<p className="mt-1 text-sm text-red-600 dark:text-red-400">
														{errors.max_assignments_per_day}
													</p>
												)}
											</Field>
										</div>
									</>
								)}

								{activeTab === "approval" && (
									<>
										<div className={highlightedCardClass}>
											<Text className="text-sm text-zinc-700 dark:text-zinc-300">
												Aprobaciones adicionales según monto; el motor usa el máximo
												entre estas reglas y las de beneficiarios (configuradas en backend
												si aplica).
											</Text>
										</div>
										<div className={highlightedCardClass}>
											<Field>
												<Label>Monto umbral (MXN)</Label>
												<Input
													type="number"
													step="0.01"
													min="0"
													placeholder="Ej. 500"
													value={data.amount_threshold_mxn}
													onChange={(e) =>
														setData("amount_threshold_mxn", e.target.value)
													}
												/>
												{errors.amount_threshold_mxn && (
													<p className="mt-1 text-sm text-red-600 dark:text-red-400">
														{errors.amount_threshold_mxn}
													</p>
												)}
											</Field>
											<Field>
												<Label>Aprobaciones requeridas por monto</Label>
												<Input
													type="number"
													min="0"
													value={data.required_approvals_by_amount}
													onChange={(e) =>
														setData(
															"required_approvals_by_amount",
															e.target.value,
														)
													}
												/>
												{errors.required_approvals_by_amount && (
													<p className="mt-1 text-sm text-red-600 dark:text-red-400">
														{errors.required_approvals_by_amount}
													</p>
												)}
											</Field>
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

								{activeTab === "authorizers" && (
									<>
										<div className={highlightedCardClass}>
											<Text className="text-sm text-zinc-700 dark:text-zinc-300">
												Quienes pueden aprobar cambios de configuración cuando no aplicas
												como superadmin. Selecciona uno o más autorizadores.
											</Text>
										</div>
										<div className={highlightedCardClass}>
											<div className="max-h-[min(22rem,50vh)] space-y-2 overflow-y-auto rounded-lg border border-famedic-dark/20 bg-white/60 p-3 dark:border-famedic-lime/20 dark:bg-famedic-darker/30">
												{authorizers.length === 0 ? (
													<p className="text-sm text-zinc-600 dark:text-zinc-300">
														No hay administradores con rol autorizador.
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
																					(id) =>
																						id !== authorizer.id,
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
										</div>
										{errors.authorizer_ids && (
											<p className="text-sm text-red-600 dark:text-red-400">
												{errors.authorizer_ids}
											</p>
										)}
									</>
								)}
							</motion.div>
						</AnimatePresence>
					</div>

					<div className="shrink-0 border-t border-zinc-200 px-4 py-4 dark:border-zinc-700 sm:px-6">
						<Button
							type="submit"
							disabled={processing}
							className="max-md:w-full"
						>
							Guardar cambios
						</Button>
						<Text className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
							Guarda todos los valores del formulario; los campos de otras pestañas no se
							pierden al cambiar de tab.
						</Text>
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
								Si no eres superadmin, los cambios pueden generar una solicitud de
								aprobación y no aplicarán hasta que los autorizadores la completen.
							</li>
							<li>
								El correo del autorizador se usa para el código de nuevos cupones
								cuando está activada la autorización por correo.
							</li>
							<li>
								El umbral de monto y las aprobaciones por beneficiarios suman criterios:
								el sistema aplica el máximo requerido.
							</li>
							<li>
								Puedes compartir un enlace con pestaña abierta usando el parámetro{" "}
								<code className="rounded bg-zinc-200 px-1 text-xs dark:bg-zinc-700">
									?tab=
								</code>
								: limits, approval, authorizers.
							</li>
						</ul>
					</div>
				</div>
			</div>
		</AdminLayout>
	);
}
