import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

export default function EcommerceTables({
	payment_methods = [],
	top_products = [],
	coupons,
}) {
	const couponSummary = coupons?.summary || [];
	const couponTop = coupons?.top || [];

	return (
		<section className="space-y-6">
			{/* Payment methods */}
			<div className="space-y-3">
				<div className="flex flex-wrap items-center gap-2">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Métodos de pago
					</h2>
					<AnalyticsTruthBadge truth="disponible" />
				</div>
				{payment_methods.length ? (
					<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Table bleed className="[--gutter:theme(spacing.6)]" dense>
							<TableHead>
								<TableRow>
									<TableHeader>Método</TableHeader>
									<TableHeader>Pedidos</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{payment_methods.map((row) => (
									<TableRow key={row.id}>
										<TableCell className="font-medium">{row.label}</TableCell>
										<TableCell className="tabular-nums">
											{row.orders_label}
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</div>
				) : (
					<EmptyListCard
						heading="Sin pagos"
						message="No hay transacciones morph en el periodo."
					/>
				)}
			</div>

			{/* Top products */}
			<div className="space-y-3">
				<div className="flex flex-wrap items-center gap-2">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Top productos
					</h2>
					<AnalyticsTruthBadge truth="disponible" />
				</div>
				{top_products.length ? (
					<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Table bleed className="[--gutter:theme(spacing.6)]" dense>
							<TableHead>
								<TableRow>
									<TableHeader>Producto</TableHeader>
									<TableHeader>Canal</TableHeader>
									<TableHeader>Cantidad</TableHeader>
									<TableHeader>Ingreso</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{top_products.map((row) => (
									<TableRow key={row.id}>
										<TableCell className="max-w-[18rem] font-medium">
											<span className="line-clamp-2">{row.name}</span>
										</TableCell>
										<TableCell>
											<Badge
												color={row.channel === "lab" ? "sky" : "violet"}
											>
												{row.channel_label}
											</Badge>
										</TableCell>
										<TableCell className="tabular-nums">
											{row.quantity_label}
										</TableCell>
										<TableCell className="tabular-nums">
											{row.revenue_label}
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</div>
				) : (
					<EmptyListCard
						heading="Sin productos"
						message="No hay ítems de lab/farmacia en el periodo."
					/>
				)}
			</div>

			{/* Coupons */}
			<div className="space-y-3">
				<div className="flex flex-wrap items-center gap-2">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Cupones
					</h2>
					<AnalyticsTruthBadge truth="disponible" />
				</div>
				{coupons?.note ? (
					<Text className="text-xs text-zinc-500">{coupons.note}</Text>
				) : null}

				<div className="grid gap-4 xl:grid-cols-2">
					<div className="space-y-2">
						<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
							Por canal
						</h3>
						{couponSummary.length ? (
							<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
								<Table bleed className="[--gutter:theme(spacing.6)]" dense>
									<TableHead>
										<TableRow>
											<TableHeader>Canal</TableHeader>
											<TableHeader>Usos</TableHeader>
											<TableHeader>Monto</TableHeader>
										</TableRow>
									</TableHead>
									<TableBody>
										{couponSummary.map((row) => (
											<TableRow key={row.id}>
												<TableCell className="font-medium">
													{row.label}
												</TableCell>
												<TableCell className="tabular-nums">
													{row.uses_label}
												</TableCell>
												<TableCell className="tabular-nums">
													{row.amount_label}
												</TableCell>
											</TableRow>
										))}
									</TableBody>
								</Table>
							</div>
						) : (
							<EmptyListCard
								heading="Sin cupones"
								message="No hay CouponTransaction en el periodo."
							/>
						)}
					</div>

					<div className="space-y-2">
						<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
							Top códigos
						</h3>
						{couponTop.length ? (
							<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
								<Table bleed className="[--gutter:theme(spacing.6)]" dense>
									<TableHead>
										<TableRow>
											<TableHeader>Código</TableHeader>
											<TableHeader>Usos</TableHeader>
											<TableHeader>Monto</TableHeader>
										</TableRow>
									</TableHead>
									<TableBody>
										{couponTop.map((row) => (
											<TableRow key={row.id}>
												<TableCell className="font-medium">
													{row.code}
												</TableCell>
												<TableCell className="tabular-nums">
													{row.uses_label}
												</TableCell>
												<TableCell className="tabular-nums">
													{row.amount_label}
												</TableCell>
											</TableRow>
										))}
									</TableBody>
								</Table>
							</div>
						) : (
							<EmptyListCard
								heading="Sin códigos"
								message="Sin usos de cupón rankeables."
							/>
						)}
					</div>
				</div>
			</div>
		</section>
	);
}
