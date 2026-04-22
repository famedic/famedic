import Card from "@/Components/Card";

export default function PatientCard({ purchase }) {
	const fullName =
		purchase?.temporarly_hide_gda_order_id
			? "Nombre de paciente pendiente"
			: purchase?.full_name || "Sin nombre";

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6">
			<h2 className="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">
				Informacion del paciente
			</h2>
			<div className="grid min-w-0 gap-4 sm:grid-cols-2">
				<Detail label="Nombre" value={fullName} />
				<Detail label="Telefono" value={purchase?.phone || "No disponible"} />
				<Detail
					label="Fecha de nacimiento"
					value={purchase?.formatted_birth_date || "No especificada"}
				/>
				<Detail label="Genero" value={purchase?.formatted_gender || "No especificado"} />
			</div>
		</Card>
	);
}

function Detail({ label, value }) {
	return (
		<div className="min-w-0">
			<p className="text-xs uppercase tracking-wide text-zinc-500 dark:text-slate-400">{label}</p>
			<p className="mt-1 break-words text-sm font-medium text-zinc-800 dark:text-slate-100">{value}</p>
		</div>
	);
}
