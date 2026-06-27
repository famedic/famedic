import { useMemo, useState } from "react";
import { Link } from "@inertiajs/react";
import {
	HomeIcon,
	ShoppingBagIcon,
	MapPinIcon,
	IdentificationIcon,
} from "@heroicons/react/24/outline";
import BottomSheetMenu from "@/Components/MobileNav/BottomSheetMenu";
import FloatingActionButton from "@/Components/MobileNav/FloatingActionButton";

function isPedidosActive() {
	return Boolean(
		route().current("laboratory-purchases.index") ||
			route().current("laboratory-purchases.show") ||
			route().current("online-pharmacy-purchases.index") ||
			route().current("online-pharmacy-purchases.show"),
	);
}

function isPacientesActive() {
	return Boolean(
		route().current("contacts.index") ||
			route().current("contacts.create") ||
			route().current("contacts.edit"),
	);
}

function isDireccionesActive() {
	return Boolean(
		route().current("addresses.index") ||
			route().current("addresses.create") ||
			route().current("addresses.edit"),
	);
}

function BottomNavLink({ href, label, icon: Icon, active }) {
	return (
		<Link
			href={href}
			className={`flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 border-t-2 px-1 py-2 transition active:scale-[0.98] ${
				active
					? "border-famedic-lime text-famedic-darker dark:text-famedic-lime"
					: "border-transparent text-zinc-500 hover:text-famedic-dark dark:text-slate-400 dark:hover:text-white"
			}`}
		>
			<Icon className={`size-6 shrink-0 ${active ? "scale-105" : ""}`} />
			<span className="max-w-full truncate text-center text-[10px] font-semibold leading-tight sm:text-xs">
				{label}
			</span>
		</Link>
	);
}

/**
 * Barra inferior móvil: Inicio, Pedidos, (+), Pacientes, Direcciones.
 * El (+) abre bottom sheet con el resto de enlaces (permisos desde `userNavigation` en servidor).
 */
export default function MobileBottomNav({ userNavigation = [] }) {
	const [menuOpen, setMenuOpen] = useState(false);

	const sheetItems = useMemo(() => {
		const fromServer = userNavigation.map(({ label, url, icon }) => ({
			label,
			url,
			icon,
		}));
		const extras = [
			{
				label: "Ayuda",
				url: "tel:8128601893",
				icon: "PhoneIcon",
			},
			{
				label: "Configuración",
				url: route("user.edit"),
				icon: "Cog6ToothIcon",
			},
		];
		return [...fromServer, ...extras];
	}, [userNavigation]);

	const inicioActive = Boolean(route().current("home"));
	const pedidosActive = isPedidosActive();
	const pacientesActive = isPacientesActive();
	const direccionesActive = isDireccionesActive();

	return (
		<>
			<BottomSheetMenu open={menuOpen} onClose={() => setMenuOpen(false)} items={sheetItems} />

			<nav
				className="fixed inset-x-0 bottom-0 z-[55] border-t border-zinc-200/90 bg-white/95 pb-[max(0.35rem,env(safe-area-inset-bottom))] shadow-[0_-4px_24px_rgba(0,0,0,0.06)] backdrop-blur-md dark:border-slate-800 dark:bg-slate-900/95 lg:hidden"
				aria-label="Navegación principal"
			>
				<div className="relative mx-auto grid h-[4.25rem] max-w-lg grid-cols-5 items-end px-1">
					<BottomNavLink
						href={route("home")}
						label="Inicio"
						icon={HomeIcon}
						active={inicioActive}
					/>
					<BottomNavLink
						href={route("laboratory-purchases.index")}
						label="Pedidos"
						icon={ShoppingBagIcon}
						active={pedidosActive}
					/>

					<div className="relative flex min-h-[3.25rem] flex-col items-center justify-end pb-1">
						<div className="absolute bottom-[calc(100%-0.35rem)] left-1/2 z-10 -translate-x-1/2">
							<FloatingActionButton
								onClick={() => setMenuOpen(true)}
								aria-label="Abrir menú de más opciones"
							/>
						</div>
					</div>

					<BottomNavLink
						href={route("contacts.index")}
						label="Pacientes"
						icon={IdentificationIcon}
						active={pacientesActive}
					/>
					<BottomNavLink
						href={route("addresses.index")}
						label="Direcciones"
						icon={MapPinIcon}
						active={direccionesActive}
					/>
				</div>
			</nav>

			{/* Espacio para barra + FAB elevado + safe area */}
			<div
				className="h-[max(5.75rem,calc(4.5rem+env(safe-area-inset-bottom)))] shrink-0 lg:hidden"
				aria-hidden
			/>
		</>
	);
}
