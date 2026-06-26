import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	ArrowDownTrayIcon,
	HeartIcon,
	SparklesIcon,
} from "@heroicons/react/24/solid";
import MembershipProgress from "@/Components/Membership/MembershipProgress";
import clsx from "clsx";

const STATUS_STYLES = {
	active: {
		dot: "bg-emerald-400",
		label: "Activa",
		pill: "bg-emerald-500/20 text-emerald-100",
	},
	expired: {
		dot: "bg-zinc-400",
		label: "Expirada",
		pill: "bg-white/10 text-white/80",
	},
	none: {
		dot: "bg-zinc-300",
		label: "Sin membresía",
		pill: "bg-white/10 text-white/80",
	},
};

export default function MembershipHero({
	status,
	progress,
	capabilities,
	onShowBenefits,
}) {
	const style = STATUS_STYLES[status?.status] ?? STATUS_STYLES.none;

	return (
		<Card className="overflow-hidden rounded-[20px] shadow-lg ring-1 ring-slate-100">
			<div className="bg-gradient-to-br from-famedic-dark via-famedic-dark to-violet-900 px-5 py-7 text-white sm:px-8 sm:py-9">
				<div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
					<div className="min-w-0 flex-1 space-y-5">
						<div className="flex items-start gap-4">
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
							</div>
						</div>

						<div className="flex flex-wrap items-center gap-3">
							<span
								className={clsx(
									"inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium",
									style.pill,
								)}
							>
								<span
									className={clsx("size-2 rounded-full", style.dot)}
								/>
								{status?.statusLabel}
							</span>
						</div>

						<div className="grid gap-3 sm:grid-cols-2">
							<div className="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur">
								<Text className="text-xs uppercase tracking-wide text-white/50">
									Inicio
								</Text>
								<p className="mt-1 font-poppins text-base font-semibold">
									{status?.startDate ?? "—"}
								</p>
							</div>
							<div className="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur">
								<Text className="text-xs uppercase tracking-wide text-white/50">
									Fin
								</Text>
								<p className="mt-1 font-poppins text-base font-semibold">
									{status?.endDate ?? "—"}
								</p>
							</div>
						</div>

						<div className="flex flex-wrap gap-3">
							<Button
								type="button"
								onClick={onShowBenefits}
								className="!bg-white/15 !text-white hover:!bg-white/25"
							>
								<SparklesIcon className="size-4" />
								Ver beneficios
							</Button>
							{capabilities?.canDownloadReceipt && (
								<Button
									outline
									disabled={!capabilities.receiptDownloadUrl}
									href={capabilities.receiptDownloadUrl ?? undefined}
									className="!border-white/25 !text-white hover:!bg-white/10"
								>
									<ArrowDownTrayIcon className="size-4" />
									Descargar comprobante
								</Button>
							)}
							{status?.canRenew && capabilities?.canRenew && (
								<Button
									href={status.renewUrl}
									className="!bg-white !text-famedic-dark hover:!bg-white/90"
								>
									Renovar membresía
								</Button>
							)}
						</div>
					</div>

					{progress && (
						<div className="flex shrink-0 justify-center lg:justify-end">
							<MembershipProgress progress={progress} />
						</div>
					)}
				</div>
			</div>
		</Card>
	);
}
