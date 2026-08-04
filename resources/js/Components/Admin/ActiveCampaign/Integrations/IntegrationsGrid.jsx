import IntegrationCard from "./IntegrationCard";

function GridSkeleton() {
	return (
		<div
			className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
			aria-busy="true"
			aria-label="Cargando detalles de integraciones"
		>
			{Array.from({ length: 3 }).map((_, i) => (
				<div
					key={i}
					className="h-64 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800"
				/>
			))}
		</div>
	);
}

export default function IntegrationsGrid({
	integrations = [],
	deferred = null,
	loadingDeferred = false,
}) {
	const probesById = Object.fromEntries(
		(deferred?.probes || []).map((p) => [p.id, p]),
	);
	const acError = deferred?.details?.activecampaign_last_error || null;

	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Integraciones
				</h2>
				<p className="text-xs text-zinc-500">
					ActiveCampaign y Mailgun con señales reales de config; el resto
					queda como Próximamente.
				</p>
			</div>

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
				{integrations.map((item) => (
					<IntegrationCard
						key={item.id}
						integration={item}
						probe={probesById[item.id] || null}
						lastErrorDetail={
							item.id === "activecampaign" && !loadingDeferred
								? acError
								: null
						}
					/>
				))}
			</div>

			{loadingDeferred ? (
				<p className="text-[11px] text-zinc-400">
					Cargando probes y detalle de errores…
				</p>
			) : null}
		</section>
	);
}

export { GridSkeleton };
