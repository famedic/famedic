import clsx from "clsx";

/**
 * Contenedor de un grupo expandible del menú (padre + hijos).
 * Evita repetir layout en el sidebar.
 */
export default function SidebarGroup({ children, className }) {
	return (
		<div className={clsx("space-y-0.5", className)} data-slot="sidebar-group">
			{children}
		</div>
	);
}
