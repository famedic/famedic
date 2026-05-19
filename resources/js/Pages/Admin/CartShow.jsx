import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	DescriptionDetails,
	DescriptionList,
	DescriptionTerm,
} from "@/Components/Catalyst/description-list";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	ArrowLeftIcon,
	CalendarDaysIcon,
	CheckCircleIcon,
	ClockIcon,
	ShoppingCartIcon,
	UserIcon,
} from "@heroicons/react/16/solid";

function statusBadge(displayStatus) {
	if (displayStatus === "completed") {
		return { color: "blue", label: "Comprado" };
	}
	if (displayStatus === "abandoned") {
		return { color: "red", label: "Abandonado" };
	}
	return { color: "green", label: "Activo" };
}

function InfoCard({ title, children }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-600/80 dark:bg-zinc-800/90">
			<Subheading className="mb-3">{title}</Subheading>
			{children}
		</div>
	);
}

export default function CartShow({ cart }) {
	const b = statusBadge(cart.display_status);
	const isLab = cart.type === "lab";

	return (
		<AdminLayout title={`Carrito #${cart.id}`}>
			<div className="space-y-6">
				<Button
					href={route("admin.carts.index")}
					outline
					className="inline-flex items-center gap-2"
				>
					<ArrowLeftIcon className="size-4" />
					Volver al listado
				</Button>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex items-center gap-2">
							<ShoppingCartIcon className="size-6 text-zinc-500 dark:text-zinc-400" />
							<Heading>Detalle de carrito #{cart.id}</Heading>
						</div>
						{cart.user ? (
							<Text className="text-sm text-zinc-600 dark:text-zinc-300">
								<Strong>
									{cart.user.full_name || cart.user.email}
								</Strong>
								{cart.user.email && <> · {cart.user.email}</>}
								{cart.user.phone && <> · {cart.user.phone}</>}
							</Text>
						) : (
							<Text className="text-sm">Usuario no disponible</Text>
						)}
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<Badge color="slate">{cart.type_label}</Badge>
						{cart.lab_brands?.map((brand) => (
							<Badge key={brand.value} color="zinc">
								{brand.label}
							</Badge>
						))}
						<Badge color={b.color}>{b.label}</Badge>
						{cart.appointment_pending_confirmation && (
							<Badge color="amber">Cita por confirmar</Badge>
						)}
						{cart.appointment_confirmed_pending_payment && (
							<Badge color="violet">Cita confirmada, sin pago</Badge>
						)}
					</div>
				</div>

				<div className="grid gap-4 lg:grid-cols-2">
					<InfoCard title="Resumen">
						<DescriptionList>
							<DescriptionTerm>Ítems en snapshot</DescriptionTerm>
							<DescriptionDetails>{cart.items_count}</DescriptionDetails>

							<DescriptionTerm>Total</DescriptionTerm>
							<DescriptionDetails>
								<Strong>{cart.total_formatted}</Strong>
							</DescriptionDetails>

							<DescriptionTerm>Estatus visible</DescriptionTerm>
							<DescriptionDetails>{b.label}</DescriptionDetails>

							<DescriptionTerm>Estado en monitoreo</DescriptionTerm>
							<DescriptionDetails>
								{cart.monitoring_status_label}
							</DescriptionDetails>

							<DescriptionTerm>Creado</DescriptionTerm>
							<DescriptionDetails>
								{cart.created_at_human ?? "—"}
							</DescriptionDetails>

							<DescriptionTerm>Última actividad</DescriptionTerm>
							<DescriptionDetails>
								{cart.updated_at_human ?? "—"}
							</DescriptionDetails>

							{cart.completed_at_human && (
								<>
									<DescriptionTerm>Completado</DescriptionTerm>
									<DescriptionDetails>
										{cart.completed_at_human}
									</DescriptionDetails>
								</>
							)}

							{cart.display_status === "abandoned" && (
								<>
									<DescriptionTerm>Umbral de abandono</DescriptionTerm>
									<DescriptionDetails>
										{cart.abandoned_threshold_minutes} min sin
										actividad
									</DescriptionDetails>
								</>
							)}
						</DescriptionList>
					</InfoCard>

					<InfoCard title="Enlaces relacionados">
						<div className="flex flex-col gap-2">
							{cart.user?.admin_url && (
								<Button
									href={cart.user.admin_url}
									outline
									className="justify-start"
								>
									<UserIcon className="size-4" />
									Ver perfil de usuario
								</Button>
							)}
							{cart.related_laboratory_purchase?.admin_url && (
								<Button
									href={cart.related_laboratory_purchase.admin_url}
									outline
									className="justify-start"
								>
									<CheckCircleIcon className="size-4" />
									Ver pedido de laboratorio #
									{cart.related_laboratory_purchase.id}
								</Button>
							)}
							{!cart.related_laboratory_purchase &&
								cart.display_status === "completed" &&
								isLab && (
									<Text className="text-sm text-zinc-500 dark:text-zinc-400">
										No se encontró un pedido de laboratorio
										vinculado en la ventana de fechas del
										carrito.
									</Text>
								)}
							{!cart.user?.admin_url && (
								<Text className="text-sm text-zinc-500 dark:text-zinc-400">
									Sin usuario asociado.
								</Text>
							)}
						</div>
					</InfoCard>
				</div>

				{isLab && cart.related_laboratory_purchase && (
					<InfoCard title="Pedido de laboratorio vinculado">
						<DescriptionList>
							<DescriptionTerm>ID</DescriptionTerm>
							<DescriptionDetails>
								#{cart.related_laboratory_purchase.id}
							</DescriptionDetails>
							<DescriptionTerm>Marca</DescriptionTerm>
							<DescriptionDetails>
								{cart.related_laboratory_purchase.brand_label}
							</DescriptionDetails>
							<DescriptionTerm>Fecha de compra</DescriptionTerm>
							<DescriptionDetails>
								{cart.related_laboratory_purchase.created_at_human}
							</DescriptionDetails>
							<DescriptionTerm>Total del pedido</DescriptionTerm>
							<DescriptionDetails>
								{cart.related_laboratory_purchase.total_formatted}
							</DescriptionDetails>
						</DescriptionList>
					</InfoCard>
				)}

				{isLab && cart.laboratory_appointments?.length > 0 && (
					<InfoCard title="Citas de laboratorio relacionadas">
						<div className="overflow-x-auto">
							<Table>
								<TableHead>
									<TableRow>
										<TableHeader>Marca</TableHeader>
										<TableHeader>Paciente</TableHeader>
										<TableHeader>Estatus</TableHeader>
										<TableHeader>Fecha de cita</TableHeader>
										<TableHeader></TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{cart.laboratory_appointments.map((appointment) => (
										<TableRow key={appointment.id}>
											<TableCell>
												{appointment.brand_label}
											</TableCell>
											<TableCell>
												{appointment.patient_name ?? "—"}
											</TableCell>
											<TableCell>
												<div className="flex flex-wrap gap-1">
													{appointment.is_confirmed ? (
														<Badge color="green">
															Confirmada
														</Badge>
													) : (
														<Badge color="amber">
															Por confirmar
														</Badge>
													)}
													{appointment.has_linked_purchase && (
														<Badge color="blue">
															Con pedido
														</Badge>
													)}
												</div>
											</TableCell>
											<TableCell>
												<div className="flex items-center gap-1 text-sm">
													<CalendarDaysIcon className="size-4 text-zinc-400" />
													{appointment.appointment_date_human ??
														"Sin fecha"}
												</div>
											</TableCell>
											<TableCell>
												<Button
													href={appointment.admin_url}
													outline
													size="sm"
												>
													Ver cita
												</Button>
											</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</div>
					</InfoCard>
				)}

				<InfoCard title="Productos (snapshot de monitoreo)">
					{cart.items.length === 0 ? (
						<Text className="text-sm text-zinc-500 dark:text-zinc-400">
							No hay ítems registrados en el snapshot de este
							carrito.
						</Text>
					) : (
						<>
							<div className="overflow-x-auto">
								<Table>
									<TableHead>
										<TableRow>
											<TableHeader>Producto</TableHeader>
											{isLab && (
												<TableHeader>Marca</TableHeader>
											)}
											{isLab && (
												<TableHeader>Cita</TableHeader>
											)}
											<TableHeader className="text-right">
												Cantidad
											</TableHeader>
											<TableHeader className="text-right">
												Precio unit.
											</TableHeader>
											<TableHeader className="text-right">
												Subtotal
											</TableHeader>
										</TableRow>
									</TableHead>
									<TableBody>
										{cart.items.map((row) => (
											<TableRow key={row.id}>
												<TableCell>{row.name}</TableCell>
												{isLab && (
													<TableCell>
														{row.brand_label ? (
															<Badge color="slate">
																{row.brand_label}
															</Badge>
														) : (
															"—"
														)}
													</TableCell>
												)}
												{isLab && (
													<TableCell>
														{row.requires_appointment ? (
															<Badge color="amber">
																<ClockIcon className="size-3" />
																Requiere cita
															</Badge>
														) : (
															<Text className="text-xs text-zinc-500">
																No requiere
															</Text>
														)}
													</TableCell>
												)}
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
							<div className="mt-4 flex justify-end border-t border-zinc-200 pt-4 dark:border-zinc-600/80">
								<Text className="text-zinc-950 dark:text-zinc-50">
									<Strong>Total: </Strong>
									{cart.total_formatted}
								</Text>
							</div>
						</>
					)}
				</InfoCard>
			</div>
		</AdminLayout>
	);
}
