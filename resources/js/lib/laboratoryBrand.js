export function laboratoryBrandValue(brand) {
	if (brand == null) {
		return "";
	}

	if (typeof brand === "string") {
		return brand;
	}

	if (typeof brand === "object" && brand.value) {
		return brand.value;
	}

	return String(brand);
}

export function laboratoryBrandImageSrc(brand, brands) {
	const key = laboratoryBrandValue(brand).toLowerCase();

	if (brands?.[key]?.imageSrc) {
		return `/images/gda/${brands[key].imageSrc}`;
	}

	if (!key) {
		return "/images/gda/GDA-OLAB.png";
	}

	return `/images/gda/GDA-${key.toUpperCase()}.png`;
}
