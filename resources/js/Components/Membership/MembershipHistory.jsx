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

const STATUS_COLORS = {
	paid: "emerald",
	pending: "amber",
	free: "sky",
};

export default function MembershipHistory({ history = [] }) {
	if (history.length === 0) {
		return (
			<Card className="p-6 shadow-sm ring-1 ring-slate-100 sm:p-8">
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Historial
				</h3>
				<Text className="mt-2 text-sm text-zinc-500">
					Aún no hay movimientos registrados en tu membresía.
				</Text>
			</Card>
		);
	}

	return (
		<section className="space-y-4">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Historial
				</h3>
				<Text className="text-sm text-zinc-500">
					Compras y movimientos de tu membresía.
				</Text>
			</div>

			<Card className="overflow-hidden shadow-sm ring-1 ring-slate-100">
				<div className="hidden sm:block">
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Fecha</TableHeader>
								<TableHeader>Concepto</TableHeader>
								<TableHeader>Monto</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader className="text-right">
									Acciones
								</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{history.map((item) => (
								<TableRow key={item.id}>
									<TableCell>{item.date}</TableCell>
									<TableCell>{item.concept}</TableCell>
									<TableCell>{item.amount}</TableCell>
									<TableCell>
										<Badge
											color={
												STATUS_COLORS[item.statusKey] ??
												"zinc"
											}
										>
											{item.status}
										</Badge>
									</TableCell>
									<TableCell className="text-right">
										<Button
											plain
											disabled
											className="!text-sm"
										>
											Ver detalle
										</Button>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>

				<div className="divide-y divide-slate-100 sm:hidden dark:divide-slate-800">
					{history.map((item) => (
						<div key={item.id} className="space-y-2 p-4">
							<div className="flex items-center justify-between gap-3">
								<p className="font-medium text-zinc-800 dark:text-slate-100">
									{item.concept}
								</p>
								<Badge
									color={
										STATUS_COLORS[item.statusKey] ?? "zinc"
									}
								>
									{item.status}
								</Badge>
							</div>
							<div className="flex items-center justify-between text-sm text-zinc-500">
								<span>{item.date}</span>
								<span className="font-medium text-zinc-700 dark:text-slate-200">
									{item.amount}
								</span>
							</div>
						</div>
					))}
				</div>
			</Card>
		</section>
	);
}
