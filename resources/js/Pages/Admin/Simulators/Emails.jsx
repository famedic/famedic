import { useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Listbox, ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";
import { Link } from "@inertiajs/react";
import { ArrowLeftIcon, EnvelopeOpenIcon } from "@heroicons/react/24/outline";

function previewHref(type, purchaseId) {
	if (!purchaseId) {
		return "#";
	}
	const base = route("admin.simulators.emails.preview", { type });
	return `${base}?laboratory_purchase=${encodeURIComponent(String(purchaseId))}`;
}

export default function EmailSimulator({ purchases, suggestedPurchaseId, emailGroups }) {
	const purchasesWithUser = useMemo(() => purchases.filter((p) => p.has_customer_user), [purchases]);

	const initialId = useMemo(() => {
		if (suggestedPurchaseId && purchases.some((p) => p.id === suggestedPurchaseId && p.has_customer_user)) {
			return suggestedPurchaseId;
		}
		return purchasesWithUser[0]?.id ?? null;
	}, [purchases, purchasesWithUser, suggestedPurchaseId]);

	const [selectedPurchaseId, setSelectedPurchaseId] = useState(initialId);

	const selectedPurchase = useMemo(
		() => purchases.find((p) => p.id === selectedPurchaseId) ?? null,
		[purchases, selectedPurchaseId],
	);

	const canPreview = Boolean(selectedPurchase?.has_customer_user);

	return (
		<AdminLayout title="Simulador de correos">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start gap-4">
					<Button href={route("admin.simulators.index")} outline>
						<ArrowLeftIcon className="size-4" />
						Simuladores
					</Button>
				</div>

				<div>
					<Heading>Simulador de correos</Heading>
					<Text className="mt-2 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Elige un <Strong>pedido de laboratorio</Strong> con cliente que tenga usuario en Famedic. Los
						enlaces de vista previa se abren en una <Strong>nueva pestaña</Strong> y solo generan HTML local;
						no se envían correos reales.
					</Text>
				</div>

				<div className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
					<div className="flex items-start gap-3">
						<div className="rounded-2xl bg-sky-100 p-2.5 dark:bg-sky-950/40">
							<EnvelopeOpenIcon className="size-6 text-sky-800 dark:text-sky-200" />
						</div>
						<div className="min-w-0 flex-1 space-y-4">
							<div>
								<Subheading>Contexto: pedido y usuario simulado</Subheading>
								<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
									Las plantillas usan datos reales del pedido y del <Strong>usuario del cliente</Strong>{" "}
									(correo, nombre, etc.). Si un pedido no tiene usuario (solo invitado o datos
									incompletos), no podrás previsualizarlo.
								</Text>
							</div>

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
												{purchase.gda_order_id ? ` · GDA ${purchase.gda_order_id}` : ""} ·{" "}
												{purchase.customer_label}
												{purchase.has_customer_user ? "" : " · (sin usuario cliente)"} ·{" "}
												{purchase.created_at}
											</ListboxLabel>
										</ListboxOption>
									))}
								</Listbox>
							</Field>

							{suggestedPurchaseId &&
								purchases.some((p) => p.id === suggestedPurchaseId && p.has_customer_user) && (
								<Text className="text-xs text-zinc-500 dark:text-slate-500">
									Sugerencia: el pedido <Strong>#{suggestedPurchaseId}</Strong> tiene usuario cliente y
									suele funcionar bien para pruebas.
								</Text>
							)}

							{selectedPurchase && !selectedPurchase.has_customer_user && (
								<div className="rounded-xl border border-amber-300/60 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-950/30 dark:text-amber-100">
									Este pedido no tiene usuario cliente vinculado. Selecciona otro para habilitar las
									vistas previas.
								</div>
							)}

							{purchasesWithUser.length === 0 && (
								<Text className="text-sm text-amber-700 dark:text-amber-200">
									No hay pedidos recientes con usuario cliente. Completa una compra de laboratorio con
									cuenta o elige un pedido más antiguo desde el listado admin.
								</Text>
							)}
						</div>
					</div>
				</div>

				<div className="space-y-8">
					{emailGroups.map((group) => (
						<section key={group.title} className="space-y-3">
							<Subheading>{group.title}</Subheading>
							<ul className="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white dark:divide-slate-700 dark:border-slate-700 dark:bg-slate-900">
								{group.items.map((item) => (
									<li key={item.key} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
										<div className="min-w-0">
											<p className="font-medium text-zinc-900 dark:text-zinc-100">{item.title}</p>
											<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{item.description}</Text>
										</div>
										<div className="shrink-0">
											<a
												href={previewHref(item.key, selectedPurchaseId)}
												target="_blank"
												rel="noopener noreferrer"
												className={
													canPreview && selectedPurchaseId
														? "inline-flex text-sm font-medium text-famedic-light underline hover:no-underline"
														: "inline-flex cursor-not-allowed text-sm font-medium text-zinc-400 no-underline dark:text-zinc-600"
												}
												aria-disabled={!canPreview || !selectedPurchaseId}
												onClick={(e) => {
													if (!canPreview || !selectedPurchaseId) {
														e.preventDefault();
													}
												}}
											>
												Abrir vista previa →
											</a>
										</div>
									</li>
								))}
							</ul>
						</section>
					))}
				</div>

				<Text className="text-xs text-zinc-500 dark:text-slate-500">
					¿Necesitas otra herramienta?{" "}
					<Link href={route("admin.simulators.index")} className="text-famedic-light hover:underline">
						Volver al listado de simuladores
					</Link>
				</Text>
			</div>
		</AdminLayout>
	);
}
