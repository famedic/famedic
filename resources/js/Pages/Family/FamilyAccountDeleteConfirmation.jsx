import { useForm } from "@inertiajs/react";
import { useState, useEffect } from "react";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";

export default function FamilyAccountDeleteConfirmation({
	isOpen,
	close,
	familyAccount,
}) {
	const { delete: destroy, processing } = useForm({});
	const [cachedFamilyAccount, setCachedFamilyAccount] =
		useState(familyAccount);

	useEffect(() => {
		if (isOpen) {
			setCachedFamilyAccount(familyAccount);
		}
	}, [isOpen]);

	const handleDestroy = () => {
		if (!processing && cachedFamilyAccount) {
			destroy(
				route("family.destroy", {
					family_account: cachedFamilyAccount,
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
			title={`Eliminar familiar "${cachedFamilyAccount?.full_name || ""}"`}
			description="¿Estás seguro de que deseas eliminar el familiar?"
			processing={processing}
			destroy={handleDestroy}
		/>
	);
}
