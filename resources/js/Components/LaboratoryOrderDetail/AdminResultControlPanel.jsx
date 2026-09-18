import { useState } from "react";
import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	ArrowPathIcon,
	BellAlertIcon,
	ChevronDownIcon,
	DocumentMagnifyingGlassIcon,
	ShieldCheckIcon,
} from "@heroicons/react/24/outline";

function AdminActionButton({ title, description, disabled, busy, onClick, icon: Icon, color }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900/50">
			<div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
				<div className="min-w-0 flex-1">
					<p className="text-sm font-semibold text-zinc-900 dark:text-white">{title}</p>
					<p className="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-slate-400">
						{description}
					</p>
				</div>
				<Button
					outline={color !== "famedic-lime"}
					color={color}
					type="button"
					className="w-full shrink-0 justify-center sm:w-auto"
					disabled={disabled}
					onClick={onClick}
				>
					{busy ? <ArrowPathIcon className="size-4 animate-spin" /> : <Icon className="size-4" />}
					{title}
				</Button>
			</div>
		</div>
	);
}

export default function AdminResultControlPanel({ resultControl, actionState, onRunAction }) {
	const [open, setOpen] = useState(false);

	if (!resultControl?.can_admin_manage || !resultControl?.admin_actions) return null;

	const actions = resultControl.admin_actions;
	const isBusy = Boolean(actionState?.action);

	return (
		<Card className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/80 p-4 shadow-none dark:border-slate-600 dark:bg-slate-900/30">
			<button
				type="button"
				className="flex w-full items-start justify-between gap-3 text-left"
				onClick={() => setOpen((value) => !value)}
				aria-expanded={open}
			>
				<div className="min-w-0">
					<div className="flex flex-wrap items-center gap-2">
						<ShieldCheckIcon className="size-5 shrink-0 text-zinc-500 dark:text-slate-400" />
						<h2 className="text-sm font-semibold text-zinc-800 dark:text-slate-200">
							Herramientas internas (equipo Famedic)
						</h2>
						<Badge color={resultControl.is_complete ? "green" : "amber"}>
							{resultControl.label || "Estado operativo"}
						</Badge>
					</div>
					<p className="mt-1 text-xs text-zinc-500 dark:text-slate-400">
						Solo visible para administradores. El paciente no ve esta sección.
					</p>
				</div>
				<ChevronDownIcon
					className={`size-5 shrink-0 text-zinc-500 transition-transform dark:text-slate-400 ${open ? "rotate-180" : ""}`}
				/>
			</button>

			{open && (
				<div className="mt-4 space-y-3 border-t border-zinc-200 pt-4 dark:border-slate-700">
					<p className="text-xs leading-relaxed text-zinc-600 dark:text-slate-400">
						{resultControl.message}
					</p>

					<AdminActionButton
						title="Actualizar desde GDA"
						description="Vuelve a consultar al laboratorio por PDFs o estatus pendientes de cada estudio."
						icon={ArrowPathIcon}
						disabled={isBusy || !actions.can_refresh}
						busy={actionState?.action === "refresh"}
						onClick={() => onRunAction("refresh", actions.refresh_url)}
					/>
					<AdminActionButton
						title="Analizar existente"
						description="Procesa un PDF ya guardado para crear o actualizar el estado por estudio."
						icon={DocumentMagnifyingGlassIcon}
						disabled={isBusy || !actions.can_analyze_legacy}
						busy={actionState?.action === "analyze"}
						onClick={() => onRunAction("analyze", actions.analyze_legacy_url)}
					/>
					<AdminActionButton
						title="Reenviar aviso"
						description="Envía otra vez al paciente el correo de que sus resultados están disponibles."
						icon={BellAlertIcon}
						color="famedic-lime"
						disabled={isBusy || !actions.can_send_notification}
						busy={actionState?.action === "notify"}
						onClick={() => onRunAction("notify", actions.send_notification_url)}
					/>

					{actionState?.message && (
						<p
							className={`rounded-lg px-3 py-2 text-sm ${
								actionState.ok
									? "bg-green-50 text-green-800 dark:bg-green-950/30 dark:text-green-200"
									: "bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
							}`}
						>
							{actionState.message}
						</p>
					)}
				</div>
			)}
		</Card>
	);
}
