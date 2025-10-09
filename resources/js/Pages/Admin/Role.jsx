import { useState } from "react";
import RoleForm from "@/Pages/Admin/Roles/RoleForm";
import AdminLayout from "@/Layouts/AdminLayout";
import { Divider } from "@/Components/Catalyst/divider";
import { Heading } from "@/Components/Catalyst/heading";
import RoleDeleteForm from "@/Pages/Admin/Roles/RoleDeleteForm";
import { ShieldCheckIcon } from "@heroicons/react/16/solid";

export default function Role({ role, showDeleteButton }) {
	const [openDeleteConfirmation, setOpenDeleteConfirmation] = useState(false);

	return (
		<AdminLayout title="Roles y permisos">
			<div className="flex flex-wrap items-end justify-between gap-8">
				<Heading>Rol "{role.name}"</Heading>

				{showDeleteButton && (
					<RoleDeleteForm
						role={role}
						setOpenDeleteConfirmation={setOpenDeleteConfirmation}
						openDeleteConfirmation={openDeleteConfirmation}
					/>
				)}
			</div>

			<Divider />

			<RoleForm />
		</AdminLayout>
	);
}
