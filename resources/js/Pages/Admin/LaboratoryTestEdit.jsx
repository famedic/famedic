import LaboratoryTestForm from "@/Pages/Admin/LaboratoryTests/LaboratoryTestForm";
import AdminLayout from "@/Layouts/AdminLayout";
import { Divider } from "@/Components/Catalyst/divider";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

export default function LaboratoryTestEdit({
	laboratoryTest,
	brands,
	categories,
}) {
	return (
		<AdminLayout title="Editar Prueba de Laboratorio">
			<div className="flex flex-wrap items-end justify-between gap-8">
				<div>
					<Heading>Editar {laboratoryTest.name}</Heading>
					<Text>
						GDA ID: {laboratoryTest.gda_id} • {laboratoryTest.brand}
					</Text>
				</div>
			</div>

			<Divider />

			<LaboratoryTestForm
				laboratoryTest={laboratoryTest}
				brands={brands}
				categories={categories}
			/>
		</AdminLayout>
	);
}
