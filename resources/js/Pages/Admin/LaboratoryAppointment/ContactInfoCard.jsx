export default function ContactInfoCard({ contact }) {
	return (
		<section className="rounded-xl border border-zinc-200/70 bg-white/85 p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
			<h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				Informacion de contacto
			</h3>
			<div className="space-y-1.5">
				<Item label="Nombre" value={contact.name} />
				<Item label="Correo" value={contact.email} />
				<Item label="Telefono" value={contact.phone} />
			</div>
		</section>
	);
}

function Item({ label, value }) {
	return (
		<div className="grid grid-cols-[5rem_minmax(0,1fr)] items-baseline gap-2">
			<p className="text-xs text-zinc-500 dark:text-zinc-400">{label}</p>
			<p
				className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100"
				title={value || "..."}
			>
				{value || "..."}
			</p>
		</div>
	);
}
