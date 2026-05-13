import {
	Dropdown,
	DropdownButton,
	DropdownDivider,
	DropdownItem,
	DropdownLabel,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import {
	NavbarItem,
} from "@/Components/Catalyst/navbar";
import { router } from "@inertiajs/react";
import { BellIcon } from "@heroicons/react/20/solid";

export default function NotificationBell({ feed }) {
	if (!feed) {
		return null;
	}

	const markRead = (id) => {
		router.post(
			route("in-app-notifications.read", id),
			{},
			{ preserveScroll: true },
		);
	};

	const markAll = () => {
		router.post(
			route("in-app-notifications.read-all"),
			{},
			{ preserveScroll: true },
		);
	};

	return (
		<Dropdown>
			<DropdownButton
				as={NavbarItem}
				className="relative !px-2"
				aria-label="Notificaciones"
			>
				<BellIcon className="size-6 text-zinc-700 dark:text-zinc-200" />
				{feed.unreadCount > 0 && (
					<span className="absolute -right-0.5 -top-0.5 flex size-4 items-center justify-center rounded-full bg-famedic-lime text-[10px] font-bold text-famedic-darker">
						{feed.unreadCount > 9 ? "9+" : feed.unreadCount}
					</span>
				)}
			</DropdownButton>
			<DropdownMenu anchor="bottom end" className="max-h-96 w-[22rem] overflow-y-auto">
				{feed.items.length === 0 && (
					<DropdownItem href="#" onClick={(e) => e.preventDefault()}>
						<DropdownLabel className="text-zinc-500">
							Sin notificaciones
						</DropdownLabel>
					</DropdownItem>
				)}
				{feed.items.map((n) => (
					<DropdownItem
						key={n.id}
						href="#"
						onClick={(e) => {
							e.preventDefault();
							if (!n.is_read) markRead(n.id);
						}}
					>
						<div
							className={
								n.is_read ? "opacity-70" : "font-medium"
							}
						>
							<DropdownLabel>{n.title}</DropdownLabel>
							<p className="mt-0.5 text-xs font-normal text-zinc-600 dark:text-zinc-400">
								{n.message}
							</p>
						</div>
					</DropdownItem>
				))}
				{feed.unreadCount > 0 && (
					<>
						<DropdownDivider />
						<DropdownItem
							href="#"
							onClick={(e) => {
								e.preventDefault();
								markAll();
							}}
						>
							<DropdownLabel>Marcar todas como leídas</DropdownLabel>
						</DropdownItem>
					</>
				)}
			</DropdownMenu>
		</Dropdown>
	);
}
