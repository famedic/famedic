export function laboratoryBrandSelectionActions(
	routeFn,
	brandValue,
	category = "",
	brandName = brandValue,
) {
	return {
		studies: {
			label: "Ver estudios",
			ariaLabel: `Ver estudios de ${brandName}`,
			href: routeFn("laboratory-tests", {
				laboratory_brand: brandValue,
				...(category ? { category } : {}),
			}),
		},
		stores: {
			label: "Ver sucursales",
			ariaLabel: `Ver sucursales de ${brandName}`,
			href: routeFn("laboratory-stores.index", {
				brand: brandValue,
			}),
		},
	};
}
