import { useForm } from "@inertiajs/react";
import { useState, useEffect } from "react";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";

export default function ContactDeleteConfirmation({ isOpen, close, contact }) {
	const { delete: destroy, processing } = useForm({});
	const [cachedContact, setCachedContact] = useState(contact);

	useEffect(() => {
		if (isOpen) {
			setCachedContact(contact);
		}
	}, [isOpen]);

	const handleDestroy = () => {
		if (!processing && cachedContact) {
			destroy(route("contacts.destroy", { contact: cachedContact }), {
				preserveScroll: true,
				onSuccess: () => close(),
			});
		}
	};

	return (
		<DeleteConfirmationModal
			isOpen={isOpen}
			close={close}
			title={`Eliminar paciente "${cachedContact?.full_name || ""}"`}
			description="¿Estás seguro de que deseas eliminar el paciente frecuente?"
			processing={processing}
			destroy={handleDestroy}
		/>
	);
}
