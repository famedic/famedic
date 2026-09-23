import { useState } from "react";
import { Subheading } from "@/Components/Catalyst/heading";
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
import { Navbar, NavbarItem } from "@/Components/Catalyst/navbar";
import EmptyListCard from "@/Components/EmptyListCard";
import CustomerActiveCampaignPanel from "@/Components/Admin/CustomerActiveCampaignPanel";
import CustomerAccountPanel from "@/Components/Admin/CustomerAccountPanel";
import {
	ChartCard,
	DASHBOARD_COLORS,
} from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import { laboratoryBrandImageSrc } from "@/lib/laboratoryBrand";
import {
	CalendarIcon,
	CreditCardIcon,
	MapPinIcon,
	ShoppingCartIcon,
	BellAlertIcon,
} from "@heroicons/react/16/solid";

const INTERACTION_SERIES = [
	{ key: "purchases", label: "Compras", color: DASHBOARD_COLORS.green },
	{ key: "payments", label: "Pagos", color: DASHBOARD_COLORS.blue },
	{ key: "carts", label: "Carritos", color: DASHBOARD_COLORS.purple },
	{ key: "appointments", label: "Citas", color: DASHBOARD_COLORS.orange },
	{ key: "marketing", label: "Marketing", color: DASHBOARD_COLORS.slate },
	{ key: "notifications", label: "Notificaciones", color: DASHBOARD_COLORS.red },
];

export default function CustomerMonitorPanel({
	customer,
	canViewCartDetails,
	canViewTaxProfilesAdmin,
	canViewPaymentAttempts,
	paymentAttempts,
	efevooTokens,
	efevooTransactions,
	laboratoryNotifications,
	unreadLabNotificationsCount,
	pendingActivity = [],
	pendingPurchasesSummary = {},
	interactionSummary = {},
	recentActivity = [],
	notificationGroups = [],
	platformAccess = null,
	activeCampaignMirror,
	activeCampaignDispatches,
	activeCampaignContactUrl,
	canViewActiveCampaignHub,
	taxRegimes = {},
	laboratoryPurchases,
	onlinePharmacyPurchases,
	medicalAttentionSubscriptions,
	// passthrough for payments cards
	PaymentAttemptsCard,
	EfevooTokensCard,
	EfevooTransactionsCard,
	PurchaseTabs,
}) {
	const [activeMonitorTab, setActiveMonitorTab] = useState("overview");
	const pendingCount =
		pendingPurchasesSummary?.total ?? pendingActivity.length ?? 0;

	const counts = {
		taxProfiles: customer.tax_profiles_count ?? customer.tax_profiles?.length ?? 0,
		addresses: customer.addresses_count ?? customer.addresses?.length ?? 0,
		appointments:
			customer.laboratory_appointments_count ??
			customer.laboratory_appointments?.length ??
			0,
		pendingPurchases: pendingCount,
		payments: paymentAttempts.length,
		notifications: laboratoryNotifications.length,
	};

	return (
		<div className="space-y-5">
			<div className="flex flex-wrap items-end justify-between gap-4">
				<div>
					<Subheading>Monitor del cliente</Subheading>
					{platformAccess?.last_access_at && (
						<Text className="mt-1 text-sm text-zinc-500">
							Último acceso: {platformAccess.last_access_at}
						</Text>
					)}
				</div>
			</div>

			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
				<MonitorStat label="Perfiles fiscales" value={counts.taxProfiles} />
				<MonitorStat label="Direcciones" value={counts.addresses} />
				<MonitorStat label="Citas" value={counts.appointments} />
				<MonitorStat label="Compras pendientes" value={counts.pendingPurchases} />
				<MonitorStat label="Intentos de pago" value={counts.payments} />
				<MonitorStat
					label="Notif. laboratorio"
					value={counts.notifications}
					aside={`${unreadLabNotificationsCount} sin leer`}
				/>
			</div>

			<Navbar className="-mt-1">
				<NavbarItem
					onClick={() => setActiveMonitorTab("overview")}
					current={activeMonitorTab === "overview"}
				>
					Resumen
				</NavbarItem>
				<NavbarItem
					onClick={() => setActiveMonitorTab("purchases")}
					current={activeMonitorTab === "purchases"}
				>
					Historial de compras
				</NavbarItem>
				<NavbarItem
					onClick={() => setActiveMonitorTab("account")}
					current={activeMonitorTab === "account"}
				>
					Cuenta
				</NavbarItem>
				<NavbarItem
					onClick={() => setActiveMonitorTab("payments")}
					current={activeMonitorTab === "payments"}
				>
					Pagos
				</NavbarItem>
				<NavbarItem
					onClick={() => setActiveMonitorTab("marketing")}
					current={activeMonitorTab === "marketing"}
				>
					ActiveCampaign
				</NavbarItem>
			</Navbar>

			{activeMonitorTab === "overview" && (
				<div className="space-y-5">
					<InteractionSummaryBar summary={interactionSummary} />
					<RecentActivityStrip activities={recentActivity} />
					<PendingActivityStrip
						items={pendingActivity}
						canViewCartDetails={canViewCartDetails}
					/>
					<AppointmentsPanel
						appointments={customer.laboratory_appointments ?? []}
					/>
					<NotificationGroupsPanel groups={notificationGroups} />
				</div>
			)}

			{activeMonitorTab === "purchases" && (
				<PurchaseTabs
					laboratoryPurchases={laboratoryPurchases}
					onlinePharmacyPurchases={onlinePharmacyPurchases}
					medicalAttentionSubscriptions={medicalAttentionSubscriptions}
				/>
			)}

			{activeMonitorTab === "account" && (
				<CustomerAccountPanel
					customer={customer}
					canManageTaxProfiles={canViewTaxProfilesAdmin}
					taxRegimes={taxRegimes}
				/>
			)}

			{activeMonitorTab === "payments" && (
				<div className="grid gap-4 xl:grid-cols-2">
					<PaymentAttemptsCard
						attempts={paymentAttempts}
						canViewPaymentAttempts={canViewPaymentAttempts}
					/>
					<EfevooTokensCard tokens={efevooTokens} />
					<EfevooTransactionsCard transactions={efevooTransactions} />
				</div>
			)}

			{activeMonitorTab === "marketing" && (
				<CustomerActiveCampaignPanel
					customer={customer}
					mirror={activeCampaignMirror}
					dispatches={activeCampaignDispatches}
					activeCampaignContactUrl={activeCampaignContactUrl}
					canViewActiveCampaignHub={canViewActiveCampaignHub}
				/>
			)}
		</div>
	);
}

function MonitorStat({ label, value, aside }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-xs text-zinc-500">{label}</Text>
			<div className="mt-1 flex items-end justify-between gap-2">
				<div className="text-2xl font-semibold text-zinc-950 dark:text-white">
					{value ?? 0}
				</div>
				{aside && <Text className="text-xs text-zinc-500">{aside}</Text>}
			</div>
		</div>
	);
}

function InteractionSummaryBar({ summary = {} }) {
	const total = summary.total ?? 0;
	const max = Math.max(
		...INTERACTION_SERIES.map((s) => summary[s.key] ?? 0),
		1,
	);

	return (
		<ChartCard
			title="Interacciones (30 días)"
			description="Distribución por tipo — más legible cuando la actividad es esporádica."
		>
			{total === 0 ? (
				<Text className="text-sm text-zinc-500">Sin interacciones en el periodo.</Text>
			) : (
				<div className="space-y-3">
					{INTERACTION_SERIES.map((series) => {
						const value = summary[series.key] ?? 0;
						const width = `${Math.max(4, (value / max) * 100)}%`;

						return (
							<div key={series.key} className="grid grid-cols-[7rem_minmax(0,1fr)_2.5rem] items-center gap-3">
								<Text className="text-xs text-zinc-500">{series.label}</Text>
								<div className="h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
									<div
										className="h-full rounded-full transition-all"
										style={{ width, backgroundColor: series.color }}
									/>
								</div>
								<Text className="text-right text-sm font-semibold">{value}</Text>
							</div>
						);
					})}
				</div>
			)}
		</ChartCard>
	);
}

function RecentActivityStrip({ activities = [] }) {
	return (
		<SectionShell title="Actividad reciente">
			{activities.length === 0 ? (
				<Text className="text-sm text-zinc-500">Sin actividad reciente.</Text>
			) : (
				<div className="flex gap-3 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:thin]">
					{activities.slice(0, 12).map((activity, index) => (
						<div
							key={`${activity.category}-${activity.at}-${index}`}
							className="min-w-[240px] max-w-[280px] shrink-0 snap-start rounded-xl border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-800/40"
						>
							<div className="flex items-center gap-2">
								<Badge color={activity.category === "access" ? "emerald" : "sky"}>
									{activity.category_label}
								</Badge>
							</div>
							<Text className="mt-2 text-sm font-medium">{activity.label}</Text>
							{activity.detail && (
								<Text className="mt-1 line-clamp-2 text-xs text-zinc-500">
									{activity.detail}
								</Text>
							)}
							<Text className="mt-2 text-xs text-zinc-400">{activity.at || "—"}</Text>
						</div>
					))}
				</div>
			)}
		</SectionShell>
	);
}

const pendingStatusColors = {
	cart_saved: "sky",
	checkout_in_progress: "famedic-lime",
	appointment_pending: "amber",
	payment_pending: "emerald",
	abandoned: "red",
	active: "green",
};

function PendingActivityStrip({ items = [], canViewCartDetails }) {
	return (
		<SectionShell
			title="Compras y carritos pendientes"
			icon={ShoppingCartIcon}
		>
			{items.length === 0 ? (
				<Text className="text-sm text-zinc-500">
					No hay compras ni carritos pendientes.
				</Text>
			) : (
				<div className="flex gap-3 overflow-x-auto pb-1">
					{items.map((item) => {
						const brandValue =
							item.brand?.value ??
							(item.type === "pharmacy" ? "pharmacy" : item.brand?.value);
						const logoSrc =
							item.type === "pharmacy"
								? "/images/gda/GDA-OLAB.png"
								: laboratoryBrandImageSrc(brandValue);
						const status = item.status ?? item.monitoring_cart?.display_status;
						const badgeColor =
							pendingStatusColors[status] ?? pendingStatusColors.cart_saved;

						return (
							<div
								key={item.key}
								className="min-w-[280px] max-w-[320px] shrink-0 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
							>
								<div className="flex items-start gap-3">
									<img
										src={logoSrc}
										alt={item.brand?.label || "Laboratorio"}
										className="size-10 rounded-lg border border-zinc-100 bg-white object-contain p-1 dark:border-zinc-700"
									/>
									<div className="min-w-0 flex-1">
										<Text className="font-medium">
											{item.brand?.label || "Carrito"}
										</Text>
										<Text className="text-xs text-zinc-500">
											{item.items_count || 0} estudios ·{" "}
											{item.pricing?.formatted_total || "—"}
										</Text>
									</div>
									<Badge color={badgeColor}>
										{item.status_label || status}
									</Badge>
								</div>
								<Text className="mt-3 text-sm text-zinc-700 dark:text-zinc-200">
									{item.intent_label}
								</Text>
								{(item.last_activity_label ||
									item.activity?.last_activity_at) && (
									<Text className="mt-2 text-xs text-zinc-500">
										Última actividad:{" "}
										<Strong>
											{item.last_activity_label ||
												item.activity.last_activity_at}
										</Strong>
									</Text>
								)}
								{item.activity?.inactive_for_label && (
									<Text className="mt-1 text-xs text-rose-600">
										Sin actividad: {item.activity.inactive_for_label}
									</Text>
								)}
								{item.monitoring_cart?.id && canViewCartDetails && (
									<div className="mt-3">
										<Button
											outline
											size="sm"
											href={route("admin.carts.show", {
												cart: item.monitoring_cart.id,
											})}
										>
											Ver carrito
										</Button>
									</div>
								)}
							</div>
						);
					})}
				</div>
			)}
		</SectionShell>
	);
}

const APPOINTMENT_INTERACTION_LABELS = {
	patient_phone_intent: "Intentó llamar",
	patient_whatsapp_intent: "Intentó WhatsApp",
	patient_callback_preference: "Dejó callback",
	concierge_note: "Nota concierge",
	concierge_outbound_call: "Llamada saliente",
	admin_bulk_soft_delete: "Eliminación admin",
};

function appointmentStatusBadge(appointment) {
	if (appointment.laboratory_purchase_id) {
		return { label: "Comprada", color: "famedic-lime" };
	}
	if (appointment.confirmed_at) {
		return { label: "Confirmada", color: "blue" };
	}

	return { label: "Pendiente", color: "amber" };
}

function AppointmentsPanel({ appointments = [] }) {
	return (
		<SectionShell title="Citas de laboratorio" icon={CalendarIcon}>
			{appointments.length === 0 ? (
				<EmptyListCard />
			) : (
				<div className="overflow-x-auto">
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Marca</TableHeader>
								<TableHeader>Paciente</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader>Solicitud</TableHeader>
								<TableHeader>Confirmación</TableHeader>
								<TableHeader>Cita</TableHeader>
								<TableHeader>Sucursal</TableHeader>
								<TableHeader>Contacto</TableHeader>
								<TableHeader>Interacciones</TableHeader>
								<TableHeader className="text-right">Acciones</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{appointments.map((appointment) => {
								const brandValue =
									appointment.brand?.value ?? appointment.brand;
								const logoSrc = laboratoryBrandImageSrc(brandValue);
								const status = appointmentStatusBadge(appointment);
								const store = appointment.laboratory_store;
								const interactions = appointment.interactions ?? [];

								return (
									<TableRow key={appointment.id}>
										<TableCell>
											<div className="flex items-center gap-2">
												<img
													src={logoSrc}
													alt=""
													className="size-8 shrink-0 rounded-lg border border-zinc-100 bg-white object-contain p-0.5"
												/>
												<Text className="text-xs text-zinc-500">
													{appointment.brand?.label ||
														brandValue ||
														"—"}
												</Text>
											</div>
										</TableCell>
										<TableCell>
											<Text className="font-medium">
												{appointment.patient_full_name || "Paciente"}
											</Text>
											{appointment.patient_full_phone && (
												<Text className="text-xs text-zinc-500">
													{appointment.patient_full_phone}
												</Text>
											)}
										</TableCell>
										<TableCell>
											<Badge color={status.color}>{status.label}</Badge>
											{appointment.laboratory_purchase_id && (
												<Text className="mt-1 text-xs text-zinc-500">
													Orden #{appointment.laboratory_purchase_id}
												</Text>
											)}
										</TableCell>
										<TableCell className="whitespace-nowrap text-xs text-zinc-600">
											{appointment.formatted_request_saved_at ||
												appointment.formatted_created_at ||
												"—"}
										</TableCell>
										<TableCell className="whitespace-nowrap text-xs text-zinc-600">
											{appointment.formatted_confirmed_at || "—"}
										</TableCell>
										<TableCell className="whitespace-nowrap text-xs text-zinc-600">
											{appointment.formatted_appointment_date || "Sin fecha"}
										</TableCell>
										<TableCell className="max-w-[220px]">
											{store ? (
												<>
													<Text className="text-xs font-medium">
														{store.name}
													</Text>
													{store.address && (
														<Text className="line-clamp-2 text-xs text-zinc-500">
															{store.address}
														</Text>
													)}
												</>
											) : (
												<Text className="text-xs text-zinc-400">—</Text>
											)}
										</TableCell>
										<TableCell className="min-w-[160px]">
											<div className="flex flex-wrap gap-1">
												{appointment.has_left_callback_info && (
													<Badge color="zinc">
														Callback:{" "}
														{appointment.formatted_callback_availability_range ||
															"Sí"}
													</Badge>
												)}
												{appointment.formatted_phone_call_intent_at && (
													<Badge color="zinc">
														Llamada:{" "}
														{appointment.formatted_phone_call_intent_at}
													</Badge>
												)}
												{!appointment.has_left_callback_info &&
													!appointment.formatted_phone_call_intent_at && (
														<Text className="text-xs text-zinc-400">—</Text>
													)}
											</div>
										</TableCell>
										<TableCell className="min-w-[140px]">
											{interactions.length === 0 ? (
												<Text className="text-xs text-zinc-400">—</Text>
											) : (
												<div className="flex flex-wrap gap-1">
													{interactions.slice(0, 3).map((interaction) => {
														const typeKey =
															interaction.type?.value ?? interaction.type;
														const label =
															APPOINTMENT_INTERACTION_LABELS[typeKey] ||
															typeKey?.replace(/_/g, " ") ||
															"Interacción";

														return (
															<Badge key={interaction.id} color="sky">
																{label}
															</Badge>
														);
													})}
													{interactions.length > 3 && (
														<Badge color="zinc">
															+{interactions.length - 3}
														</Badge>
													)}
												</div>
											)}
										</TableCell>
										<TableCell className="text-right">
											<Button
												outline
												size="sm"
												href={route(
													"admin.laboratory-appointments.show",
													{
														laboratory_appointment: appointment.id,
													},
												)}
											>
												Ver
											</Button>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</div>
			)}
		</SectionShell>
	);
}

function NotificationSummaryCard({ title, value }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-zinc-50/80 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800/40">
			<Text className="text-xs text-zinc-500">{title}</Text>
			<Text className="mt-0.5 text-sm font-semibold">{value ?? "—"}</Text>
		</div>
	);
}

function NotificationGroupHeader({ group }) {
	const folio = group.folio || group.gda_order_id;
	const consecutivo = group.gda_consecutivo;

	return (
		<div className="space-y-3">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div className="flex flex-wrap items-center gap-2">
					{group.brand?.image_src && (
						<img
							src={group.brand.image_src}
							alt={group.brand.label || "Marca"}
							className="h-8 w-auto object-contain"
						/>
					)}
					{group.brand?.label && (
						<Badge color="violet">{group.brand.label}</Badge>
					)}
					<Badge color={group.is_gabinete ? "amber" : "sky"}>
						{group.is_gabinete ? "Gabinete" : "Laboratorio"}
					</Badge>
					{consecutivo && (
						<Text className="text-xs text-zinc-600">
							Consecutivo GDA: <Strong>{consecutivo}</Strong>
						</Text>
					)}
					{folio && (
						<Text className="text-xs text-zinc-600">
							Folio / etiqueta GDA: <Strong>{folio}</Strong>
						</Text>
					)}
				</div>
				<div className="flex flex-wrap items-center gap-2">
					<Badge color="sky">{group.count} eventos</Badge>
					{group.monitor_url && (
						<Button outline size="sm" href={group.monitor_url}>
							Ver en monitor
						</Button>
					)}
				</div>
			</div>

			{group.is_gabinete && (
				<Text className="text-xs text-zinc-400">
					El consecutivo corto proviene de infogda_orden; el folio completo es la
					etiqueta GDA (p. ej. GZ0L…).
				</Text>
			)}

			{group.summary && (
				<>
					<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
						<NotificationSummaryCard
							title="Notificaciones toma de muestra"
							value={String(group.summary.sample_notifications ?? 0)}
						/>
						<NotificationSummaryCard
							title="Notificaciones resultados"
							value={String(group.summary.results_notifications ?? 0)}
						/>
						<NotificationSummaryCard
							title="Primera toma de muestra"
							value={group.summary.sample_at}
						/>
						<NotificationSummaryCard
							title="Primeros resultados"
							value={group.summary.results_at}
						/>
					</div>

					<div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
						<NotificationSummaryCard
							title="Tiempo muestra → resultados"
							value={group.summary.diff_label}
						/>
						{group.summary.results_pdf?.label && (
							<div className="flex flex-wrap items-center gap-2">
								<Badge
									color={
										group.summary.results_pdf.is_stale
											? "amber"
											: group.summary.results_pdf.has_pdf_in_storage
												? "famedic-lime"
												: group.summary.results_pdf.available_at_gda
													? "sky"
													: "zinc"
									}
								>
									{group.summary.results_pdf.label}
								</Badge>
							</div>
						)}
					</div>

					{group.summary.emails && (
						<div className="flex flex-wrap gap-2">
							<Badge color="sky">
								Emails muestra enviados:{" "}
								{group.summary.emails.sample_sent_count ?? 0}
							</Badge>
							<Badge color="famedic-lime">
								Emails resultados enviados:{" "}
								{group.summary.emails.results_sent_count ?? 0}
							</Badge>
						</div>
					)}
				</>
			)}
		</div>
	);
}

function NotificationEventDetail({ event }) {
	const email = event.famedic_email ?? {};

	return (
		<div className="mt-4 rounded-xl border border-sky-200 bg-sky-50/60 p-4 dark:border-sky-900 dark:bg-sky-950/30">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div>
					<Text className="font-medium">
						{event.type_label || event.type || "Notificación"}
					</Text>
					{event.linea_negocio && event.linea_negocio !== event.type && (
						<Text className="mt-0.5 text-xs text-zinc-500">
							GDA: {event.linea_negocio}
						</Text>
					)}
				</div>
				<Badge color={email.notified ? "famedic-lime" : "zinc"}>
					{email.notified ? "Paciente notificado" : "Sin email al paciente"}
				</Badge>
			</div>

			<div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
				<NotificationDetailField
					label="Recibido en Famedic"
					value={event.at || "—"}
				/>
				<NotificationDetailField
					label="Email enviado al paciente"
					value={email.sent_at || "—"}
				/>
				<NotificationDetailField
					label="Destinatario"
					value={email.recipient || "—"}
				/>
				<NotificationDetailField
					label="Estado GDA"
					value={event.gda_status || event.status || "—"}
				/>
				{event.gda_consecutivo && (
					<NotificationDetailField
						label="Consecutivo GDA"
						value={event.gda_consecutivo}
					/>
				)}
				{event.infogda_etiqueta && (
					<NotificationDetailField
						label="Etiqueta GDA"
						value={event.infogda_etiqueta}
					/>
				)}
			</div>

			{email.attempted_at && !email.sent_at && (
				<Text className="mt-3 text-xs text-amber-700 dark:text-amber-300">
					Intento de envío: {email.attempted_at}
				</Text>
			)}
			{email.error && (
				<Text className="mt-2 text-xs text-rose-600">
					Error de email: {email.error}
				</Text>
			)}
		</div>
	);
}

function NotificationDetailField({ label, value }) {
	return (
		<div>
			<Text className="text-xs text-zinc-500">{label}</Text>
			<Text className="mt-0.5 text-sm">{value}</Text>
		</div>
	);
}

function NotificationGroupsPanel({ groups = [] }) {
	const [selected, setSelected] = useState(null);

	return (
		<SectionShell title="Notificaciones por orden" icon={BellAlertIcon}>
			{groups.length === 0 ? (
				<EmptyListCard />
			) : (
				<div className="space-y-5">
					{groups.map((group) => {
						const groupSelectionKey = `${group.order_key}`;
						const selectedEvent =
							selected?.groupKey === groupSelectionKey
								? group.events.find((e) => e.id === selected.eventId)
								: null;

						return (
							<div
								key={group.order_key}
								className="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
							>
								<NotificationGroupHeader group={group} />

								<div className="relative mx-2 mt-6 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
									{group.events.map((event, index) => {
										const left =
											group.events.length <= 1
												? 50
												: (index / (group.events.length - 1)) * 100;
										const isSelected = selectedEvent?.id === event.id;

										return (
											<button
												key={event.id}
												type="button"
												title={`${event.type_label || event.type} · ${event.at}`}
												onClick={() =>
													setSelected({
														groupKey: groupSelectionKey,
														eventId: event.id,
													})
												}
												className={`absolute top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 ${
													isSelected
														? "size-4 bg-sky-600 ring-4 ring-sky-200 dark:ring-sky-900"
														: "size-3 bg-sky-500 ring-2 ring-white hover:size-3.5 hover:bg-sky-600 dark:ring-zinc-900"
												}`}
												style={{ left: `${left}%` }}
												aria-label={`Ver detalle: ${event.type_label || event.type}`}
												aria-pressed={isSelected}
											/>
										);
									})}
								</div>

								<ul className="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
									{group.events.map((event) => {
										const isSelected = selectedEvent?.id === event.id;
										const email = event.famedic_email ?? {};

										return (
											<li key={event.id}>
												<button
													type="button"
													onClick={() =>
														setSelected({
															groupKey: groupSelectionKey,
															eventId: event.id,
														})
													}
													className={`flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left text-xs transition-colors ${
														isSelected
															? "bg-sky-50 text-sky-950 dark:bg-sky-950/40 dark:text-sky-100"
															: "text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
													}`}
												>
													<span>
														<Strong>
															{event.type_label || event.type || "Notificación"}
														</Strong>{" "}
														· {event.at}
													</span>
													{email.notified && (
														<Badge color="famedic-lime">Email enviado</Badge>
													)}
												</button>
											</li>
										);
									})}
								</ul>

								{selectedEvent && (
									<NotificationEventDetail event={selectedEvent} />
								)}
							</div>
						);
					})}
				</div>
			)}
		</SectionShell>
	);
}

function SectionShell({ title, icon: Icon, children }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="mb-3 flex items-center gap-2">
				{Icon && <Icon className="size-5 text-zinc-400" />}
				<Subheading>{title}</Subheading>
			</div>
			{children}
		</div>
	);
}
