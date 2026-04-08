import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ArrowLeftIcon, ShoppingCartIcon } from "@heroicons/react/16/solid";

function statusBadge(displayStatus) {
	if (displayStatus === "completed") {
		return { color: "blue", label: "Comprado" };
	}
	if (displayStatus === "abandoned") {
		return { color: "red", label: "Abandonado" };
	}
	return { color: "green", label: "Activo" };
}

export default function CartShow({ cart }) {
	const b = statusBadge(cart.display_status);

	return (
		<AdminLayout title={`Carrito #${cart.id}`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-center gap-4">
					<Button
						href={route("admin.carts.index")}
						outline
						className="inline-flex items-center gap-2"
					>
						<ArrowLeftIcon className="size-4" />
						Volver al listado
					</Button>
				</div>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex items-center gap-2">
							<ShoppingCartIcon className="size-6 text-zinc-500" />
							<Heading>Detalle de carrito</Heading>
						</div>
						<Text className="text-sm text-zinc-600 dark:text-zinc-300">
							{cart.user ? (
								<>
									<Strong>{cart.user.full_name || cart.user.email}</Strong>
									{cart.user.email && (
										<> · {cart.user.email}</>
									)}
								</>
							) : (
								"Usuario no disponible"
							)}
						</Text>
					</div>
					<div className="flex flex-wrap items-center gap-3">
						<Badge color="slate">{cart.type_label}</Badge>
						<Badge color={b.color}>{b.label}</Badge>
						<Text className="text-sm text-zinc-500">
							Última actividad: {cart.updated_at_human}
						</Text>
					</div>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading className="mb-3">Productos</Subheading>
					<div className="overflow-x-auto">
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Producto</TableHeader>
									<TableHeader className="text-right">Cantidad</TableHeader>
									<TableHeader className="text-right">Precio unit.</TableHeader>
									<TableHeader className="text-right">Subtotal</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{cart.items.map((row) => (
									<TableRow key={row.id}>
										<TableCell>{row.name}</TableCell>
										<TableCell className="text-right">
											{row.quantity}
										</TableCell>
										<TableCell className="text-right">
											{row.unit_price_formatted}
										</TableCell>
										<TableCell className="text-right">
											{row.line_total_formatted}
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</div>
					<div className="mt-4 flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-700">
						<Text>
							<Strong>Total: </Strong>
							{cart.total_formatted}
						</Text>
					</div>
				</div>
			</div>
		</AdminLayout>
	);
}
