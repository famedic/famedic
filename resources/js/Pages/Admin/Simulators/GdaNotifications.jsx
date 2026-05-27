import { useCallback, useEffect, useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Listbox, ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Link } from "@inertiajs/react";
import { ArrowLeftIcon, BellAlertIcon } from "@heroicons/react/24/outline";
import axios from "axios";

export default function GdaNotificationsSimulator({ purchases, webhookUrl }) {
	const [selectedPurchaseId, setSelectedPurchaseId] = useState(
		purchases.find((p) => p.has_gda_reference)?.id ?? purchases[0]?.id ?? null,
	);
	const [notificationType, setNotificationType] = useState("sample_collection");
	const [sendEmail, setSendEmail] = useState(false);
	const [selectedItemId, setSelectedItemId] = useState(null);
	const [history, setHistory] = useState(null);
	const [loadingHistory, setLoadingHistory] = useState(false);
	const [submitting, setSubmitting] = useState(false);
	const [resending, setResending] = useState(null);
	const [feedback, setFeedback] = useState(null);
	const [error, setError] = useState(null);

	const selectedPurchase = useMemo(
		() => purchases.find((p) => p.id === selectedPurchaseId) ?? null,
		[purchases, selectedPurchaseId],
	);

	const loadHistory = useCallback(async (purchaseId) => {
		if (!purchaseId) {
			setHistory(null);
			return;
		}
		setLoadingHistory(true);
		setError(null);
		try {
			const { data } = await axios.get(
				route("admin.simulators.gda.history", { laboratory_purchase: purchaseId }),
			);
			setHistory(data);
			if (data.purchase?.items?.length) {
				setSelectedItemId((prev) => prev ?? data.purchase.items[0].id);
			}
		} catch (e) {
			setError(e.response?.data?.message ?? "No se pudo cargar el historial.");
			setHistory(null);
		} finally {
			setLoadingHistory(false);
		}
	}, []);

	useEffect(() => {
		setSelectedItemId(null);
		loadHistory(selectedPurchaseId);
	}, [selectedPurchaseId, loadHistory]);

	const handleSimulate = async () => {
		if (!selectedPurchaseId) return;
		setSubmitting(true);
		setFeedback(null);
		setError(null);
		try {
			const { data } = await axios.post(
				route("admin.simulators.gda.simulate", { laboratory_purchase: selectedPurchaseId }),
				{
					notification_type: notificationType,
					send_email: sendEmail,
					laboratory_purchase_item_id: selectedItemId,
				},
			);
			setFeedback(data.message);
			if (data.history) setHistory(data.history);
		} catch (e) {
			const msg =
				e.response?.data?.message ??
				Object.values(e.response?.data?.errors ?? {}).flat().join(" ") ??
				"Error al simular.";
			setError(msg);
		} finally {
			setSubmitting(false);
		}
	};

	const handleResend = async (type) => {
		if (!selectedPurchaseId) return;
		setResending(type);
		setFeedback(null);
		setError(null);
		try {
			const { data } = await axios.post(
				route("admin.simulators.gda.resend", { laboratory_purchase: selectedPurchaseId }),
				{ type },
			);
			setFeedback(data.message);
			if (data.history) setHistory(data.history);
		} catch (e) {
			const msg =
				e.response?.data?.message ??
				Object.values(e.response?.data?.errors ?? {}).flat().join(" ") ??
				"Error al reenviar.";
			setError(msg);
		} finally {
			setResending(null);
		}
	};

	const items = history?.purchase?.items ?? [];

	return (
		<AdminLayout title="Simulador notificaciones GDA">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start gap-4">
					<Button href={route("admin.simulators.index")} outline>
						<ArrowLeftIcon className="size-4" />
						Simuladores
					</Button>
				</div>

				<div>
					<Heading>Simulador de notificaciones GDA</Heading>
					<Text className="mt-2 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Replica el webhook <code className="text-xs">POST /api/laboratory/webhook/notifications</code>{" "}
						({webhookUrl}): crea registros en <Strong>laboratory_notifications</Strong>, actualiza el pedido y
						opcionalmente envía el correo al paciente (mismas reglas de gate que en producción, salvo que con
						correo activado en simulación se fuerza el envío inmediato).
					</Text>
				</div>

				<div className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
					<div className="flex items-start gap-3">
						<div className="rounded-2xl bg-violet-100 p-2.5 dark:bg-violet-950/40">
							<BellAlertIcon className="size-6 text-violet-800 dark:text-violet-200" />
						</div>
						<div className="min-w-0 flex-1 space-y-4">
							<Subheading>Simular notificación entrante</Subheading>

							<Field>
								<Label>Pedido de laboratorio</Label>
								<Listbox
									value={selectedPurchaseId}
									onChange={setSelectedPurchaseId}
									placeholder="Selecciona un pedido"
									disabled={purchases.length === 0}
								>
									{purchases.map((purchase) => (
										<ListboxOption key={purchase.id} value={purchase.id}>
											<ListboxLabel>
												#{purchase.id}
												{purchase.brand_label ? ` · ${purchase.brand_label}` : ""}
												{purchase.gda_order_id ? ` · GDA ${purchase.gda_order_id}` : ""}
												{purchase.gda_consecutivo ? ` · Cons. ${purchase.gda_consecutivo}` : ""}
												{!purchase.has_gda_reference ? " · (sin folio GDA)" : ""} · {purchase.customer_label}{" "}
												· {purchase.studies_count} estudios · {purchase.created_at}
											</ListboxLabel>
										</ListboxOption>
									))}
								</Listbox>
							</Field>

							{selectedPurchase && (
								<Text className="text-sm text-zinc-600 dark:text-zinc-400">
									Marca: <Strong>{selectedPurchase.brand_label ?? history?.purchase?.brand_label ?? "—"}</Strong>
									{selectedPurchase.gda_order_id ? (
										<>
											{" "}
											· Folio GDA: <Strong>{selectedPurchase.gda_order_id}</Strong>
										</>
									) : null}
								</Text>
							)}

							{selectedPurchase && !selectedPurchase.has_gda_reference && (
								<div className="rounded-xl border border-amber-300/60 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-950/30 dark:text-amber-100">
									Este pedido no tiene <Strong>gda_order_id</Strong> ni <Strong>gda_consecutivo</Strong>; no se
									puede simular hasta que exista folio GDA.
								</div>
							)}

							<div className="grid gap-4 sm:grid-cols-2">
								<Field>
									<Label>Tipo de notificación</Label>
									<Listbox value={notificationType} onChange={setNotificationType}>
										<ListboxOption value="sample_collection">
											<ListboxLabel>Toma de muestra</ListboxLabel>
										</ListboxOption>
										<ListboxOption value="results">
											<ListboxLabel>Resultados disponibles</ListboxLabel>
										</ListboxOption>
									</Listbox>
								</Field>

								{items.length > 0 && (
									<Field>
										<Label>Estudio en el payload (opcional)</Label>
										<Listbox
											value={selectedItemId}
											onChange={setSelectedItemId}
											placeholder="Primer estudio del pedido"
										>
											{items.map((item) => (
												<ListboxOption key={item.id} value={item.id}>
													<ListboxLabel>
														{item.gda_id} · {item.name}
													</ListboxLabel>
												</ListboxOption>
											))}
										</Listbox>
									</Field>
								)}
							</div>

							<CheckboxField>
								<Checkbox checked={sendEmail} onChange={setSendEmail} color="lime" />
								<Label>Enviar correo al paciente en esta simulación</Label>
							</CheckboxField>
							<Text className="text-xs text-zinc-500 dark:text-slate-500">
								Si está desactivado, solo se registran y procesan los datos (gate, BD). Si está activado, se
								intenta enviar el correo de inmediato al cliente del pedido (sin esperar el conteo de estudios).
							</Text>

							<div className="flex flex-wrap gap-2">
								<Button
									color="famedic-lime"
									disabled={
										!selectedPurchaseId ||
										!selectedPurchase?.has_gda_reference ||
										submitting
									}
									onClick={handleSimulate}
								>
									{submitting ? "Simulando…" : "Simular notificación GDA"}
								</Button>
								<Button outline disabled={loadingHistory} onClick={() => loadHistory(selectedPurchaseId)}>
									{loadingHistory ? "Actualizando…" : "Actualizar historial"}
								</Button>
							</div>

							{feedback && (
								<div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-100">
									{feedback}
								</div>
							)}
							{error && (
								<div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/30 dark:text-red-100">
									{error}
								</div>
							)}
						</div>
					</div>
				</div>

				{history?.gate_state && (
					<div className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
						<Subheading>Estado del gate (conteo de estudios)</Subheading>
						<div className="mt-3 flex flex-wrap gap-2">
							{history.purchase?.brand_label && (
								<Badge color="violet">Marca: {history.purchase.brand_label}</Badge>
							)}
							<Badge color="zinc">Orden GDA: {history.gate_state.gda_order_id}</Badge>
							<Badge color="zinc">
								Muestras: {history.gate_state.sample_received_count} / {history.gate_state.total_studies}
							</Badge>
							<Badge color="zinc">Resultados: {history.gate_state.results_received_count}</Badge>
							{history.gate_state.sample_email_sent_at && (
								<Badge color="emerald">Correo muestra: {history.gate_state.sample_email_sent_at}</Badge>
							)}
							{history.gate_state.results_email_sent_at && (
								<Badge color="emerald">Correo resultados: {history.gate_state.results_email_sent_at}</Badge>
							)}
						</div>
					</div>
				)}

				<div className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
					<div className="flex flex-wrap items-center justify-between gap-3">
						<Subheading>Registros de notificaciones (reales)</Subheading>
						<div className="flex flex-wrap gap-2">
							<Button
								outline
								disabled={!history?.can_resend_sample || resending}
								onClick={() => handleResend("sample_collection")}
							>
								{resending === "sample_collection" ? "Enviando…" : "Reenviar correo toma de muestra"}
							</Button>
							<Button
								outline
								disabled={!history?.can_resend_results || resending}
								onClick={() => handleResend("results")}
							>
								{resending === "results" ? "Enviando…" : "Reenviar correo resultados"}
							</Button>
						</div>
					</div>

					{loadingHistory && (
						<Text className="mt-4 text-sm text-zinc-500">Cargando registros…</Text>
					)}

					{!loadingHistory && (!history?.notifications || history.notifications.length === 0) && (
						<Text className="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
							No hay notificaciones GDA vinculadas a este pedido todavía.
						</Text>
					)}

					{history?.notifications?.length > 0 && (
						<div className="mt-4 overflow-x-auto">
							<table className="min-w-full text-left text-sm">
								<thead>
									<tr className="border-b border-zinc-200 dark:border-slate-700">
										<th className="px-2 py-2 font-medium">ID</th>
										<th className="px-2 py-2 font-medium">Tipo</th>
										<th className="px-2 py-2 font-medium">Estado</th>
										<th className="px-2 py-2 font-medium">Acuse</th>
										<th className="px-2 py-2 font-medium">Correo</th>
										<th className="px-2 py-2 font-medium">Fecha</th>
									</tr>
								</thead>
								<tbody>
									{history.notifications.map((row) => (
										<tr key={row.id} className="border-b border-zinc-100 dark:border-slate-800">
											<td className="px-2 py-2">{row.id}</td>
											<td className="px-2 py-2">{row.type_label}</td>
											<td className="px-2 py-2">
												{row.status}
												{row.gda_status ? ` / ${row.gda_status}` : ""}
											</td>
											<td className="max-w-[8rem] truncate px-2 py-2 text-xs" title={row.gda_acuse}>
												{row.gda_acuse ?? "—"}
											</td>
											<td className="px-2 py-2">
												{row.email_sent_at ? (
													<span className="text-emerald-700 dark:text-emerald-300">
														Enviado {row.email_recipient_email ? `→ ${row.email_recipient_email}` : ""}
														<br />
														<span className="text-xs">{row.email_sent_at}</span>
													</span>
												) : row.email_error ? (
													<span className="text-red-600">{row.email_error}</span>
												) : (
													<span className="text-zinc-500">Sin envío</span>
												)}
											</td>
											<td className="px-2 py-2 text-xs">{row.created_at}</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>
					)}
				</div>

				<Text className="text-xs text-zinc-500 dark:text-slate-500">
					<Link href={route("admin.simulators.index")} className="text-famedic-light hover:underline">
						Volver al listado de simuladores
					</Link>
				</Text>
			</div>
		</AdminLayout>
	);
}
