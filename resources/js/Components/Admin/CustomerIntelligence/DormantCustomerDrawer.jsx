import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

function Section({ title, children }) {
	return (
		<section className="space-y-3 border-b border-zinc-200 pb-5 last:border-0 dark:border-zinc-700">
			<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
				{title}
			</h3>
			{children}
		</section>
	);
}

function Field({ label, value }) {
	return (
		<div>
			<p className="text-[11px] uppercase tracking-wide text-zinc-400">{label}</p>
			<p className="mt-0.5 text-sm text-zinc-800 dark:text-zinc-100">
				{value || "—"}
			</p>
		</div>
	);
}

export default function DormantCustomerDrawer({ open, drawer, loading = false, onClose }) {
	const general = drawer?.general;
	const ai = drawer?.ai;

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 backdrop-blur-[1px]" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-2xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-famedic-light">
								Perfil 360
							</p>
							<Headless.DialogTitle className="mt-1 text-lg font-semibold text-zinc-900 dark:text-zinc-50">
								{general?.name || (loading ? "Cargando…" : "Cliente")}
							</Headless.DialogTitle>
							{general?.email ? (
								<Text className="mt-0.5 text-xs text-zinc-500">{general.email}</Text>
							) : null}
						</div>
						<button
							type="button"
							onClick={onClose}
							className="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
						>
							<XMarkIcon className="size-5" />
						</button>
					</div>

					<div className="flex-1 space-y-5 overflow-y-auto px-5 py-5">
						{loading && !drawer ? (
							<p className="text-sm text-zinc-400">Cargando perfil…</p>
						) : null}

						{drawer ? (
							<>
								<Section title="Información general">
									<div className="grid grid-cols-2 gap-3">
										<Field label="Nombre" value={general.name} />
										<Field label="Sexo" value={general.gender} />
										<Field label="Nacimiento" value={general.birth_date} />
										<Field label="Ciudad" value={general.city} />
										<Field label="Estado" value={general.state} />
										<Field label="Teléfono" value={general.phone} />
										<Field label="Registro" value={general.registered_at} />
										<Field
											label="Tiempo registrado"
											value={`${general.days_registered} días`}
										/>
										<Field label="Última actividad" value={general.last_activity_at} />
										<Field label="Fuente" value={general.registration_source} />
										<Field label="Tipo de cuenta" value={general.account_type} />
									</div>
								</Section>

								<Section title="Timeline">
									<ol className="space-y-3">
										{(drawer.timeline || []).map((event, index) => (
											<li key={`${event.type}-${index}`} className="flex gap-3">
												<span className="mt-1 size-2.5 shrink-0 rounded-full bg-sky-500" />
												<div>
													<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
														{event.label}
													</p>
													<p className="text-xs text-zinc-400">{event.at}</p>
													{event.detail ? (
														<p className="mt-0.5 text-xs text-zinc-500">{event.detail}</p>
													) : null}
												</div>
											</li>
										))}
									</ol>
								</Section>

								<Section title="Actividad">
									<div className="grid grid-cols-2 gap-3">
										<Field
											label="Sesiones (proxy)"
											value={drawer.activity?.sessions_proxy}
										/>
										<Field
											label="Ítems lab"
											value={drawer.activity?.lab_cart_items}
										/>
										<Field
											label="Ítems farmacia"
											value={drawer.activity?.pharmacy_cart_items}
										/>
										<Field
											label="Checkouts"
											value={drawer.activity?.checkout_drafts}
										/>
										<Field label="Última visita" value={drawer.activity?.last_visit} />
										<Field label="Dispositivo" value={drawer.activity?.device} />
									</div>
								</Section>

								<Section title="Compras">
									<p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/30 dark:text-rose-300">
										{drawer.purchases?.message || "Nunca ha realizado una compra"}
									</p>
								</Section>

								<Section title="ActiveCampaign">
									<div className="flex flex-wrap gap-1.5">
										{(drawer.activecampaign?.tags || []).map((tag) => (
											<Badge key={tag} color="zinc">
												{tag}
											</Badge>
										))}
									</div>
									<div className="mt-3 grid grid-cols-2 gap-3">
										<Field
											label="Lead score"
											value={drawer.activecampaign?.lead_score}
										/>
										<Field
											label="Listas"
											value={(drawer.activecampaign?.lists || []).join(", ")}
										/>
									</div>
								</Section>

								<Section title="IA">
									<div className="rounded-xl border border-violet-200 bg-violet-50/70 p-4 dark:border-violet-900 dark:bg-violet-950/30">
										<div className="flex items-center justify-between gap-3">
											<p className="text-sm font-semibold text-violet-900 dark:text-violet-200">
												Probabilidad IA
											</p>
											<p className="text-2xl font-semibold tabular-nums text-violet-700 dark:text-violet-300">
												{ai?.probability ?? 0}%
											</p>
										</div>
										<p className="mt-3 text-sm leading-relaxed text-violet-900/80 dark:text-violet-200/80">
											{ai?.summary}
										</p>
										<ul className="mt-3 space-y-1.5">
											{(ai?.bullets || []).map((bullet, index) => (
												<li
													key={index}
													className="text-xs text-violet-800 dark:text-violet-200"
												>
													• {bullet}
												</li>
											))}
										</ul>
										<p className="mt-3 text-xs font-medium text-violet-700 dark:text-violet-300">
											{ai?.recommended_action}
										</p>
									</div>
								</Section>
							</>
						) : null}
					</div>

					<div className="flex gap-2 border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
						{general?.show_url ? (
							<Button href={general.show_url} className="flex-1">
								Abrir ficha completa
							</Button>
						) : null}
						<Button outline className="flex-1" onClick={onClose}>
							Cerrar
						</Button>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
