import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	ArrowDownTrayIcon,
	MagnifyingGlassIcon,
} from "@heroicons/react/24/outline";
import { useMemo, useState } from "react";

const STATUS_COLORS = {
	paid: "emerald",
	pending: "amber",
	free: "sky",
};

const STATUS_FILTERS = [
	{ key: "all", label: "Todos" },
	{ key: "paid", label: "Pagados" },
	{ key: "pending", label: "Pendientes" },
	{ key: "free", label: "Gratuitos" },
];

export default function MembershipPayments({ payments = [], capabilities }) {
	const [query, setQuery] = useState("");
	const [statusFilter, setStatusFilter] = useState("all");

	const filteredPayments = useMemo(() => {
		return payments.filter((payment) => {
			const matchesStatus =
				statusFilter === "all" || payment.statusKey === statusFilter;
			const search = query.trim().toLowerCase();

			if (!search) {
				return matchesStatus;
			}

			const haystack = [
				payment.date,
				payment.concept,
				payment.amount,
				payment.method,
				payment.provider,
				payment.status,
			]
				.filter(Boolean)
				.join(" ")
				.toLowerCase();

			return matchesStatus && haystack.includes(search);
		});
	}, [payments, query, statusFilter]);

	return (
		<div className="space-y-6">
			<div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
				<div>
					<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
						Historial de pagos
					</h3>
					<Text className="text-sm text-zinc-500">
						Consulta y filtra tus transacciones.
					</Text>
				</div>

				{capabilities?.canDownloadReceipt && (
					<Button
						outline
						disabled={!capabilities.receiptDownloadUrl}
						href={capabilities.receiptDownloadUrl ?? undefined}
						className="w-full sm:w-auto"
					>
						<ArrowDownTrayIcon className="size-4" />
						Descargar comprobante
					</Button>
				)}
			</div>

			<div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
				<div className="relative w-full lg:max-w-sm">
					<MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
					<input
						type="search"
						value={query}
						onChange={(event) => setQuery(event.target.value)}
						placeholder="Buscar por concepto, monto o método..."
						className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-4 text-sm outline-none ring-famedic-dark/20 transition focus:border-violet-300 focus:ring-2 dark:border-slate-700 dark:bg-slate-900"
					/>
				</div>

				<div className="flex gap-2 overflow-x-auto pb-1">
					{STATUS_FILTERS.map((filter) => (
						<button
							key={filter.key}
							type="button"
							onClick={() => setStatusFilter(filter.key)}
							className={`shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium transition ${
								statusFilter === filter.key
									? "border-famedic-lime bg-famedic-lime text-famedic-dark"
									: "border-slate-200 bg-white text-zinc-600 hover:bg-slate-50"
							}`}
						>
							{filter.label}
						</button>
					))}
				</div>
			</div>

			<Card className="overflow-hidden rounded-2xl shadow-sm ring-1 ring-slate-100">
				{filteredPayments.length === 0 ? (
					<div className="p-8 text-center">
						<Text className="text-sm text-zinc-500">
							No se encontraron pagos con los filtros seleccionados.
						</Text>
					</div>
				) : (
					<>
						<div className="hidden lg:block">
							<Table>
								<TableHead>
									<TableRow>
										<TableHeader>Fecha</TableHeader>
										<TableHeader>Concepto</TableHeader>
										<TableHeader>Monto</TableHeader>
										<TableHeader>Método</TableHeader>
										<TableHeader>Proveedor</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Factura</TableHeader>
										<TableHeader className="text-right">
											Acciones
										</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{filteredPayments.map((payment) => (
										<TableRow key={payment.id}>
											<TableCell>{payment.date}</TableCell>
											<TableCell>{payment.concept}</TableCell>
											<TableCell>{payment.amount}</TableCell>
											<TableCell>{payment.method}</TableCell>
											<TableCell>{payment.provider}</TableCell>
											<TableCell>
												<Badge
													color={
														STATUS_COLORS[
															payment.statusKey
														] ?? "zinc"
													}
												>
													{payment.status}
												</Badge>
											</TableCell>
											<TableCell>
												{payment.invoiceAvailable
													? "Disponible"
													: "—"}
											</TableCell>
											<TableCell className="text-right">
												<Button plain disabled className="!text-sm">
													Ver detalle
												</Button>
											</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</div>

						<div className="divide-y divide-slate-100 lg:hidden">
							{filteredPayments.map((payment) => (
								<div key={payment.id} className="space-y-2 p-4">
									<div className="flex items-center justify-between gap-3">
										<p className="font-medium">{payment.concept}</p>
										<Badge
											color={
												STATUS_COLORS[payment.statusKey] ??
												"zinc"
											}
										>
											{payment.status}
										</Badge>
									</div>
									<div className="flex justify-between text-sm text-zinc-500">
										<span>{payment.date}</span>
										<span className="font-medium text-zinc-800">
											{payment.amount}
										</span>
									</div>
									<Text className="text-xs text-zinc-500">
										{payment.method} · {payment.provider}
									</Text>
								</div>
							))}
						</div>
					</>
				)}
			</Card>
		</div>
	);
}
