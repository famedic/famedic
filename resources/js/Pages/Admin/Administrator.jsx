import { useState } from "react";
import AdministratorForm from "@/Pages/Admin/Administrators/AdministratorForm";
import AdminLayout from "@/Layouts/AdminLayout";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Divider } from "@/Components/Catalyst/divider";
import { Heading } from "@/Components/Catalyst/heading";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { TrashIcon } from "@heroicons/react/16/solid";

export default function Administrator({
	administrator,
	roles,
	showDeleteButton,
}) {
	return (
		<AdminLayout title="Administradores">
			<div className="flex flex-wrap items-end justify-between gap-8">
				<div className="flex items-center gap-2">
					<Avatar
						className="mr-2 size-12"
						src={administrator.user.profile_photo_url}
					/>
					<div>
						<Heading>{administrator.user.full_name}</Heading>
						<Text>{administrator.user.email}</Text>
					</div>
				</div>

				{showDeleteButton && (
					<AdministratorDeleteForm administrator={administrator} />
				)}
			</div>

			<Divider />

			<AdministratorForm administrator={administrator} roles={roles} />
		</AdminLayout>
	);
}

function AdministratorDeleteForm({ administrator }) {
	const [openDeleteConfirmation, setOpenDeleteConfirmation] = useState(false);
	const { delete: destroy, processing } = useForm({});

	const deleteAdministrator = () => {
		if (!processing) {
			destroy(
				route("admin.administrators.destroy", {
					administrator: administrator,
				}),
			);
		}
	};

	return (
		<>
			<Button
				dusk="deleteAdministrator"
				onClick={() => setOpenDeleteConfirmation(true)}
				outline
			>
				<TrashIcon />
				Eliminar
			</Button>

			<DeleteConfirmationModal
				isOpen={!!openDeleteConfirmation}
				close={() => setOpenDeleteConfirmation(false)}
				title="Eliminar administrador"
				description="¿Estás seguro de que deseas eliminar este administrador?"
				processing={processing}
				destroy={deleteAdministrator}
			/>
		</>
	);
}

