import { router } from "@inertiajs/react";

export function resolveLaboratoryBrandValue(laboratoryBrand) {
	if (!laboratoryBrand) {
		return null;
	}

	if (typeof laboratoryBrand === "string") {
		return laboratoryBrand;
	}

	return laboratoryBrand.value ?? null;
}

export function removeLaboratoryCartMembership(laboratoryBrand, options = {}) {
	const brand = resolveLaboratoryBrandValue(laboratoryBrand);

	if (!brand) {
		options.onError?.("Marca de laboratorio no válida.");
		return;
	}

	router.delete(
		route("laboratory.cart-membership.destroy", {
			laboratory_brand: brand,
		}),
		{
			preserveScroll: true,
			preserveState: false,
			onFinish: options.onFinish,
			onError: (errors) => {
				options.onError?.(
					errors?.message ||
						"No pudimos quitar la membresía. Actualiza la página e inténtalo de nuevo.",
				);
			},
		},
	);
}
