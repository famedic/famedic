import {
	Dropdown,
	DropdownButton,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import { NavbarItem } from "@/Components/Catalyst/navbar";
import { router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { ArrowRightIcon, BellIcon, CheckIcon } from "@heroicons/react/20/solid";
import {
	BeakerIcon,
	Cog6ToothIcon,
	DocumentTextIcon,
	MegaphoneIcon,
} from "@heroicons/react/24/outline";

const FILTERS = [
	{ id: "all", label: "Todas" },
	{ id: "unread", label: "No leídas" },
	{ id: "important", label: "Importantes" },
];

const importantTypes = new Set([
	"laboratory_preparation_summary_ready",
	"laboratory_results_available",
	"payment_confirmed",
	"appointment_scheduled",
]);

function isImportant(notification) {
	return importantTypes.has(notification.type);
}

function notificationIcon(type) {
	if (type?.includes("preparation")) return DocumentTextIcon;
	if (type?.includes("result")) return BeakerIcon;
	if (type?.includes("payment")) return CheckIcon;
	if (type?.includes("campaign") || type?.includes("promotion"))
		return MegaphoneIcon;

	return BellIcon;
}

function timeAgo(dateValue) {
	if (!dateValue) return "";

	const date = new Date(dateValue);
	if (Number.isNaN(date.getTime())) return "";

	const diffSeconds = Math.max(
		1,
		Math.floor((Date.now() - date.getTime()) / 1000),
	);
	const diffMinutes = Math.floor(diffSeconds / 60);
	const diffHours = Math.floor(diffMinutes / 60);
	const diffDays = Math.floor(diffHours / 24);

	if (diffMinutes < 1) return "Hace unos segundos";
	if (diffMinutes < 60) return `Hace ${diffMinutes} min`;
	if (diffHours < 24)
		return `Hace ${diffHours} ${diffHours === 1 ? "hora" : "horas"}`;

	return `Hace ${diffDays} ${diffDays === 1 ? "día" : "días"}`;
}

function normalizeActionUrl(actionUrl) {
	if (!actionUrl) return null;
	if (actionUrl.startsWith("/")) return actionUrl;

	try {
		const url = new URL(actionUrl, window.location.origin);
		const current = window.location;
		const equivalentLocalHost =
			["localhost", "127.0.0.1"].includes(url.hostname) &&
			["localhost", "127.0.0.1"].includes(current.hostname) &&
			url.port === current.port;

		if (url.origin === current.origin || equivalentLocalHost) {
			return `${url.pathname}${url.search}${url.hash}`;
		}
	} catch {
		return actionUrl;
	}

	return actionUrl;
}

export default function NotificationBell({ feed }) {
	const [activeFilter, setActiveFilter] = useState("all");
	const safeFeed = feed ?? { items: [], unreadCount: 0 };
	const notifications = safeFeed.items ?? [];
	const counts = useMemo(
		() => ({
			all: notifications.length,
			unread: notifications.filter((n) => !n.is_read).length,
			important: notifications.filter(isImportant).length,
		}),
		[notifications],
	);
	const visibleNotifications = useMemo(() => {
		if (activeFilter === "unread") {
			return notifications.filter((n) => !n.is_read);
		}
		if (activeFilter === "important") {
			return notifications.filter(isImportant);
		}

		return notifications;
	}, [activeFilter, notifications]);

	if (!feed) {
		return null;
	}

	const markRead = (id, actionUrl = null) => {
		router.post(
			route("in-app-notifications.read", id),
			{},
			{
				preserveScroll: true,
				onSuccess: () => {
					const target = normalizeActionUrl(actionUrl);
					if (target) router.visit(target);
				},
			},
		);
	};

	const markReadOnly = (id) => {
		router.post(
			route("in-app-notifications.read", id),
			{},
			{ preserveScroll: true },
		);
	};

	const openNotification = (notification) => {
		if (!notification.is_read) {
			markRead(notification.id, notification.action_url);
			return;
		}

		const target = normalizeActionUrl(notification.action_url);
		if (target) {
			router.visit(target);
		}
	};

	const visitNotification = (notification) => {
		const target = normalizeActionUrl(notification.action_url);
		if (!target) return;

		if (!notification.is_read) {
			markRead(notification.id, target);
			return;
		}

		router.visit(target);
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
				{safeFeed.unreadCount > 0 && (
					<span className="absolute -right-0.5 -top-0.5 flex size-4 items-center justify-center rounded-full bg-famedic-lime text-[10px] font-bold text-famedic-darker">
						{safeFeed.unreadCount > 9 ? "9+" : safeFeed.unreadCount}
					</span>
				)}
			</DropdownButton>
			<DropdownMenu
				anchor="bottom end"
				className="max-h-[calc(100vh-6rem)] w-[34rem] max-w-[calc(100vw-2rem)] overflow-y-auto !p-0"
			>
				<div className="col-span-full overflow-hidden rounded-2xl bg-white text-famedic-darker shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-950 dark:text-white dark:ring-white/10">
					<div className="flex items-center justify-between gap-3 px-4 pb-3 pt-4">
						<h2 className="text-lg font-black">Notificaciones</h2>
						<div className="flex items-center gap-3">
							{safeFeed.unreadCount > 0 && (
								<button
									type="button"
									onClick={markAll}
									className="text-xs font-semibold text-green-700 transition hover:text-green-900 dark:text-lime-300 dark:hover:text-lime-100"
								>
									Marcar todas como leídas
								</button>
							)}
							<button
								type="button"
								className="rounded-full p-1.5 text-famedic-darker transition hover:bg-zinc-100 dark:text-white dark:hover:bg-white/10"
								aria-label="Configurar notificaciones"
								title="Configurar notificaciones"
							>
								<Cog6ToothIcon className="size-5" />
							</button>
						</div>
					</div>

					<div className="grid grid-cols-3 gap-1.5 px-4 pb-3">
						{FILTERS.map((filter) => (
							<button
								key={filter.id}
								type="button"
								onClick={() => setActiveFilter(filter.id)}
								className={[
									"rounded-lg border px-3 py-2 text-sm font-bold transition",
									activeFilter === filter.id
										? "border-famedic-darker bg-famedic-darker text-white shadow-sm dark:border-lime-300 dark:bg-lime-300 dark:text-famedic-darker"
										: "border-zinc-200 bg-white text-famedic-darker hover:border-lime-200 hover:bg-lime-50 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10",
								].join(" ")}
							>
								{filter.label} ({counts[filter.id]})
							</button>
						))}
					</div>

					<div className="space-y-2 px-3 pb-3">
						{visibleNotifications.length === 0 && (
							<div className="rounded-xl border border-dashed border-zinc-200 px-4 py-8 text-center text-sm font-medium text-zinc-500 dark:border-white/10 dark:text-zinc-400">
								Sin notificaciones
							</div>
						)}

						{visibleNotifications.map((notification) => {
							const Icon = notificationIcon(notification.type);
							const canNavigate = Boolean(
								notification.action_url,
							);
							const isInteractive =
								canNavigate || !notification.is_read;

							return (
								<div
									key={notification.id}
									role={isInteractive ? "button" : undefined}
									tabIndex={isInteractive ? 0 : undefined}
									onClick={() =>
										openNotification(notification)
									}
									onKeyDown={(event) => {
										if (
											event.key === "Enter" ||
											event.key === " "
										) {
											event.preventDefault();
											openNotification(notification);
										}
									}}
									className={[
										"group relative flex w-full gap-3 rounded-xl p-3 text-left transition",
										isInteractive ? "cursor-pointer" : "",
										notification.is_read
											? "bg-white hover:bg-zinc-50 dark:bg-transparent dark:hover:bg-white/5"
											: "bg-lime-50/90 ring-1 ring-lime-100 hover:bg-lime-100/70 dark:bg-lime-300/10 dark:ring-lime-300/15 dark:hover:bg-lime-300/15",
									].join(" ")}
								>
									<div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-lime-100 text-green-800 dark:bg-lime-300/20 dark:text-lime-200">
										<Icon className="size-6" />
									</div>

									<div className="min-w-0 flex-1 pr-7">
										<div className="flex items-start justify-between gap-2">
											<p className="line-clamp-2 text-sm font-black leading-5 text-famedic-darker dark:text-white">
												{notification.title}
											</p>
											{!notification.is_read && (
												<span className="mt-1 size-2.5 shrink-0 rounded-full bg-green-500" />
											)}
										</div>
										<p className="mt-0.5 line-clamp-2 text-sm leading-5 text-slate-600 dark:text-slate-300">
											{notification.message}
										</p>
										<div className="mt-1.5 flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
											<span>
												{timeAgo(
													notification.created_at,
												)}
											</span>
											{canNavigate && (
												<button
													type="button"
													onClick={(event) => {
														event.stopPropagation();
														visitNotification(
															notification,
														);
													}}
													className="inline-flex items-center gap-1 rounded-md font-bold text-famedic-darker transition hover:text-green-700 dark:text-lime-200 dark:hover:text-lime-100"
												>
													Ver pedido
													<ArrowRightIcon className="size-3.5" />
												</button>
											)}
										</div>
									</div>

									{!notification.is_read && (
										<button
											type="button"
											onClick={(event) => {
												event.stopPropagation();
												markReadOnly(notification.id);
											}}
											className="absolute bottom-3 right-3 inline-flex size-8 items-center justify-center rounded-full border border-lime-200 bg-white text-green-700 shadow-sm transition hover:border-green-300 hover:bg-green-50 dark:border-lime-300/20 dark:bg-white/10 dark:text-lime-200 dark:hover:bg-white/15"
											aria-label="Marcar como leída"
											title="Marcar como leída"
										>
											<CheckIcon className="size-4" />
										</button>
									)}
								</div>
							);
						})}
					</div>

					<div className="border-t border-zinc-100 p-3 dark:border-white/10">
						<button
							type="button"
							onClick={() =>
								router.visit(
									route("laboratory-purchases.index"),
								)
							}
							className="flex w-full items-center justify-center gap-2 rounded-lg bg-famedic-darker px-4 py-3 text-sm font-black text-white transition hover:bg-famedic-dark dark:bg-lime-300 dark:text-famedic-darker dark:hover:bg-lime-200"
						>
							Ver mis pedidos
							<ArrowRightIcon className="size-4" />
						</button>
					</div>
				</div>
			</DropdownMenu>
		</Dropdown>
	);
}
