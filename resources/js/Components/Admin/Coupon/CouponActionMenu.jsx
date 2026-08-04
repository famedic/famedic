import { Menu, MenuButton, MenuItem, MenuItems } from "@headlessui/react";
import { EllipsisVerticalIcon } from "@heroicons/react/16/solid";
import { Link } from "@/Components/Catalyst/link";

export default function CouponActionMenu({ items = [] }) {
	const visible = items.filter((item) => item && !item.hidden);
	if (visible.length === 0) return null;

	return (
		<Menu as="div" className="relative inline-block text-left">
			<MenuButton
				type="button"
				className="inline-flex items-center justify-center rounded-lg p-2 text-zinc-500 ring-1 ring-zinc-200 transition hover:bg-zinc-50 hover:text-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime dark:text-zinc-400 dark:ring-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-white"
				aria-label="Acciones"
			>
				<EllipsisVerticalIcon className="size-5" />
			</MenuButton>
			<MenuItems
				anchor="bottom end"
				className="z-30 mt-1 min-w-44 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg ring-1 ring-black/5 focus:outline-none dark:border-zinc-600 dark:bg-zinc-900 dark:ring-white/10"
			>
				{visible.map((item) => (
					<MenuItem key={item.key ?? item.label} disabled={item.disabled}>
						{({ focus, disabled }) =>
							item.href ? (
								<Link
									href={item.href}
									title={item.title}
									className={[
										"block rounded-md px-3 py-2 text-sm",
										disabled
											? "cursor-not-allowed text-zinc-400"
											: focus
												? "bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white"
												: "text-zinc-700 dark:text-zinc-200",
										item.danger ? "text-red-600 dark:text-red-400" : "",
									].join(" ")}
								>
									{item.label}
								</Link>
							) : (
								<button
									type="button"
									disabled={disabled || item.disabled}
									onClick={item.onClick}
									title={item.title}
									className={[
										"block w-full rounded-md px-3 py-2 text-left text-sm",
										disabled || item.disabled
											? "cursor-not-allowed text-zinc-400"
											: focus
												? "bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white"
												: "text-zinc-700 dark:text-zinc-200",
										item.danger ? "text-red-600 dark:text-red-400" : "",
									].join(" ")}
								>
									{item.label}
								</button>
							)
						}
					</MenuItem>
				))}
			</MenuItems>
		</Menu>
	);
}
