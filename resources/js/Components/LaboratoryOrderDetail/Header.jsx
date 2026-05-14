import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	ArrowDownTrayIcon,
	ArrowUpOnSquareIcon,
	BeakerIcon,
	CalendarDaysIcon,
	ArrowsRightLeftIcon,
	QrCodeIcon,
} from "@heroicons/react/24/outline";

const typeConfig = {
	without_appointment: { label: "Sin cita", icon: BeakerIcon, color: "blue" },
	with_appointment: {
		label: "Requiere cita",
		icon: CalendarDaysIcon,
		color: "amber",
	},
	mixed: { label: "Mixta", icon: ArrowsRightLeftIcon, color: "purple" },
};

export default function Header({
	breadcrumb,
	title,
	dateLabel,
	orderType,
	brand,
	canRequestInvoice,
	invoiceDaysLeft,
	gdaOrderId,
	gdaConsecutivo,
	onRequestInvoice,
	onDownload,
	onShare,
}) {
	const config = typeConfig[orderType] || typeConfig.without_appointment;
	const TypeIcon = config.icon;

	return (
		<div className="min-w-0 max-w-full overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
			<p className="mb-3 break-words text-sm text-zinc-500 dark:text-slate-400">{breadcrumb}</p>
			<div className="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
				<div className="min-w-0 max-w-full space-y-3">
					<div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
						{brand && (
							<div className="shrink-0 rounded-xl border border-zinc-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-950">
								<img
									src={`/images/gda/GDA-${String(brand).toUpperCase()}.png`}
									alt=""
									className="h-12 w-auto max-w-[min(140px,100%)] object-contain sm:h-14"
									onError={(e) => {
										e.currentTarget.src = "/images/gda/GDA.png";
									}}
								/>
							</div>
						)}
						<h1 className="min-w-0 break-words text-xl font-semibold tracking-tight text-zinc-900 dark:text-white sm:text-2xl lg:text-3xl">
							{title}
						</h1>
					</div>
					<div className="flex max-w-full flex-wrap gap-2">
						{gdaOrderId && (
							<Badge color="famedic" className="max-w-full break-all">
								<QrCodeIcon className="size-4 shrink-0" />
								<span className="min-w-0">Folio: {gdaOrderId}</span>
							</Badge>
						)}
						{gdaConsecutivo && (
							<Badge color="sky" className="max-w-full break-all">
								<QrCodeIcon className="size-4 shrink-0" />
								<span className="min-w-0">Identificador: {gdaConsecutivo}</span>
							</Badge>
						)}
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<Badge color="slate" className="max-w-full break-words">
							{dateLabel}
						</Badge>
						<Badge color={config.color} className="max-w-full">
							<TypeIcon className="size-4 shrink-0" />
							<span className="min-w-0">{config.label}</span>
						</Badge>
						<Badge color="green" className="max-w-full break-words text-xs sm:text-sm">
							Sincronización automática
						</Badge>
					</div>
				</div>
				<div className="flex min-w-0 w-full max-w-full flex-col gap-2 sm:flex-row sm:flex-wrap lg:w-auto lg:max-w-md lg:justify-end">
					<Button outline type="button" className="w-full justify-center sm:w-auto" onClick={onDownload}>
						<ArrowDownTrayIcon className="size-4" />
						Descargar orden
					</Button>
					<Button outline type="button" className="w-full justify-center sm:w-auto" onClick={onShare}>
						<ArrowUpOnSquareIcon className="size-4" />
						Compartir
					</Button>
					{canRequestInvoice && (
						<div className="flex w-full min-w-0 flex-col gap-1 sm:w-auto">
							<Button type="button" className="w-full justify-center sm:w-auto" onClick={onRequestInvoice}>
								Solicitar factura
							</Button>
							<p className="text-center text-xs text-zinc-500 dark:text-slate-400 sm:text-left">
								{invoiceDaysLeft} días restantes para solicitar factura
							</p>
						</div>
					)}
				</div>
			</div>
		</div>
	);
}
