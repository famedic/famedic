import AdminLayout from "@/Layouts/AdminLayout";
import { Divider } from "@/Components/Catalyst/divider";
import { Heading } from "@/Components/Catalyst/heading";
import RoleForm from "@/Pages/Admin/Roles/RoleForm";

export default function RoleCreation() {
	return (
		<AdminLayout title="Roles y permisos">
			<Heading>Crear rol</Heading>

			<Divider />

			<RoleForm />
		</AdminLayout>
	);
}
