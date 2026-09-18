import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ChevronDoubleRightIcon } from "@heroicons/react/16/solid";

export default function StickyCheckoutBar({
	formattedTotal,
	checkoutUrl,
	onCheckoutClick,
	disabled = false,
}) {
	return (
		<div className="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 shadow-[0_-4px_16px_rgba(0,0,0,0.08)] backdrop-blur-sm lg:hidden dark:border-slate-800 dark:bg-slate-950/95">
			<div className="mx-auto flex max-w-lg items-end justify-between gap-4">
				<div>
					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						Total
					</Text>
					<Strong className="text-xl text-famedic-dark dark:text-white">
						{formattedTotal}
					</Strong>
				</div>
				<Button
					href={checkoutUrl}
					onClick={onCheckoutClick}
					disabled={disabled}
					className="min-h-11 flex-1 !py-3 sm:max-w-xs"
				>
					Continuar
					<ChevronDoubleRightIcon />
				</Button>
			</div>
		</div>
	);
}
