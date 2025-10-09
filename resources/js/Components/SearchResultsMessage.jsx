import { Text, Strong } from "@/Components/Catalyst/text";

export default function SearchResultsMessage({ paginatedData }) {
	return (
		<Text>
			<span className="flex items-center justify-between gap-2">
				<span>
					Mostrando{" "}
					<Strong>
						{paginatedData.from.toLocaleString("es-MX")} a{" "}
						{paginatedData.to.toLocaleString("es-MX")}
					</Strong>{" "}
					de{" "}
					<Strong>
						{paginatedData.total.toLocaleString("es-MX")}
					</Strong>{" "}
					resultados encontrados
				</span>
			</span>
		</Text>
	);
}
