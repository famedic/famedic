import SidebarBadge from "@/Components/Admin/Sidebar/SidebarBadge";

export default function SuiteBadge({ variant = "beta", children, className }) {
	return (
		<SidebarBadge variant={variant} className={className}>
			{children}
		</SidebarBadge>
	);
}
