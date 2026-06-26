import { useState } from "react";
import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	HeartIcon,
	SparklesIcon,
} from "@heroicons/react/24/solid";
import {
	ClipboardDocumentIcon,
	CheckIcon,
	PhoneIcon,
} from "@heroicons/react/24/outline";
import MembershipProgress from "@/Components/Membership/MembershipProgress";

function HeroStat({ label, children, action }) {
	return (
		<div className="rounded-2xl bg-white/10 px-3 py-3 backdrop-blur sm:px-4 sm:py-3.5">
			<div className="flex items-start justify-between gap-2">
				<Text className="text-[10px] font-medium uppercase tracking-wide text-white/50 sm:text-xs">
					{label}
				</Text>
				{action}
			</div>
			<div className="mt-1.5 min-w-0">{children}</div>
		</div>
	);
}

export default function MembershipHero({
	status,
	progress,
	access,
	plan,
	capabilities,
	onShowBenefits,
}) {
	const [copied, setCopied] = useState(false);
	const isActive = status?.status === "active";
	const renewalDate = plan?.renewalDate ?? status?.endDate;

	const copyIdentifier = async () => {
		if (!access?.identifier) return;

		try {
			await navigator.clipboard.writeText(access.identifier);
			setCopied(true);
			window.setTimeout(() => setCopied(false), 2000);
		} catch {
			setCopied(false);
		}
	};

	return (
		<Card className="overflow-hidden rounded-[20px] shadow-lg ring-1 ring-slate-100">
			<div className="bg-gradient-to-br from-famedic-dark via-famedic-dark to-violet-900 px-5 py-7 text-white sm:px-8 sm:py-9">
				<div className="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
					<div className="min-w-0 flex-1 space-y-5">
						<div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
							<div className="flex min-w-0 items-start gap-4">
								<div className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 backdrop-blur">
									<HeartIcon className="size-6 text-rose-300" />
								</div>
								<div className="min-w-0">
									<Text className="text-sm text-white/60">
										Tu plan
									</Text>
									<h2 className="font-poppins text-2xl font-semibold tracking-tight sm:text-3xl">
										{status?.title}
									</h2>
									<div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-white/70">
										<span>Vigencia</span>
										<span className="font-medium text-white">
											{status?.startDate ?? "—"}
										</span>
										<span className="text-white/40">→</span>
										<span className="font-medium text-white">
											{status?.endDate ?? "—"}
										</span>
									</div>
								</div>
							</div>

							{progress && (
								<div className="flex shrink-0 justify-center sm:justify-end xl:hidden">
									<MembershipProgress progress={progress} size="sm" />
								</div>
							)}
						</div>

						<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
							<HeroStat
								label="ID de membresía"
								action={
									access?.identifier ? (
										<button
											type="button"
											onClick={copyIdentifier}
											className="inline-flex items-center gap-1 text-[11px] font-medium text-white/80 transition hover:text-white"
											aria-label="Copiar ID de membresía"
										>
											{copied ? (
												<CheckIcon className="size-3.5 text-emerald-300" />
											) : (
												<ClipboardDocumentIcon className="size-3.5" />
											)}
											{copied ? "Copiado" : "Copiar"}
										</button>
									) : null
								}
							>
								<p className="font-poppins text-lg font-bold leading-tight tracking-tight sm:text-xl">
									{access?.identifier ?? "—"}
								</p>
							</HeroStat>

							<HeroStat
								label="Línea médica"
								action={
									access?.telHref ? (
										<a
											href={access.telHref}
											className="relative inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[11px] font-semibold text-emerald-300 no-underline motion-safe:animate-call-invite transition hover:bg-white/15 hover:text-white hover:animate-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300/80"
										>
											<PhoneIcon className="size-3.5 shrink-0" />
											Llamar
										</a>
									) : null
								}
							>
								<p className="font-poppins text-base font-semibold leading-tight sm:text-lg">
									{access?.formattedPhone ?? "—"}
								</p>
								{access?.lineLabel && (
									<Text className="mt-0.5 text-[11px] leading-snug text-white/55 sm:text-xs">
										{access.lineLabel}
									</Text>
								)}
							</HeroStat>
						</div>

						<div className="flex flex-wrap items-center gap-x-5 gap-y-3">
							<div className="flex flex-wrap gap-3">
								<Button
									type="button"
									onClick={onShowBenefits}
									className="!bg-white/15 !text-white hover:!bg-white/25"
								>
									<SparklesIcon className="size-4" />
									Ver beneficios
								</Button>
								{status?.canRenew && capabilities?.canRenew && (
									<Button
										href={status.renewUrl}
										className="!bg-white !text-famedic-dark hover:!bg-white/90"
									>
										Renovar membresía
									</Button>
								)}
							</div>

							{(isActive || renewalDate) && (
								<div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
									{isActive && (
										<span className="inline-flex items-center gap-2 font-medium text-white">
											<span
												className="size-2.5 shrink-0 rounded-full bg-emerald-400 motion-safe:animate-member-status-pulse"
												aria-hidden="true"
											/>
											Activa
										</span>
									)}
									{renewalDate && (
										<span className="text-white/70">
											<span className="text-white/50">
												Próxima renovación{" "}
											</span>
											<span className="font-medium text-white">
												{renewalDate}
											</span>
											{plan?.paymentType && (
												<span className="text-white/50">
													{" "}
													· {plan.paymentType}
												</span>
											)}
										</span>
									)}
								</div>
							)}
						</div>
					</div>

					{progress && (
						<div className="hidden shrink-0 justify-center xl:flex xl:justify-end">
							<MembershipProgress progress={progress} />
						</div>
					)}
				</div>
			</div>
		</Card>
	);
}
