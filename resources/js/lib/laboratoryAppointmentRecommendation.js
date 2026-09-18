export function appointmentStoreRecommendationPresentation(recommendation) {
	if (!recommendation?.store) {
		return null;
	}

	const status = recommendation.validation?.status ?? "unknown";
	const valid = status === "valid" && recommendation.can_use === true;

	if (valid) {
		return {
			tone: "success",
			title: "Sucursal recomendada por el paciente",
			message:
				"El paciente seleccionó previamente esta sucursal. Puedes utilizarla o elegir otra.",
			detail:
				"Esta sucursal coincide con los requisitos conocidos de los estudios actuales.",
			actionLabel: "Usar esta sucursal",
			canUse: true,
		};
	}

	return {
		tone: status === "unknown" ? "warning" : "danger",
		title: "La recomendación necesita revisión",
		message:
			"La selección del paciente ya no coincide con los requisitos conocidos de los estudios actuales.",
		detail:
			status === "unknown"
				? "El paciente seleccionó esta sucursal previamente. Revisa manualmente antes de usar el selector."
				: "Elige otra sucursal desde el selector existente.",
		actionLabel: null,
		canUse: false,
	};
}
