export default function CreditCardBrand({ brand }) {
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

	brand = brand.toLowerCase();
	if (brandSVGsMap[brand]) {
		return (
			<>
				<img
					src={brandSVGsMap[brand].light}
					alt={brand}
					className="size-6 stroke-zinc-500/40 dark:hidden"
				/>
				<img
					src={brandSVGsMap[brand].dark}
					alt={brand}
					className="hidden size-6 stroke-zinc-500/40 dark:block"
				/>
			</>
		);
	}

	return;
}
