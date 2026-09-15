export function buildLaboratoryOrderShareMessage(url) {
	return [
		"Te comparto los datos de tu cita de laboratorio en FAMEDIC.",
		"",
		"Consulta aqui la informacion de la cita, estudios e instrucciones:",
		url,
	].join("\n");
}

export function buildWhatsAppShareUrl(url) {
	return `https://wa.me/?text=${encodeURIComponent(buildLaboratoryOrderShareMessage(url))}`;
}

export function buildEmailShareUrl(url) {
	const subject = "Cita de laboratorio FAMEDIC";
	const body = buildLaboratoryOrderShareMessage(url);

	return `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}
