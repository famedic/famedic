export default function ContactInfoCard({ contact }) {
	return (
		<section className="rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
			<h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				Informacion de contacto
			</h3>
			<div className="grid gap-4 sm:grid-cols-2">
				<Item label="Nombre" value={contact.name} />
				<Item label="Correo" value={contact.email} />
				<Item label="Telefono" value={contact.phone} />
			</div>
		</section>
	);
}

function Item({ label, value }) {
	return (
		<div>
			<p className="text-xs text-zinc-500 dark:text-zinc-400">{label}</p>
			<p className="break-all text-sm font-medium text-zinc-900 dark:text-zinc-100">
				{value || "..."}
			</p>
		</div>
	);
}
