import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
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
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { Badge } from "@/Components/Catalyst/badge";

export default function CouponsIndex({ coupons }) {
	return (
		<AdminLayout title="Cupones saldo">
			<div className="flex flex-wrap items-center justify-between gap-4">
				<Heading>Cupones saldo</Heading>
				<div className="flex flex-wrap gap-2">
					<Button href={route("admin.coupons.assign")} outline>
						Asignar saldo
					</Button>
					<Button href={route("admin.coupons.import")} outline>
						Importar Excel
					</Button>
					<Button href={route("admin.coupons.create")} color="emerald">
						Nuevo cupón
					</Button>
				</div>
			</div>
			<Text className="mt-2 text-zinc-600">
				Los cupones se usan completos en una sola compra (no hay uso parcial).
			</Text>
			<div className="mt-6">
				<PaginatedTable paginatedData={coupons}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>ID</TableHeader>
								<TableHeader>Código</TableHeader>
								<TableHeader>Monto</TableHeader>
								<TableHeader>Restante</TableHeader>
								<TableHeader>Asignaciones</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{coupons.data.map((c) => (
								<TableRow key={c.id}>
									<TableCell>{c.id}</TableCell>
									<TableCell>{c.code || "—"}</TableCell>
									<TableCell>
										{(c.amount_cents / 100).toLocaleString("es-MX", {
											style: "currency",
											currency: "MXN",
										})}
									</TableCell>
									<TableCell>
										{(c.remaining_cents / 100).toLocaleString("es-MX", {
											style: "currency",
											currency: "MXN",
										})}
									</TableCell>
									<TableCell>{c.coupon_users_count}</TableCell>
									<TableCell>
										{c.is_active ? (
											<Badge color="emerald">Activo</Badge>
										) : (
											<Badge color="zinc">Inactivo</Badge>
										)}
									</TableCell>
									<TableCell className="text-right">
										<Button
											href={route("admin.coupons.edit", c.id)}
											plain
										>
											Editar
										</Button>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
		</AdminLayout>
	);
}
