import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { ExclamationTriangleIcon } from "@heroicons/react/24/solid";

export default function MembershipRenewBanner({ renewal }) {
	if (!renewal?.showBanner) {
		return null;
	}

	return (
		<div className="rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50 p-5 shadow-sm dark:border-amber-500/20 dark:from-amber-500/10 dark:to-orange-500/10 sm:p-6">
			<div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
				<div className="flex items-start gap-3">
					<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300">
						<ExclamationTriangleIcon className="size-5" />
					</div>
					<div>
						<p className="font-poppins font-semibold text-amber-900 dark:text-amber-100">
							Tu membresía vence en {renewal.daysRemaining} días
						</p>
						<Text className="mt-1 text-sm text-amber-800/80 dark:text-amber-100/80">
							Renueva hoy para no perder tus beneficios.
						</Text>
					</div>
				</div>

				<Button href={renewal.renewUrl} className="w-full sm:w-auto">
					Renovar ahora
				</Button>
			</div>
		</div>
	);
}
