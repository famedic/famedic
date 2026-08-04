import clsx from "clsx";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Dropdown,
	DropdownButton,
	DropdownItem,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
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
	CreditCardIcon,
	MapPinIcon,
	ShoppingCartIcon,
	UserIcon,
} from "@heroicons/react/16/solid";
import {
	BeakerIcon,
	EllipsisHorizontalIcon,
	PhoneIcon,
} from "@heroicons/react/24/outline";

function statusBadge(displayStatus) {
	if (displayStatus === "completed") {
		return { color: "green", label: "Comprado" };
	}
	if (displayStatus === "abandoned") {
		return { color: "red", label: "Abandonado" };
	}
	return { color: "amber", label: "Activo" };
}

function InfoCard({ title, children, className, action }) {
	return (
		<div
			className={clsx(
				"rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-600/80 dark:bg-zinc-800/90",
				className,
			)}
		>
			{(title || action) && (
				<div className="mb-3 flex items-start justify-between gap-3">
					{title ? <Subheading>{title}</Subheading> : <span />}
					{action}
				</div>
			)}
			{children}
		</div>
	);
}

function AppointmentCheckoutSummary({ appointment, showAppointmentDate = false }) {
	if (!appointment) {
		return (
			<Text className="text-sm text-zinc-500 dark:text-zinc-400">
				Sin solicitud
			</Text>
		);
	}

	const hasActivity =
		appointment.request_saved_at ||
		appointment.has_callback_info ||
		appointment.has_phone_call_intent ||
		(showAppointmentDate && appointment.appointment_date_human);

	return (
		<div className="space-y-1 text-sm">
			{appointment.request_saved_at && (
				<Text className="text-zinc-600 dark:text-zinc-300">
					<Strong>Solicitud:</Strong> {appointment.request_saved_at}
				</Text>
			)}
			{showAppointmentDate && (
				<Text className="text-zinc-600 dark:text-zinc-300">
					<Strong>Fecha de cita:</Strong>{" "}
					{appointment.appointment_date_human ?? "Sin fecha"}
				</Text>
			)}
			{appointment.has_phone_call_intent && (
				<Text className="text-zinc-600 dark:text-zinc-300">
					<Strong>Llamada:</Strong>{" "}
					{appointment.phone_call_intent_at_human ?? "Intentó llamar"}
				</Text>
			)}
			{appointment.callback_availability_range && (
				<Text className="text-zinc-600 dark:text-zinc-300">
					<Strong>Disponibilidad:</Strong>{" "}
					{appointment.callback_availability_range}
				</Text>
			)}
			{appointment.callback_comment && (
				<Text className="text-zinc-600 dark:text-zinc-300">
					<Strong>Comentarios:</Strong> {appointment.callback_comment}
				</Text>
			)}
			{!hasActivity && (
				<Text className="text-zinc-500 dark:text-zinc-400">
					Solicitud registrada, sin actividad adicional
				</Text>
			)}
		</div>
	);
}

function JourneyStepper({ steps }) {
	if (!steps?.length) {
		return null;
	}

	return (
		<ol className="relative mb-5 grid gap-4 sm:grid-cols-5">
			{steps.map((step, index) => {
				const isCompleted = step.status === "completed";
				const isCurrent = step.status === "current";
				const showConnector = index < steps.length - 1;

				return (
					<li key={step.id} className="relative flex flex-col items-center text-center">
						{showConnector && (
							<span
								aria-hidden
								className={clsx(
									"absolute left-[calc(50%+1.1rem)] top-4 hidden h-0.5 w-[calc(100%-2.2rem)] sm:block",
									isCompleted
										? "bg-famedic-dark dark:bg-famedic-light"
										: "bg-zinc-200 dark:bg-zinc-600",
								)}
							/>
						)}
						<span
							className={clsx(
								"relative z-10 flex size-8 items-center justify-center rounded-full text-xs font-semibold",
								isCompleted &&
									"bg-famedic-dark text-white dark:bg-famedic-light dark:text-zinc-900",
								isCurrent &&
									"bg-sky-600 text-white ring-4 ring-sky-100 dark:bg-sky-500 dark:ring-sky-900/40",
								!isCompleted &&
									!isCurrent &&
									"bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300",
							)}
						>
							{isCompleted ? (
								<CheckCircleIcon className="size-4" />
							) : (
								index + 1
							)}
						</span>
						<p className="mt-2 text-xs font-semibold text-zinc-900 dark:text-zinc-50">
							{step.label}
						</p>
						{step.at ? (
							<p className="mt-0.5 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">
								{isCompleted && step.id !== "created"
									? `Completado ${step.at}`
									: step.at}
							</p>
						) : (
							<p className="mt-0.5 text-[11px] text-zinc-400">Pendiente</p>
						)}
					</li>
				);
			})}
		</ol>
	);
}

function StatusPanel({ cart, badge }) {
	const isCompleted = cart.display_status === "completed";
	const isAbandoned = cart.display_status === "abandoned";

	return (
		<div
			className={clsx(
				"rounded-xl border p-5 text-center shadow-sm",
				isCompleted &&
					"border-emerald-200 bg-emerald-50 dark:border-emerald-800/60 dark:bg-emerald-950/30",
				isAbandoned &&
					"border-rose-200 bg-rose-50 dark:border-rose-800/60 dark:bg-rose-950/30",
				!isCompleted &&
					!isAbandoned &&
					"border-amber-200 bg-amber-50 dark:border-amber-800/60 dark:bg-amber-950/30",
			)}
		>
			<div
				className={clsx(
					"mx-auto flex size-12 items-center justify-center rounded-full",
					isCompleted && "bg-emerald-600 text-white",
					isAbandoned && "bg-rose-600 text-white",
					!isCompleted && !isAbandoned && "bg-amber-500 text-white",
				)}
			>
				{isCompleted ? (
					<CheckCircleIcon className="size-7" />
				) : isAbandoned ? (
					<ClockIcon className="size-7" />
				) : (
					<ShoppingCartIcon className="size-7" />
				)}
			</div>
			<p className="mt-3 text-lg font-semibold text-zinc-950 dark:text-zinc-50">
				{badge.label}
			</p>
			<p className="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
				{isCompleted && cart.completed_at_human
					? `Monitoreo completado ${cart.completed_at_human}`
					: isAbandoned && cart.abandoned_at_human
						? `Sin actividad desde ${cart.abandoned_at_human}`
						: cart.monitoring_status_label}
			</p>
		</div>
	);
}

function QuickActions({ cart }) {
	const actions = [];

	if (cart.user?.admin_url) {
		actions.push({
			key: "user",
			href: cart.user.admin_url,
			label: "Ver perfil de usuario",
			icon: UserIcon,
		});
	}

	if (cart.related_laboratory_purchase?.admin_url) {
		actions.push({
			key: "purchase",
			href: cart.related_laboratory_purchase.admin_url,
			label: `Ver pedido de laboratorio #${cart.related_laboratory_purchase.id}`,
			icon: CheckCircleIcon,
		});
	}

	if (cart.laboratory_appointments?.length > 0) {
		actions.push({
			key: "appointments",
			href: "#citas-relacionadas",
			label: "Ver citas relacionadas",
			icon: CalendarDaysIcon,
		});
	}

	if (actions.length === 0) {
		return (
			<Text className="text-sm text-zinc-500 dark:text-zinc-400">
				Sin acciones disponibles.
			</Text>
		);
	}

	return (
		<div className="flex flex-col gap-2">
			{actions.map((action) => (
				<Button
					key={action.key}
					href={action.href}
					outline
					className="justify-start"
				>
					<action.icon className="size-4" />
					{action.label}
				</Button>
			))}
		</div>
	);
}

function Timeline({ events }) {
	if (!events?.length) {
		return (
			<Text className="text-sm text-zinc-500 dark:text-zinc-400">
				Sin eventos registrados.
			</Text>
		);
	}

	return (
		<ol className="space-y-4">
			{events.map((event, index) => (
				<li key={event.id} className="relative flex gap-3">
					<div className="flex flex-col items-center">
						<span className="mt-1 size-2.5 rounded-full bg-famedic-dark dark:bg-famedic-light" />
						{index < events.length - 1 && (
							<span className="mt-1 w-px flex-1 bg-zinc-200 dark:bg-zinc-600" />
						)}
					</div>
					<div className="pb-1">
						<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
							{event.label}
						</p>
						{event.detail && (
							<p className="text-xs text-zinc-500 dark:text-zinc-400">
								{event.detail}
							</p>
						)}
						{event.at && (
							<p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
								{event.at}
							</p>
						)}
					</div>
				</li>
			))}
		</ol>
	);
}

function SummaryGrid({ cart, badge }) {
	const rows = [
		{ label: "Total", value: cart.total_formatted, strong: true },
		{ label: "Items en snapshot", value: cart.items_count },
		{ label: "ID del carrito", value: `#${cart.id}` },
		{ label: "Estado en monitoreo", value: cart.monitoring_status_label },
		{ label: "Creado", value: cart.created_at_human ?? "—" },
		{ label: "Última actividad", value: cart.updated_at_human ?? "—" },
	];

	if (cart.completed_at_human) {
		rows.push({ label: "Completado", value: cart.completed_at_human });
	}

	if (cart.inactive_for_label) {
		rows.push({
			label: "Tiempo sin actividad",
			value: cart.inactive_for_label,
		});
	}

	if (cart.display_status === "abandoned" && cart.abandoned_at_human) {
		rows.push({
			label: "Abandonado desde",
			value: cart.abandoned_at_human,
		});
	}

	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
			<div className="rounded-lg border border-zinc-200/80 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
				<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
					Estatus visible
				</p>
				<div className="mt-2">
					<Badge color={badge.color}>{badge.label}</Badge>
				</div>
			</div>
			{rows.map((row) => (
				<div
					key={row.label}
					className="rounded-lg border border-zinc-200/80 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-900/40"
				>
					<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
						{row.label}
					</p>
					<p
						className={clsx(
							"mt-1 text-sm text-zinc-900 dark:text-zinc-50",
							row.strong && "text-base font-semibold",
						)}
					>
						{row.value}
					</p>
				</div>
			))}
		</div>
	);
}

function CheckoutDraftPanel({ draft, isCompletedCart }) {
	const stepBadgeColor = draft.is_completed || isCompletedCart ? "green" : "sky";
	const stepLabel =
		draft.is_completed || isCompletedCart
			? "Completado"
			: `Paso: ${draft.checkout_step_label}`;

	return (
		<div className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-600/80">
			<div className="mb-4 flex flex-wrap items-center gap-2">
				{draft.brand_label && (
					<Badge color="zinc">{draft.brand_label}</Badge>
				)}
				<Badge color={stepBadgeColor}>{stepLabel}</Badge>
				{draft.updated_at_human && (
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						Actualizado {draft.updated_at_human}
					</Text>
				)}
			</div>

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
				<div>
					<div className="mb-1 flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
						<UserIcon className="size-3.5" />
						Paciente
					</div>
					{draft.patient ? (
						<div className="text-sm">
							<Text>
								<Strong>{draft.patient.full_name}</Strong>
							</Text>
							<Text className="text-zinc-600 dark:text-zinc-300">
								{draft.patient.formatted_gender}
								{draft.patient.formatted_birth_date &&
									` · ${draft.patient.formatted_birth_date}`}
							</Text>
							{draft.patient.phone && (
								<Text className="text-zinc-600 dark:text-zinc-300">
									{draft.patient.phone}
								</Text>
							)}
						</div>
					) : (
						<Text className="text-sm text-zinc-500">
							{draft.patient_name || "Sin seleccionar"}
						</Text>
					)}
				</div>

				<div>
					<div className="mb-1 flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
						<MapPinIcon className="size-3.5" />
						Dirección
					</div>
					{draft.address ? (
						<Text className="text-sm text-zinc-600 dark:text-zinc-300">
							{draft.address.formatted_address ||
								draft.address.full_address}
						</Text>
					) : (
						<Text className="text-sm text-zinc-500">
							{draft.address_short || "Sin seleccionar"}
						</Text>
					)}
				</div>

				<div>
					<div className="mb-1 flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
						<CreditCardIcon className="size-3.5" />
						Método de pago
					</div>
					{draft.payment_method_label ? (
						<Text className="text-sm text-zinc-600 dark:text-zinc-300">
							{draft.payment_method_label}
						</Text>
					) : (
						<Text className="text-sm text-zinc-500">
							Sin seleccionar
						</Text>
					)}
				</div>

				<div>
					<div className="mb-1 flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
						<CalendarDaysIcon className="size-3.5" />
						Cita
					</div>
					<AppointmentCheckoutSummary appointment={draft.appointment} />
				</div>
			</div>
		</div>
	);
}

export default function CartShow({ cart }) {
	const badge = statusBadge(cart.display_status);
	const isLab = cart.type === "lab";
	const isCompleted = cart.display_status === "completed";
	const appointments = cart.laboratory_appointments ?? [];
	const checkoutDrafts = cart.checkout_drafts ?? [];
	const journeySteps = cart.journey_steps ?? [];
	const timeline = cart.timeline ?? [];
	const otherCarts = cart.other_user_carts ?? [];

	return (
		<AdminLayout title={`Carrito #${cart.id}`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-3">
					<Button
						href={route("admin.carts.index")}
						outline
						className="inline-flex items-center gap-2"
					>
						<ArrowLeftIcon className="size-4" />
						Volver al listado
					</Button>

					<Dropdown>
						<DropdownButton outline>
							Acciones
							<EllipsisHorizontalIcon />
						</DropdownButton>
						<DropdownMenu>
							{cart.user?.admin_url && (
								<DropdownItem href={cart.user.admin_url}>
									<UserIcon />
									Ver perfil de usuario
								</DropdownItem>
							)}
							{cart.related_laboratory_purchase?.admin_url && (
								<DropdownItem
									href={cart.related_laboratory_purchase.admin_url}
								>
									<CheckCircleIcon />
									Ver pedido #
									{cart.related_laboratory_purchase.id}
								</DropdownItem>
							)}
							{appointments[0]?.admin_url && (
								<DropdownItem href={appointments[0].admin_url}>
									<CalendarDaysIcon />
									Ver cita relacionada
								</DropdownItem>
							)}
							<DropdownItem href={route("admin.carts.index")}>
								<ShoppingCartIcon />
								Volver a carritos
							</DropdownItem>
						</DropdownMenu>
					</Dropdown>
				</div>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex items-center gap-2">
							<ShoppingCartIcon className="size-6 text-zinc-500 dark:text-zinc-400" />
							<Heading>Detalle del carrito #{cart.id}</Heading>
						</div>
						{cart.user ? (
							<div className="space-y-1">
								<Text className="text-sm text-zinc-600 dark:text-zinc-300">
									<Strong>
										{cart.user.full_name || cart.user.email}
									</Strong>
								</Text>
								<div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
									{cart.user.email && <span>{cart.user.email}</span>}
									{cart.user.phone && (
										<span className="inline-flex items-center gap-1">
											<PhoneIcon className="size-3.5" />
											{cart.user.phone}
										</span>
									)}
								</div>
							</div>
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
						<Badge color={badge.color}>{badge.label}</Badge>
						{cart.appointment_pending_confirmation && (
							<Badge color="amber">Cita por confirmar</Badge>
						)}
						{cart.appointment_confirmed_pending_payment && (
							<Badge color="violet">Cita confirmada, sin pago</Badge>
						)}
					</div>
				</div>

				<div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
					<div className="space-y-4">
						<InfoCard title="Resumen del carrito">
							<SummaryGrid cart={cart} badge={badge} />
						</InfoCard>

						{isLab && cart.related_laboratory_purchase && (
							<InfoCard
								title="Pedido de laboratorio vinculado"
								action={
									<Button
										href={cart.related_laboratory_purchase.admin_url}
										outline
										size="sm"
									>
										Ver pedido relacionado
									</Button>
								}
							>
								<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
									<div>
										<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
											ID del pedido
										</p>
										<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
											#{cart.related_laboratory_purchase.id}
										</p>
									</div>
									<div>
										<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
											Laboratorio
										</p>
										<p className="mt-1 text-sm text-zinc-900 dark:text-zinc-50">
											{cart.related_laboratory_purchase.brand_label}
										</p>
									</div>
									<div>
										<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
											Fecha
										</p>
										<p className="mt-1 text-sm text-zinc-900 dark:text-zinc-50">
											{
												cart.related_laboratory_purchase
													.created_at_human
											}
										</p>
									</div>
									<div>
										<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
											Total
										</p>
										<p className="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
											{
												cart.related_laboratory_purchase
													.total_formatted
											}
										</p>
									</div>
								</div>
							</InfoCard>
						)}

						{isLab &&
							!cart.related_laboratory_purchase &&
							isCompleted && (
								<InfoCard title="Pedido de laboratorio vinculado">
									<Text className="text-sm text-zinc-500 dark:text-zinc-400">
										No se encontró un pedido vinculado en la
										ventana de fechas del carrito.
									</Text>
								</InfoCard>
							)}

						{isLab && appointments.length > 0 && (
							<InfoCard title="Cita de laboratorio relacionada">
								<div id="citas-relacionadas">
									<Table>
										<TableHead>
											<TableRow>
												<TableHeader>Paciente</TableHeader>
												<TableHeader>Estatus</TableHeader>
												<TableHeader>
													Solicitud / disponibilidad
												</TableHeader>
											</TableRow>
										</TableHead>
										<TableBody>
											{appointments.map((appointment) => (
												<TableRow key={appointment.id}>
													<TableCell>
														<div className="space-y-2">
															<div>
																<p className="font-medium">
																	{appointment.patient_name ??
																		"—"}
																</p>
																{appointment.brand_label && (
																	<p className="text-xs text-zinc-500">
																		{
																			appointment.brand_label
																		}
																	</p>
																)}
															</div>
															<Button
																href={appointment.admin_url}
																outline
															>
																Ver cita
															</Button>
														</div>
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
															{appointment.has_phone_call_intent && (
																<Badge color="sky">
																	Intentó llamar
																</Badge>
															)}
															{appointment.has_callback_info && (
																<Badge color="violet">
																	Con disponibilidad
																</Badge>
															)}
														</div>
													</TableCell>
													<TableCell>
														<AppointmentCheckoutSummary
															appointment={appointment}
															showAppointmentDate
														/>
													</TableCell>
												</TableRow>
											))}
										</TableBody>
									</Table>
								</div>
							</InfoCard>
						)}

						{(journeySteps.length > 0 ||
							(isLab && checkoutDrafts.length > 0)) && (
							<InfoCard title="Progreso de checkout">
								{journeySteps.length > 0 && (
									<JourneyStepper steps={journeySteps} />
								)}
								{isLab && checkoutDrafts.length > 0 ? (
									<div className="space-y-4">
										{checkoutDrafts.map((draft) => (
											<CheckoutDraftPanel
												key={draft.id}
												draft={draft}
												isCompletedCart={isCompleted}
											/>
										))}
									</div>
								) : isLab ? (
									<Text className="text-sm text-zinc-500 dark:text-zinc-400">
										Sin detalle de checkout disponible.
									</Text>
								) : null}
							</InfoCard>
						)}

						<InfoCard title="Productos del carrito">
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

					<aside className="space-y-4 xl:sticky xl:top-6 xl:self-start">
						<StatusPanel cart={cart} badge={badge} />

						<InfoCard title="Acciones rápidas">
							<QuickActions cart={cart} />
						</InfoCard>

						<InfoCard title="Línea de tiempo">
							<Timeline events={timeline} />
						</InfoCard>

						<InfoCard title="Carritos relacionados">
							{otherCarts.length === 0 ? (
								<Text className="text-sm text-zinc-500 dark:text-zinc-400">
									No hay otros carritos de monitoreo para este
									usuario.
								</Text>
							) : (
								<ul className="space-y-2">
									{otherCarts.map((other) => {
										const otherBadge = statusBadge(
											other.display_status,
										);

										return (
											<li key={other.id}>
												<a
													href={other.admin_url}
													className="block rounded-lg border border-zinc-200/80 px-3 py-2 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:bg-zinc-900/50"
												>
													<div className="flex items-center justify-between gap-2">
														<span className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
															#{other.id}
														</span>
														<Badge color={otherBadge.color}>
															{other.display_status_label ||
																otherBadge.label}
														</Badge>
													</div>
													<div className="mt-1 flex items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
														<span className="inline-flex items-center gap-1">
															{other.type === "lab" ||
															other.type_label ===
																"Laboratorio" ? (
																<BeakerIcon className="size-3.5" />
															) : (
																<ShoppingCartIcon className="size-3.5" />
															)}
															{other.type_label}
														</span>
														<span>{other.total_formatted}</span>
													</div>
													{other.updated_at_human && (
														<p className="mt-1 text-[11px] text-zinc-400 dark:text-zinc-500">
															Actividad {other.updated_at_human}
														</p>
													)}
												</a>
											</li>
										);
									})}
								</ul>
							)}
						</InfoCard>
					</aside>
				</div>
			</div>
		</AdminLayout>
	);
}
