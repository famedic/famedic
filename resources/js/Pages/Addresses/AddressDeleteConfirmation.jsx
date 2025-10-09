import { useForm } from "@inertiajs/react";
import { useState, useEffect } from "react";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";

export default function AddressDeleteConfirmation({ isOpen, close, address }) {
	const { delete: destroy, processing } = useForm({});
	const [cachedAddress, setCachedAddress] = useState(address);

	useEffect(() => {
		if (isOpen) {
			setCachedAddress(address);
		}
	}, [isOpen]);

	const handleDestroy = () => {
		if (!processing && cachedAddress) {
			destroy(route("addresses.destroy", { address: cachedAddress }), {
				preserveScroll: true,
				onSuccess: () => close(),
			});
		}
	};

	return (
		<DeleteConfirmationModal
			isOpen={isOpen}
			close={close}
			title={`Eliminar dirección "${cachedAddress?.street} ${cachedAddress?.number || ""}"`}
			description="¿Estás seguro de que deseas eliminar tu dirección?"
			processing={processing}
			destroy={handleDestroy}
		/>
	);
}
