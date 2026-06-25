export function formatShortDateTime(iso) {
	if (!iso) return "—";
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "short",
		timeStyle: "short",
	});
}

export function formatMxnFromCents(cents) {
	if (cents == null || cents === "") return "—";
	const n = Number(cents);
	if (Number.isNaN(n)) return "—";
	return (n / 100).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
	});
}

export function formatMxnFromNumber(n) {
	if (n === null || n === undefined || Number.isNaN(Number(n))) return "—";
	return Number(n).toLocaleString("es-MX", {
		style: "currency",
		currency: "MXN",
	});
}

export function creatorDisplayName(user) {
	if (!user) return "Sistema";
	if (user.full_name) return user.full_name;
	const parts = [user.name, user.paternal_lastname, user.maternal_lastname].filter(
		Boolean,
	);
	return parts.join(" ").trim() || user.email || "Sistema";
}
