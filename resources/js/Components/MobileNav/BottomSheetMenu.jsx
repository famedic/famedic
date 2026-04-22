import * as Headless from "@headlessui/react";
import { Link } from "@inertiajs/react";
import { XMarkIcon } from "@heroicons/react/20/solid";
import {
	UserCircleIcon,
	ShoppingBagIcon,
	CreditCardIcon,
	MapPinIcon,
	CommandLineIcon,
	UsersIcon,
	IdentificationIcon,
	BuildingLibraryIcon,
	PhoneIcon,
	Cog6ToothIcon,
	LifebuoyIcon,
} from "@heroicons/react/24/outline";
import { useCallback, useRef } from "react";

const iconMap = {
	UserCircleIcon,
	ShoppingBagIcon,
	CreditCardIcon,
	MapPinIcon,
	CommandLineIcon,
	UsersIcon,
	IdentificationIcon,
	BuildingLibraryIcon,
	PhoneIcon,
	Cog6ToothIcon,
	LifebuoyIcon,
};

function SheetRow({ item, onNavigate }) {
	const Icon = item.icon && iconMap[item.icon] ? iconMap[item.icon] : LifebuoyIcon;
	const isExternal = item.url?.startsWith("tel:") || item.url?.startsWith("mailto:");

	const content = (
		<>
			<span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-700 dark:bg-slate-800 dark:text-slate-200">
				<Icon className="size-5" />
			</span>
			<span className="min-w-0 flex-1 text-left text-sm font-medium text-zinc-900 dark:text-white">
				{item.label}
			</span>
		</>
	);

	if (isExternal) {
		return (
			<a
				href={item.url}
				className="flex min-w-0 items-center gap-3 rounded-xl px-3 py-3 transition hover:bg-zinc-50 active:bg-zinc-100 dark:hover:bg-slate-800/80 dark:active:bg-slate-800"
				onClick={onNavigate}
			>
				{content}
			</a>
		);
	}

	return (
		<Link
			href={item.url}
			className="flex min-w-0 items-center gap-3 rounded-xl px-3 py-3 transition hover:bg-zinc-50 active:bg-zinc-100 dark:hover:bg-slate-800/80 dark:active:bg-slate-800"
			onClick={onNavigate}
		>
			{content}
		</Link>
	);
}

/**
 * Bottom sheet con lista de enlaces (permisos ya filtrados en servidor vía userNavigation + extras).
 */
export default function BottomSheetMenu({ open, onClose, items }) {
	const startY = useRef(null);
	const dragging = useRef(false);

	const onTouchStart = useCallback((e) => {
		startY.current = e.touches[0]?.clientY ?? null;
		dragging.current = true;
	}, []);

	const onTouchEnd = useCallback(
		(e) => {
			if (!dragging.current || startY.current == null) return;
			dragging.current = false;
			const endY = e.changedTouches[0]?.clientY;
			if (endY != null && endY - startY.current > 56) {
				onClose();
			}
			startY.current = null;
		},
		[onClose],
	);

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-[60] lg:hidden">
			<Headless.DialogBackdrop
				transition
				className="fixed inset-0 bg-black/40 transition data-[closed]:opacity-0 data-[enter]:duration-300 data-[leave]:duration-200 data-[enter]:ease-out data-[leave]:ease-in"
			/>
			<div className="fixed inset-0 flex items-end justify-center p-0 sm:p-4">
				<Headless.DialogPanel
					transition
					className="max-h-[min(88vh,640px)] w-full max-w-lg rounded-t-2xl bg-white shadow-2xl ring-1 ring-zinc-950/5 transition data-[closed]:translate-y-full data-[closed]:opacity-95 data-[enter]:duration-300 data-[leave]:duration-200 data-[enter]:ease-out data-[leave]:ease-in dark:bg-slate-900 dark:ring-white/10 sm:rounded-2xl"
				>
					<div
						className="sticky top-0 z-10 border-b border-zinc-100 bg-white px-4 pb-3 pt-2 dark:border-slate-800 dark:bg-slate-900 sm:rounded-t-2xl"
						onTouchStart={onTouchStart}
						onTouchEnd={onTouchEnd}
					>
						<div
							className="mx-auto mb-2 h-1 w-10 shrink-0 rounded-full bg-zinc-300 dark:bg-slate-600"
							aria-hidden
						/>
						<div className="flex items-center justify-between gap-2">
						<Headless.DialogTitle className="text-base font-semibold text-zinc-900 dark:text-white">
							Más opciones
						</Headless.DialogTitle>
						<Headless.CloseButton
							type="button"
							aria-label="Cerrar menú"
							className="rounded-xl p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
						>
							<XMarkIcon className="size-6" />
						</Headless.CloseButton>
						</div>
					</div>
					<div
						className="mx-1 mb-[max(0.75rem,env(safe-area-inset-bottom))] mt-2 max-h-[min(72vh,520px)] overflow-y-auto overscroll-y-contain px-2 pb-4"
						role="list"
					>
						<div className="grid grid-cols-1 gap-1">
							{items.map((item) => (
								<SheetRow key={`${item.label}-${item.url}`} item={item} onNavigate={onClose} />
							))}
						</div>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}
