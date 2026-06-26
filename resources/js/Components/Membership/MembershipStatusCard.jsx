import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { HeartIcon, ArrowDownIcon } from "@heroicons/react/24/solid";

const STATUS_STYLES = {
	active: {
		badge: "emerald",
		dot: "bg-emerald-500",
		label: "Activa",
	},
	expired: {
		badge: "zinc",
		dot: "bg-zinc-400",
		label: "Expirada",
	},
	none: {
		badge: "zinc",
		dot: "bg-zinc-300",
		label: "Sin membresía",
	},
};

export default function MembershipStatusCard({ status, capabilities }) {
	const style = STATUS_STYLES[status?.status] ?? STATUS_STYLES.none;

	return (
		<Card className="overflow-hidden shadow-sm ring-1 ring-slate-100">
			<div className="bg-gradient-to-br from-famedic-dark via-famedic-dark to-violet-900 px-6 py-8 text-white sm:px-8 sm:py-10">
				<div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
					<div className="space-y-4">
						<div className="flex items-center gap-3">
							<div className="flex size-11 items-center justify-center rounded-2xl bg-white/10 backdrop-blur">
								<HeartIcon className="size-6 text-rose-300" />
							</div>
							<div>
								<Text className="text-sm text-white/70">
									Tu plan
								</Text>
								<h2 className="font-poppins text-2xl font-semibold tracking-tight sm:text-3xl">
									{status?.title}
								</h2>
							</div>
						</div>

						<div className="flex flex-wrap items-center gap-3">
							<Text className="text-sm text-white/70">
								Estado
							</Text>
							<span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-sm font-medium">
								<span
									className={`size-2 rounded-full ${style.dot}`}
								/>
								{status?.statusLabel}
							</span>
						</div>
					</div>

					{status?.status !== "none" && (
						<div className="grid gap-4 sm:grid-cols-3 lg:min-w-[28rem]">
							<div className="rounded-2xl bg-white/10 p-4 backdrop-blur">
								<Text className="text-xs uppercase tracking-wide text-white/60">
									Vigencia
								</Text>
								<p className="mt-2 font-poppins text-lg font-semibold">
									{status?.startDate ?? "—"}
								</p>
								<div className="my-2 flex justify-center">
									<ArrowDownIcon className="size-4 text-white/50" />
								</div>
								<p className="font-poppins text-lg font-semibold">
									{status?.endDate ?? "—"}
								</p>
							</div>

							<div className="rounded-2xl bg-white/10 p-4 backdrop-blur sm:col-span-2">
								<Text className="text-xs uppercase tracking-wide text-white/60">
									Quedan
								</Text>
								<p className="mt-2 font-poppins text-4xl font-bold leading-none">
									{status?.remainingDays ?? 0}
								</p>
								<Text className="mt-1 text-sm text-white/70">
									días
								</Text>
							</div>
						</div>
					)}
				</div>

				{status?.canRenew && capabilities?.canRenew && (
					<div className="mt-6 flex flex-wrap gap-3">
						<Button
							href={status.renewUrl}
							className="!bg-white !text-famedic-dark hover:!bg-white/90"
						>
							Renovar membresía
						</Button>
					</div>
				)}
			</div>
		</Card>
	);
}
