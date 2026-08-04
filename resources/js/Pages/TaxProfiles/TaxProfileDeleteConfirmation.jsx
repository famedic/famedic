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
	}, [isOpen, taxProfile]);

	const isDefault = cachedTaxProfile?.is_default === true;

	const description = [
		"Dejará de estar disponible para nuevas solicitudes. Tus solicitudes y facturas anteriores no se eliminan ni cambian.",
		isDefault
			? "Asignaremos automáticamente otro perfil activo como predeterminado, si está disponible."
			: null,
	]
		.filter(Boolean)
		.join(" ");

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
			title={`Desactivar perfil fiscal "${cachedTaxProfile?.name || ""}"`}
			description={description}
			processing={processing}
			destroy={handleDestroy}
			confirmLabel="Desactivar"
		/>
	);
}
