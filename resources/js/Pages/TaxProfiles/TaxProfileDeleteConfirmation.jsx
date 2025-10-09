import { useForm } from "@inertiajs/react";
import { useState, useEffect } from "react";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";

export default function TaxProfileDeleteConfirmation({
	isOpen,
	close,
	taxProfile,
}) {
	const { delete: destroy, processing } = useForm({});
	const [cachedTaxProfile, setCachedTaxProfile] = useState(taxProfile);

	useEffect(() => {
		if (isOpen) {
			setCachedTaxProfile(taxProfile);
		}
	}, [isOpen]);

	const handleDestroy = () => {
		if (!processing && cachedTaxProfile) {
			destroy(
				route("tax-profiles.destroy", {
					tax_profile: cachedTaxProfile,
				}),
				{
					preserveScroll: true,
					onSuccess: () => close(),
				},
			);
		}
	};

	return (
		<DeleteConfirmationModal
			isOpen={isOpen}
			close={close}
			title={`Eliminar perfil fiscal "${cachedTaxProfile?.name || ""}"`}
			description="¿Estás seguro de que deseas eliminar el perfil fiscal?"
			processing={processing}
			destroy={handleDestroy}
		/>
	);
}
