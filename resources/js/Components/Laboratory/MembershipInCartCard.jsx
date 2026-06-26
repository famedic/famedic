import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { useRemoveLaboratoryCartMembership } from "@/Hooks/useRemoveLaboratoryCartMembership";
import {
	CheckCircleIcon,
	ShieldCheckIcon,
	TrashIcon,
} from "@heroicons/react/24/solid";

export default function MembershipInCartCard({
	laboratoryBrand,
	formattedMembershipPrice,
	membershipCrossSell,
}) {
	const { isRemoving, error, removeMembership } = useRemoveLaboratoryCartMembership(
		laboratoryBrand,
	);

	const displayPrice =
		formattedMembershipPrice || membershipCrossSell?.formattedPrice || "$300.00";

	const displayAmount = membershipCrossSell?.priceCents
		? Math.round(membershipCrossSell.priceCents / 100)
		: displayPrice.replace(/[^0-9]/g, "");

	const handleRemoveMembership = () => {
		removeMembership();
	};

	return (
		<div className="mt-6 transition-all duration-300 sm:mt-8">
			<Card className="overflow-hidden border border-emerald-100 bg-emerald-50/40 shadow-sm ring-1 ring-emerald-100 dark:border-emerald-500/20 dark:bg-emerald-500/5 dark:ring-emerald-500/20">
				<div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:p-5">
					<div className="flex min-w-0 flex-1 items-start gap-3">
						<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
							<CheckCircleIcon className="size-5" />
						</div>

						<div className="min-w-0 space-y-1.5">
							<Badge color="emerald" className="w-fit px-2 py-0.5 text-xs">
								<ShieldCheckIcon className="size-3.5" />
								Agregada al carrito
							</Badge>

							<h3 className="font-poppins text-base font-semibold leading-snug text-famedic-dark sm:text-lg dark:text-white">
								Membresía Médica Anual
							</h3>

							<Text className="text-sm text-zinc-600 dark:text-slate-300">
								Atención médica familiar 24/7 por un año.
							</Text>
						</div>
					</div>

					<div className="flex shrink-0 flex-col gap-3 border-t border-emerald-100 pt-4 sm:items-end sm:border-t-0 sm:pt-0 sm:text-right dark:border-emerald-500/20">
						<div className="flex items-baseline gap-1 sm:flex-col sm:items-end sm:gap-0">
							<span className="font-poppins text-xl font-bold leading-none text-famedic-dark dark:text-white">
								${displayAmount} MXN
							</span>
							<Text className="text-sm text-zinc-500 dark:text-slate-400">
								/ año
							</Text>
						</div>

						<button
							type="button"
							onClick={handleRemoveMembership}
							disabled={isRemoving}
							className="inline-flex items-center justify-center gap-1.5 text-sm font-medium text-red-600 underline-offset-2 transition-colors hover:text-red-700 hover:underline disabled:opacity-50 dark:text-red-400 dark:hover:text-red-300"
						>
							<TrashIcon className="size-4" />
							{isRemoving ? "Quitando..." : "Quitar membresía"}
						</button>
						{error && (
							<Text className="text-xs text-red-600 dark:text-red-400">
								{error}
							</Text>
						)}
					</div>
				</div>
			</Card>
		</div>
	);
}
