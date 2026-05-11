export default function PatientInfoCard({ patient }) {
	return (
		<section className="rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
			<h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				Informacion del paciente
			</h3>
			<div className="grid gap-4 sm:grid-cols-2">
				<Item label="Paciente" value={patient.fullName} />
				<Item label="Sexo" value={patient.gender} />
				<Item label="Telefono" value={patient.phone} />
				<Item label="Fecha de nacimiento" value={patient.birthDate} />
			</div>
		</section>
	);
}

function Item({ label, value }) {
	return (
		<div>
			<p className="text-xs text-zinc-500 dark:text-zinc-400">{label}</p>
			<p className="text-sm font-medium text-zinc-900 dark:text-zinc-100">
				{value || "..."}
			</p>
		</div>
	);
}
