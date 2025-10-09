import AdminLayout from "@/Layouts/AdminLayout";
import { Divider } from "@/Components/Catalyst/divider";
import { Heading } from "@/Components/Catalyst/heading";
import AdministratorForm from "@/Pages/Admin/Administrators/AdministratorForm";

export default function AdministratorCreation({ roles }) {
	return (
		<AdminLayout title="Agregar administrador">
			<Heading>Agregar administrador</Heading>

			<Divider />

			<AdministratorForm roles={roles} />
		</AdminLayout>
	);
}
