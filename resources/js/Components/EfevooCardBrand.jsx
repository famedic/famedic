export default function EfevooCardBrand({ brand, className = "size-6" }) {
	const brandSVGsMap = {
		visa: {
			light: "https://cdn.simpleicons.org/visa",
			dark: "https://cdn.simpleicons.org/visa/FFFFFF",
		},
		mastercard: {
			light: "https://cdn.simpleicons.org/mastercard",
			dark: "https://cdn.simpleicons.org/mastercard/FFFFFF",
		},
		amex: {
			light: "https://cdn.simpleicons.org/americanexpress",
			dark: "https://cdn.simpleicons.org/americanexpress/FFFFFF",
		},
	};

	if (!brand) return null;
	
	brand = brand.toLowerCase();
	if (brandSVGsMap[brand]) {
		return (
			<>
				<img
					src={brandSVGsMap[brand].light}
					alt={brand}
					className={`${className} stroke-zinc-500/40 dark:hidden`}
				/>
				<img
					src={brandSVGsMap[brand].dark}
					alt={brand}
					className={`${className} hidden stroke-zinc-500/40 dark:block`}
				/>
			</>
		);
	}

	// Icono por defecto si no reconocemos la marca
	return (
		<CreditCardIcon className={`${className} text-gray-400`} />
	);
}