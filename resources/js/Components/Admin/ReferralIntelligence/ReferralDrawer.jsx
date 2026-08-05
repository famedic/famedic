import * as Headless from "@headlessui/react";
import { useState } from "react";
import {
	XMarkIcon,
	ClipboardDocumentIcon,
	CheckIcon,
} from "@heroicons/react/16/solid";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import ReferralBadge from "./ReferralBadge";
import ReferralTimeline from "./ReferralTimeline";
import ReferralStats from "./ReferralStats";

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
			<p className="mt-0.5 break-all text-sm text-zinc-800 dark:text-zinc-100">
				{value || "—"}
			</p>
		</div>
	);
}

export default function ReferralDrawer({ open, drawer, loading = false, onClose }) {
	const [copied, setCopied] = useState(false);
	const general = drawer?.general;
	const links = drawer?.links || {};

	const copyLink = async () => {
		if (!general?.invitation_url) {
			return;
		}
		try {
			await navigator.clipboard.writeText(general.invitation_url);
			setCopied(true);
			setTimeout(() => setCopied(false), 1800);
		} catch {
			// ignore
		}
	};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40 backdrop-blur-[1px]" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-2xl dark:bg-zinc-900">
					<div className="flex items-start justify-between gap-3 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div className="flex items-start gap-3">
							<Avatar src={general?.avatar} className="size-12" />
							<div>
								<p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-famedic-light">
									Referral 360
								</p>
								<Headless.DialogTitle className="mt-1 text-lg font-semibold text-zinc-900 dark:text-zinc-50">
									{general?.name || (loading ? "Cargando…" : "Invitador")}
								</Headless.DialogTitle>
								{general?.email ? (
									<Text className="mt-0.5 text-xs text-zinc-500">{general.email}</Text>
								) : null}
								{drawer?.level ? (
									<div className="mt-2">
										<ReferralBadge
											tone={drawer.level.key}
											label={drawer.level.label}
											medal={drawer.level.medal}
										/>
									</div>
								) : null}
							</div>
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

						<Section title="Información general">
							<div className="grid grid-cols-2 gap-3">
								<Field label="Correo" value={general?.email} />
								<Field label="Teléfono" value={general?.phone} />
								<Field label="Empresa" value={general?.company} />
								<Field label="Fecha registro" value={general?.registered_at} />
								<Field label="Tipo de cuenta" value={general?.account_type} />
								<Field label="Código de referido" value={general?.referral_code} />
							</div>
							<div className="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
								<p className="text-[11px] uppercase tracking-wide text-zinc-400">
									Link de invitación
								</p>
								<p className="mt-1 break-all text-xs text-zinc-600 dark:text-zinc-300">
									{general?.invitation_url || "—"}
								</p>
								<Button outline className="mt-3" onClick={copyLink}>
									{copied ? (
										<>
											<CheckIcon className="size-4" />
											Copiado
										</>
									) : (
										<>
											<ClipboardDocumentIcon className="size-4" />
											Copiar link
										</>
									)}
								</Button>
							</div>
						</Section>

						<Section title="Timeline">
							<ReferralTimeline items={drawer?.timeline || []} />
						</Section>

						<Section title="Analytics">
							<ReferralStats items={drawer?.analytics || []} />
						</Section>

						<Section title="Lista de referidos">
							<div className="space-y-2">
								{(drawer?.referrals || []).length === 0 ? (
									<p className="text-sm text-zinc-400">Sin referidos en el periodo.</p>
								) : (
									(drawer?.referrals || []).map((ref) => (
										<a
											key={ref.id}
											href={ref.customer_url}
											className="flex items-center gap-3 rounded-xl border border-zinc-200 px-3 py-2 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/60"
										>
											<Avatar src={ref.avatar} className="size-9" />
											<div className="min-w-0 flex-1">
												<p className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-50">
													{ref.name}
												</p>
												<p className="text-xs text-zinc-500">
													{ref.registered_at} · {ref.amount_formatted}
												</p>
											</div>
											<div className="text-right">
												<ReferralBadge tone={ref.status} label={ref.status_label} />
												<p className="mt-1 text-[10px] text-zinc-400">
													Health {ref.health_score}
												</p>
											</div>
										</a>
									))
								)}
							</div>
						</Section>

						<Section title="Customer Intelligence">
							<div className="flex flex-wrap gap-2">
								{links.customer_360 ? (
									<Button href={links.customer_360} outline>
										Customer 360
									</Button>
								) : null}
								{links.customer_journey ? (
									<Button href={links.customer_journey} outline>
										Customer Journey
									</Button>
								) : null}
								{links.customer_health ? (
									<Button href={links.customer_health} outline>
										Customer Health
									</Button>
								) : null}
								{links.dormant ? (
									<Button href={links.dormant} outline>
										Clientes Dormidos
									</Button>
								) : null}
							</div>
						</Section>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
