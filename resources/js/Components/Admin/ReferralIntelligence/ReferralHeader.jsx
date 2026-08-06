import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	ArrowPathIcon,
	ArrowDownTrayIcon,
	ArrowLeftIcon,
	FunnelIcon,
} from "@heroicons/react/16/solid";

export default function ReferralHeader({
	customersIndexUrl,
	hubUrl,
	onRefresh,
	onExport,
	onToggleFilters,
	refreshing = false,
	generatedAt,
	canExport = false,
	filtersOpen = false,
}) {
	return (
		<div className="flex flex-wrap items-start justify-between gap-4">
			<div className="space-y-2">
				<div className="flex flex-wrap gap-2">
					<Button
						href={customersIndexUrl}
						outline
						className="inline-flex items-center gap-2"
					>
						<ArrowLeftIcon className="size-4" />
						Clientes
					</Button>
					{hubUrl ? (
						<Button href={hubUrl} plain className="!text-sm">
							Customer Intelligence
						</Button>
					) : null}
				</div>
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-famedic-light">
						Referral Intelligence
					</p>
					<Heading className="mt-1">Referenciados</Heading>
					<Text className="mt-1 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
						Analiza el desempeño de clientes que invitan nuevos usuarios y
						mide su impacto comercial.
					</Text>
					{generatedAt ? (
						<Text className="mt-1 text-xs text-zinc-400">
							Actualizado {generatedAt}
						</Text>
					) : null}
				</div>
			</div>
			<div className="flex flex-wrap gap-2">
				<Button outline onClick={onToggleFilters}>
					<FunnelIcon className="size-4" />
					{filtersOpen ? "Ocultar filtros" : "Filtros"}
				</Button>
				<Button outline onClick={onRefresh} disabled={refreshing}>
					<ArrowPathIcon className="size-4" />
					Actualizar
				</Button>
				{canExport ? (
					<Button outline onClick={() => onExport("csv")}>
						<ArrowDownTrayIcon className="size-4" />
						Exportar
					</Button>
				) : null}
			</div>
		</div>
	);
}
