import { SidebarLayout } from "@/Components/Catalyst/sidebar-layout";
import Notification from "@/Components/Notification";
import NavBar from "@/Layouts/AdminLayout/navbar";
import SideBar from "@/Layouts/AdminLayout/sidebar";
import { Head } from "@inertiajs/react";
import useTrackingEvents from "@/Hooks/useTrackingEvents";
import AppLayout from "@/Layouts/AppLayout";

export default function AdminLayout({ title, children }) {
	useTrackingEvents();

	return (
		<AppLayout>
			<Head title={title} />
			<SidebarLayout navbar={<NavBar />} sidebar={<SideBar />}>
				<div className="space-y-8">{children}</div>
			</SidebarLayout>
			<Notification />
		</AppLayout>
	);
}
