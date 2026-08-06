import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	ArrowPathIcon,
	ArrowDownTrayIcon,
	ArrowLeftIcon,
	DocumentArrowDownIcon,
	TableCellsIcon,
} from "@heroicons/react/16/solid";

export default function DormantHeader({
	customersIndexUrl,
	onRefresh,
	onExport,
	refreshing = false,
	generatedAt,
	canExport = false,
}) {
	return (
		<div className="flex flex-wrap items-start justify-between gap-4">
			<div className="space-y-2">
				<Button
					href={customersIndexUrl}
					outline
					className="inline-flex items-center gap-2"
				>
					<ArrowLeftIcon className="size-4" />
					Todos los clientes
				</Button>
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-famedic-light">
						Customer Intelligence Center
					</p>
					<Heading className="mt-1">Clientes Dormidos</Heading>
					<Text className="mt-1 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
						Clientes registrados que nunca han realizado una compra.
						Oportunidades de activación para Marketing, Growth y
						Comercial.
					</Text>
					{generatedAt ? (
						<Text className="mt-1 text-xs text-zinc-400">
							Actualizado {generatedAt}
						</Text>
					) : null}
				</div>
			</div>
			<div className="flex flex-wrap gap-2">
				<Button outline onClick={onRefresh} disabled={refreshing}>
					<ArrowPathIcon className="size-4" />
					Actualizar
				</Button>
				{canExport ? (
					<>
						<Button outline onClick={() => onExport("xlsx")}>
							<TableCellsIcon className="size-4" />
							Excel
						</Button>
						<Button outline onClick={() => onExport("csv")}>
							<ArrowDownTrayIcon className="size-4" />
							CSV
						</Button>
						<Button outline onClick={() => onExport("pdf")}>
							<DocumentArrowDownIcon className="size-4" />
							PDF
						</Button>
					</>
				) : null}
			</div>
		</div>
	);
}
