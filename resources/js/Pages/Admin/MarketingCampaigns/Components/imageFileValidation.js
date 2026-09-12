export const MARKETING_CAMPAIGN_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
export const MARKETING_CAMPAIGN_IMAGE_RECOMMENDED_BYTES = 1 * 1024 * 1024;

export function formatFileSize(bytes) {
	if (!Number.isFinite(bytes) || bytes <= 0) {
		return "0 KB";
	}

	const units = ["bytes", "KB", "MB"];
	let size = bytes;
	let unitIndex = 0;

	while (size >= 1024 && unitIndex < units.length - 1) {
		size /= 1024;
		unitIndex += 1;
	}

	const precision = unitIndex === 0 || size >= 10 ? 0 : 1;
	const formatted = Number.isInteger(size)
		? String(size)
		: size.toFixed(precision);

	return `${formatted} ${units[unitIndex]}`;
}

export function validateMarketingCampaignImageFile(file) {
	if (!file) {
		return { valid: true, message: "" };
	}

	const size = Number(file.size) || 0;
	const formattedSize = formatFileSize(size);
	const maxSize = formatFileSize(MARKETING_CAMPAIGN_IMAGE_MAX_BYTES);
	const recommendedSize = formatFileSize(
		MARKETING_CAMPAIGN_IMAGE_RECOMMENDED_BYTES,
	);

	if (size > MARKETING_CAMPAIGN_IMAGE_MAX_BYTES) {
		return {
			valid: false,
			severity: "error",
			message: `Esta imagen pesa ${formattedSize}. El límite permitido es ${maxSize}; comprímela o elige otro archivo.`,
		};
	}

	if (size > MARKETING_CAMPAIGN_IMAGE_RECOMMENDED_BYTES) {
		return {
			valid: true,
			severity: "warning",
			message: `La imagen pesa ${formattedSize}. Sí puede subirse, pero recomendamos dejarla cerca de ${recommendedSize} para que la landing cargue mejor.`,
		};
	}

	return {
		valid: true,
		severity: "success",
		message: `Imagen lista para subir (${formattedSize}).`,
	};
}
