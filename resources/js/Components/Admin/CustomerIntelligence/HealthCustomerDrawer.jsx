import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

const PROB_LABELS = {
	purchase: "Compra",
	churn: "Abandono",
	email_response: "Responder email",
	whatsapp_response: "Responder WhatsApp",
	membership: "Membresía",
	laboratory: "Laboratorio",
	pharmacy: "Farmacia",
};

export default function HealthCustomerDrawer({ open, drawer, loading = false, onClose }) {
	const summary = drawer?.summary;
	const health = drawer?.health;

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 backdrop-blur-[1px]" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-2xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-famedic-light">
								Customer Health
							</p>
							<Headless.DialogTitle className="mt-1 text-lg font-semibold text-zinc-900 dark:text-zinc-50">
								{summary?.name || (loading ? "Cargando…" : "Cliente")}
							</Headless.DialogTitle>
							{summary?.email ? (
								<Text className="mt-0.5 text-xs text-zinc-500">{summary.email}</Text>
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

						{health ? (
							<>
								<div className="rounded-xl border border-violet-200 bg-violet-50/70 p-4 dark:border-violet-900 dark:bg-violet-950/30">
									<div className="flex items-end justify-between gap-3">
										<div>
											<p className="text-xs uppercase text-violet-600 dark:text-violet-300">
												Health Score
											</p>
											<p className="text-3xl font-semibold tabular-nums text-violet-900 dark:text-violet-100">
												{health.health_score}
											</p>
										</div>
										<Badge color="zinc">{health.band_label}</Badge>
									</div>
									<p className="mt-3 text-sm leading-relaxed text-violet-900/80 dark:text-violet-200/80">
										{drawer.ai_summary}
									</p>
								</div>

								<section>
									<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
										Señales
									</h3>
									<div className="grid gap-3 sm:grid-cols-2">
										<div>
											<p className="mb-1 text-[11px] text-emerald-600">Positivas</p>
											<ul className="space-y-1">
												{(health.positive_signals || []).map((s) => (
													<li key={s} className="text-xs text-zinc-600 dark:text-zinc-300">
														✔ {s}
													</li>
												))}
											</ul>
										</div>
										<div>
											<p className="mb-1 text-[11px] text-rose-600">Negativas</p>
											<ul className="space-y-1">
												{(health.negative_signals || []).length ? (
													health.negative_signals.map((s) => (
														<li key={s} className="text-xs text-zinc-600 dark:text-zinc-300">
															• {s}
														</li>
													))
												) : (
													<li className="text-xs text-zinc-400">Sin alertas fuertes</li>
												)}
											</ul>
										</div>
									</div>
								</section>

								<section>
									<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
										IA predictiva
									</h3>
									<div className="grid grid-cols-2 gap-2">
										{Object.entries(health.probabilities || {}).map(([key, value]) => (
											<div
												key={key}
												className="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/60"
											>
												<p className="text-[11px] text-zinc-400">
													{PROB_LABELS[key] || key}
												</p>
												<p className="text-sm font-semibold tabular-nums">{value}%</p>
											</div>
										))}
									</div>
								</section>

								<section>
									<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
										Timeline
									</h3>
									<ol className="space-y-3">
										{(drawer.timeline || []).map((event, index) => (
											<li key={`${event.label}-${index}`} className="flex gap-3">
												<span className="mt-1 size-2.5 shrink-0 rounded-full bg-sky-500" />
												<div>
													<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
														{event.label}
													</p>
													<p className="text-xs text-zinc-400">{event.at}</p>
													{event.detail ? (
														<p className="text-xs text-zinc-500">{event.detail}</p>
													) : null}
												</div>
											</li>
										))}
									</ol>
								</section>

								<section>
									<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
										Compras / LTV
									</h3>
									<div className="grid grid-cols-2 gap-2 text-sm">
										<p>Lab: {drawer.purchases?.lab ?? 0}</p>
										<p>Farmacia: {drawer.purchases?.pharmacy ?? 0}</p>
										<p>Membresía: {drawer.purchases?.membership ?? 0}</p>
										<p className="font-semibold">{drawer.purchases?.ltv_formatted}</p>
									</div>
								</section>

								<section>
									<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
										Productos / labs favoritos
									</h3>
									{(drawer.favorites || []).length ? (
										<ul className="space-y-1">
											{drawer.favorites.map((fav) => (
												<li
													key={fav.name}
													className="flex justify-between text-xs text-zinc-600 dark:text-zinc-300"
												>
													<span>{fav.name}</span>
													<span>{fav.count}</span>
												</li>
											))}
										</ul>
									) : (
										<p className="text-xs text-zinc-400">Sin favoritos detectados</p>
									)}
								</section>

								<section>
									<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
										Campañas / tags
									</h3>
									<p className="mb-2 text-xs text-zinc-400">{drawer.campaigns?.note}</p>
									<div className="flex flex-wrap gap-1.5">
										{(drawer.campaigns?.tags || []).map((tag) => (
											<Badge key={tag} color="zinc">
												{tag}
											</Badge>
										))}
									</div>
								</section>

								<section>
									<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
										Automatizaciones sugeridas
									</h3>
									<ul className="space-y-1.5">
										{(drawer.automations || []).map((action) => (
											<li
												key={action}
												className="rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-300"
											>
												{action}
											</li>
										))}
									</ul>
								</section>
							</>
						) : null}
					</div>

					<div className="flex gap-2 border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
						{summary?.show_url ? (
							<Button href={summary.show_url} className="flex-1">
								Abrir ficha
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
