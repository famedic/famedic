import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/20/solid";

export default function BranchBottomSheet({
	open,
	onClose,
	title = "Sucursal de preferencia",
	children,
}) {
	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-[60] lg:hidden">
			<Headless.DialogBackdrop
				transition
				className="fixed inset-0 bg-black/40 transition data-[closed]:opacity-0 data-[enter]:duration-300 data-[leave]:duration-200"
			/>
			<div className="fixed inset-0 flex items-end justify-center p-0">
				<Headless.DialogPanel
					transition
					className="flex max-h-[90vh] w-full flex-col rounded-t-2xl bg-white shadow-2xl ring-1 ring-zinc-950/5 transition data-[closed]:translate-y-full data-[enter]:duration-300 data-[leave]:duration-200 dark:bg-slate-900 dark:ring-white/10"
				>
					<div className="shrink-0 border-b border-zinc-100 px-4 pb-3 pt-2 dark:border-slate-800">
						<div
							className="mx-auto mb-2 h-1 w-10 rounded-full bg-zinc-300 dark:bg-slate-600"
							aria-hidden="true"
						/>
						<div className="flex items-start justify-between gap-3">
							<div>
								<Headless.DialogTitle className="text-base font-semibold text-zinc-900 dark:text-white">
									{title}
								</Headless.DialogTitle>
								<p className="mt-1 text-sm text-zinc-600 dark:text-slate-400">
									Opcional · Compatible con tus estudios
								</p>
							</div>
							<Headless.CloseButton
								type="button"
								aria-label="Cerrar"
								className="rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light dark:hover:bg-slate-800"
							>
								<XMarkIcon className="size-5" />
							</Headless.CloseButton>
						</div>
					</div>
					<div className="min-h-0 flex-1 overflow-y-auto overscroll-y-contain px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3">
						{children}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
