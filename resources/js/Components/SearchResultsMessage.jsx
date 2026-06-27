import { Text, Strong } from "@/Components/Catalyst/text";

export default function SearchResultsMessage({ paginatedData }) {
	const total = paginatedData.total ?? 0;

	if (total === 0) {
		return (
			<Text>
				<Strong>0</Strong> resultados encontrados
			</Text>
		);
	}

	const from = paginatedData.from ?? 0;
	const to = paginatedData.to ?? 0;

	return (
		<Text>
			<span className="flex items-center justify-between gap-2">
				<span>
					Mostrando{" "}
					<Strong>
						{from.toLocaleString("es-MX")} a{" "}
						{to.toLocaleString("es-MX")}
					</Strong>{" "}
					de <Strong>{total.toLocaleString("es-MX")}</Strong> resultados
					encontrados
				</span>
			</span>
		</Text>
	);
}
