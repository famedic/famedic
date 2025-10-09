import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";

export default function RoleDeleteForm({
	role,
	setOpenDeleteConfirmation,
	openDeleteConfirmation,
}) {
	const { delete: destroy, processing } = useForm({});

	const deleteRole = () => {
		if (!processing) {
			destroy(route("admin.roles.destroy", { role: role }));
		}
	};

	return (
		<>
			<Button
				dusk="deleteRole"
				onClick={() => setOpenDeleteConfirmation(true)}
				color="red"
			>
				Eliminar
			</Button>

			<DeleteConfirmationModal
				isOpen={!!openDeleteConfirmation}
				close={() => setOpenDeleteConfirmation(false)}
				title="Eliminar rol"
				description="¿Estás seguro de que deseas eliminar este rol?"
				processing={processing}
				destroy={deleteRole}
			/>
		</>
	);
}
