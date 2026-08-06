import { ArrowDownTrayIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

const DATASETS = [
	{ key: "executive", label: "Executive" },
	{ key: "laboratories", label: "Laboratorios" },
	{ key: "funnel", label: "Funnel" },
	{ key: "alerts", label: "Alertas" },
	{ key: "activity", label: "Activity" },
];

const FORMATS = [
	{ key: "csv", label: "CSV" },
	{ key: "xlsx", label: "Excel" },
	{ key: "pdf", label: "PDF" },
];

export default function ExportControls({ urls, filters = {}, updatedAt = null }) {
	const download = (format, dataset) => {
		const params = new URLSearchParams({
			format,
			dataset,
			preset: filters.preset || "7d",
		});
		if (filters.start_date) params.set("start_date", filters.start_date);
		if (filters.end_date) params.set("end_date", filters.end_date);
		if (filters.laboratory) params.set("laboratory", filters.laboratory);
		if (filters.branch) params.set("branch", filters.branch);
		if (filters.purchase_type) params.set("purchase_type", filters.purchase_type);
		if (filters.membership) params.set("membership", filters.membership);
		if (filters.owner) params.set("owner", filters.owner);

		window.location.href = `${urls.export}?${params.toString()}`;
	};

	return (
		<section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<SectionHeader
				title="Exportaciones"
				description="CSV · Excel · PDF (infraestructura existente)."
				provenance={provenanceForSection("exports")}
				updatedAt={updatedAt}
			/>
			<div className="flex flex-wrap gap-2">
				{DATASETS.map((dataset) =>
					FORMATS.map((format) => (
						<Button
							key={`${dataset.key}-${format.key}`}
							outline
							onClick={() => download(format.key, dataset.key)}
						>
							<ArrowDownTrayIcon className="size-4" />
							{dataset.label} · {format.label}
						</Button>
					)),
				)}
			</div>
		</section>
	);
}
