export default function CreditCardBrand({ brand, className = "size-6" }) {
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
		// Agregar más marcas según EfevooPay
		'american express': {
			light: "https://cdn.simpleicons.org/americanexpress",
			dark: "https://cdn.simpleicons.org/americanexpress/FFFFFF",
		},
		discover: {
			light: "https://cdn.simpleicons.org/discover",
			dark: "https://cdn.simpleicons.org/discover/FFFFFF",
		},
	};

	if (!brand) return null;
	
	// Normalizar nombres de marcas
	let normalizedBrand = brand.toLowerCase();
	
	// Mapear nombres alternativos
	const brandMapping = {
		'americanexpress': 'amex',
		'american express': 'amex',
		'master card': 'mastercard',
	};
	
	if (brandMapping[normalizedBrand]) {
		normalizedBrand = brandMapping[normalizedBrand];
	}

	if (brandSVGsMap[normalizedBrand]) {
		return (
			<>
				<img
					src={brandSVGsMap[normalizedBrand].light}
					alt={brand}
					className={`${className} stroke-zinc-500/40 dark:hidden`}
				/>
				<img
					src={brandSVGsMap[normalizedBrand].dark}
					alt={brand}
					className={`${className} hidden stroke-zinc-500/40 dark:block`}
				/>
			</>
		);
	}

	// Icono por defecto
	return (
		<div className={`${className} rounded border border-gray-300 flex items-center justify-center bg-white`}>
			<CreditCardIcon className="size-4 text-gray-600" />
		</div>
	);
}