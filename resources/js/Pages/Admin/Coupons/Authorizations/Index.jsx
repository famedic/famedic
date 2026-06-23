import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { formatShortDateTime } from "@/lib/couponFormat";

function kindBadge(kind) {
	if (kind === "assignment") {
		return <Badge color="amber">Asignación multi-firma</Badge>;
	}
	return <Badge color="purple">Activación de crédito/cupón</Badge>;
}

export default function AuthorizationsIndex({ items = [], actionableCount = 0 }) {
	return (
		<AdminLayout title="Pendientes de autorización">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div className="max-w-2xl">
						<Heading>Pendientes de autorización</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Revisa y decide sobre créditos, cupones y códigos promocionales que requieren tu
							visto bueno.
						</Text>
					</div>
					{actionableCount > 0 && (
						<Badge color="amber">{actionableCount} pendiente(s) de tu acción</Badge>
					)}
				</div>

				{items.length === 0 ? (
					<div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/80 p-10 text-center dark:border-zinc-600 dark:bg-zinc-900/40">
						<Subheading>No hay créditos, cupones o códigos pendientes de autorización.</Subheading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Cuando existan solicitudes pendientes, aparecerán aquí.
						</Text>
					</div>
				) : (
					<div className="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Tipo</TableHeader>
									<TableHeader>Referencia</TableHeader>
									<TableHeader>Monto</TableHeader>
									<TableHeader>Creador</TableHeader>
									<TableHeader>Aprobaciones</TableHeader>
									<TableHeader>Fecha</TableHeader>
									<TableHeader className="text-right">Acción</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{items.map((item) => (
									<TableRow key={item.id}>
										<TableCell className="align-top">
											<div className="flex flex-col gap-1.5">
												<Badge color="zinc">{item.credit_type_label}</Badge>
												{kindBadge(item.kind)}
												{item.i_can_act && <Badge color="amber">Tu acción</Badge>}
											</div>
										</TableCell>
										<TableCell className="align-top">
											<p className="font-medium text-zinc-900 dark:text-zinc-100">{item.title}</p>
											{item.promo_code && (
												<p className="mt-1 font-mono text-xs text-zinc-500">{item.promo_code}</p>
											)}
											{item.description && (
												<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-400 line-clamp-2">
													{item.description}
												</p>
											)}
										</TableCell>
										<TableCell className="align-top">{item.formatted_amount}</TableCell>
										<TableCell className="align-top">
											{item.creator ? (
												<>
													<p className="text-sm">{item.creator.name}</p>
													<p className="text-xs text-zinc-500">{item.creator.email}</p>
												</>
											) : (
												"—"
											)}
										</TableCell>
										<TableCell className="align-top">
											{item.current_approvals}/{item.required_approvals}
											{item.remaining_approvals > 0 && (
												<p className="text-xs text-amber-700 dark:text-amber-300">
													Faltan {item.remaining_approvals}
												</p>
											)}
										</TableCell>
										<TableCell className="align-top text-sm text-zinc-600 dark:text-zinc-400">
											{formatShortDateTime(item.created_at)}
										</TableCell>
										<TableCell className="align-top text-right">
											<Button href={item.show_url} outline>
												Revisar autorización
											</Button>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</div>
				)}
			</div>
		</AdminLayout>
	);
}
