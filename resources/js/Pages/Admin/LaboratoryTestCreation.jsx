import AdminLayout from "@/Layouts/AdminLayout";
import { Divider } from "@/Components/Catalyst/divider";
import { Heading } from "@/Components/Catalyst/heading";
import LaboratoryTestForm from "@/Pages/Admin/LaboratoryTests/LaboratoryTestForm";

export default function LaboratoryTestCreation({ brands, categories }) {
	return (
		<AdminLayout title="Agregar prueba de laboratorio">
			<Heading>Agregar prueba de laboratorio</Heading>

			<Divider />

			<LaboratoryTestForm brands={brands} categories={categories} />
		</AdminLayout>
	);
}
