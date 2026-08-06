import { useMemo, useState } from "react";
import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon, MagnifyingGlassIcon } from "@heroicons/react/16/solid";
import ContactsToolbar from "@/Components/Admin/ActiveCampaign/Contacts/ContactsToolbar";
import ContactsTable from "@/Components/Admin/ActiveCampaign/Contacts/ContactsTable";
import ContactDrawer from "@/Components/Admin/ActiveCampaign/Contacts/ContactDrawer";

export default function Contacts({ contacts, filters, drawer = null }) {
	const [selected, setSelected] = useState(null);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters?.search) {
			badges.push(
				<Badge key="search" color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}
		if (filters?.membership === "active") {
			badges.push(
				<Badge key="membership-active" color="emerald">
					Membresía activa
				</Badge>,
			);
		} else if (filters?.membership === "inactive") {
			badges.push(
				<Badge key="membership-inactive" color="zinc">
					Membresía inactiva
				</Badge>,
			);
		}
		if (filters?.start_date) {
			badges.push(
				<Badge key="start" color="slate">
					Desde {filters.start_date}
				</Badge>,
			);
		}
		if (filters?.end_date) {
			badges.push(
				<Badge key="end" color="slate">
					Hasta {filters.end_date}
				</Badge>,
			);
		}

		return badges;
	}, [filters]);

	return (
		<AdminLayout title="Marketing Intelligence · Contactos">
			<div className="space-y-6">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<Link
						href={route("admin.activecampaign.dashboard")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						Marketing Intelligence
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Contactos
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="max-w-2xl space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Contactos</Heading>
							<Badge color="famedic">CRM</Badge>
							<Badge color="sky">Centro del módulo</Badge>
						</div>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Hub de pacientes: filtrar, explorar y abrir la vista 360.
							Journey, eventos, tags e insights parten desde aquí.
						</Text>
					</div>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
					<ContactsToolbar filters={filters} />
				</div>

				<ContactsTable
					contacts={contacts}
					filterBadges={filterBadges}
					onOpenContact={setSelected}
				/>
			</div>

			<ContactDrawer
				open={Boolean(selected)}
				contact={selected}
				drawer={drawer}
				onClose={() => setSelected(null)}
			/>
		</AdminLayout>
	);
}
