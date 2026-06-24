import { Button } from "@/Components/Catalyst/button";

const PRESET_LABELS = {
	all: "Todos los asegurados",
	active: "Solo activos",
	expired: "Solo vencidos",
	odessa: "Solo Odessa",
	regular: "Solo Regular",
	familiar: "Solo Familiar",
	trial: "Solo Trial / pruebas",
	institutional: "Solo Institucional",
	certificate: "Solo Certificate accounts",
	family_dependents: "Titulares con dependientes",
	sync_error: "Error de sync Murguía",
	no_credito: "Sin noCredito",
	duplicate_credito: "noCredito duplicado",
	duplicate_email: "Email duplicado",
};

export default function ExportButtons({ filters }) {
	const buildExportUrl = (format) => {
		const params = new URLSearchParams();

		Object.entries(filters).forEach(([key, value]) => {
			if (value !== null && value !== undefined && value !== "") {
				params.set(key, value);
			}
		});

		params.set("format", format);

		return `${route("admin.murguia-reports.export")}?${params.toString()}`;
	};

	return (
		<div className="flex flex-wrap gap-2">
			<Button href={buildExportUrl("csv")} outline>
				Exportar CSV
			</Button>
			<Button href={buildExportUrl("xlsx")} outline>
				Exportar Excel
			</Button>
		</div>
	);
}

export { PRESET_LABELS };
