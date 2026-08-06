import { useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	no_configurada: { label: "No configurada", color: "amber" },
	proximamente: { label: "Próximamente", color: "zinc" },
};

function ActionButton({ action, onTest, testing }) {
	if (!action) return null;

	if (action.enabled && action.href) {
		return (
			<Button href={action.href} outline>
				{action.label}
			</Button>
		);
	}

	if (action.enabled && action.mode === "config_probe") {
		return (
			<div className="space-y-1">
				<Button outline onClick={onTest} disabled={testing}>
					{testing ? "Verificando…" : action.label}
				</Button>
				{action.hint ? (
					<p className="max-w-xs text-[11px] text-zinc-400">{action.hint}</p>
				) : null}
			</div>
		);
	}

	return (
		<div className="space-y-1">
			<Button outline disabled title={action.hint || "No disponible"}>
				{action.label}
			</Button>
			{action.hint ? (
				<p className="max-w-xs text-[11px] text-zinc-400">{action.hint}</p>
			) : null}
		</div>
	);
}

export default function IntegrationCard({
	integration,
	probe = null,
	lastErrorDetail = null,
}) {
	const [showProbe, setShowProbe] = useState(false);
	const truth = TRUTH[integration.truth] || TRUTH.proximamente;
	const actions = integration.actions || {};

	return (
		<div className="relative">
			<div className="absolute right-3 top-3 z-10">
				<Badge color={truth.color} className="!text-[10px] uppercase">
					{truth.label}
				</Badge>
			</div>

			<ChartCard
				title={integration.name}
				description={`${integration.auth_type} · ${integration.category || "integration"}`}
			>
				<div className="space-y-2 pr-16 text-sm">
					<p>
						<span className="text-zinc-400">Estado · </span>
						<span className="font-medium text-zinc-900 dark:text-zinc-50">
							{integration.status}
						</span>
					</p>
					<p>
						<span className="text-zinc-400">Última sincronización · </span>
						{integration.last_sync}
					</p>
					<p>
						<span className="text-zinc-400">Último error · </span>
						{integration.last_error}
					</p>
					<p>
						<span className="text-zinc-400">Configuración · </span>
						{integration.configuration}
					</p>
					<p>
						<span className="text-zinc-400">Versión · </span>
						{integration.version}
					</p>
					<p>
						<span className="text-zinc-400">Autenticación · </span>
						{integration.auth_type}
					</p>
				</div>

				{integration.id === "activecampaign" && lastErrorDetail ? (
					<div className="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs dark:border-zinc-700 dark:bg-zinc-950/50">
						<p className="font-medium text-zinc-700 dark:text-zinc-200">
							Detalle diferido · último failed
						</p>
						<p className="mt-1 text-zinc-500">
							{lastErrorDetail.when}
							{lastErrorDetail.event_type
								? ` · ${lastErrorDetail.event_type}`
								: ""}
						</p>
						<p className="mt-1 text-zinc-600 dark:text-zinc-300">
							{lastErrorDetail.message}
						</p>
					</div>
				) : null}

				{showProbe && probe ? (
					<div
						className={`mt-3 rounded-lg border p-3 text-xs ${
							probe.ok
								? "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
								: "border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
						}`}
					>
						<p className="font-medium">Resultado de prueba (config local)</p>
						<p className="mt-1">{probe.result}</p>
						<p className="mt-1 opacity-70">
							Alcance: {probe.scope} · {probe.checked_at}
						</p>
					</div>
				) : null}

				<div className="mt-4 flex flex-wrap gap-2">
					<ActionButton
						action={actions.test}
						testing={false}
						onTest={() => setShowProbe(true)}
					/>
					<ActionButton action={actions.logs} />
					<ActionButton action={actions.settings} />
				</div>

				{actions.test?.enabled && !probe && showProbe ? (
					<Text className="mt-2 text-[11px] text-zinc-400">
						Cargando verificación diferida…
					</Text>
				) : null}
			</ChartCard>
		</div>
	);
}
