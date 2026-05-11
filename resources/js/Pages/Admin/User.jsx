import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Avatar } from "@/Components/Catalyst/avatar";
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
import EmptyListCard from "@/Components/EmptyListCard";
import {
	CheckCircleIcon,
	XCircleIcon,
	EnvelopeIcon,
	PhoneIcon,
	UserIcon,
	CalendarIcon,
	CreditCardIcon,
	BellAlertIcon,
	ShoppingCartIcon,
} from "@heroicons/react/16/solid";
import { router } from "@inertiajs/react";

export default function UserPage({
	user,
	customer,
	canViewTaxProfilesAdmin = false,
	efevooTokens,
	efevooTransactions,
	laboratoryNotifications,
	unreadLabNotificationsCount,
	monitoringCarts = null,
	canViewCartDetails = false,
}) {
	return (
		<AdminLayout title={user.full_name || user.email || "Usuario"}>
			<div className="space-y-6">
				<Header user={user} />

				<div className="grid gap-4 md:grid-cols-2">
					<ProfileCard user={user} />
					<CustomerCard customer={customer} />
				</div>

				<div className="grid gap-4 md:grid-cols-2">
					<AddressesCard customer={customer} />
					<ContactsCard customer={customer} />
				</div>

				<TaxProfilesCard
					customer={customer}
					canViewTaxProfilesAdmin={canViewTaxProfilesAdmin}
				/>

				<PurchasesCard customer={customer} />

				{monitoringCarts !== null && (
					<UserCartsSection
						carts={monitoringCarts}
						canViewCartDetails={canViewCartDetails}
					/>
				)}

				<div className="grid gap-4 md:grid-cols-2">
					<EfevooTokensCard tokens={efevooTokens} />
					<EfevooTransactionsCard transactions={efevooTransactions} />
				</div>

				<NotificationsCard
					notifications={laboratoryNotifications}
					unreadCount={unreadLabNotificationsCount}
				/>
			</div>
		</AdminLayout>
	);
}

function Header({ user }) {
	return (
		<div className="flex flex-wrap items-center gap-4">
			<Avatar
				src={user.profile_photo_url}
				alt={user.full_name || user.email}
				className="h-16 w-16"
			/>
			<div className="space-y-1">
				<Heading>{user.full_name || user.email}</Heading>
				<div className="flex flex-wrap gap-2 text-sm text-zinc-600 dark:text-zinc-300">
					{user.email && (
						<span className="inline-flex items-center gap-1">
							<EnvelopeIcon className="size-4" />
							{user.email}
						</span>
					)}
					{user.full_phone && (
						<span className="inline-flex items-center gap-1">
							<PhoneIcon className="size-4" />
							{user.full_phone}
						</span>
					)}
					{user.created_at && (
						<span className="inline-flex items-center gap-1">
							<CalendarIcon className="size-4" />
							Registrado: {user.created_at}
						</span>
					)}
				</div>
			</div>
		</div>
	);
}

function ProfileCard({ user }) {
	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Perfil</Subheading>
			<div className="space-y-1 text-sm">
				<Text>
					<Strong>Nombre completo:</Strong> {user.full_name || "—"}
				</Text>
				<Text>
					<Strong>Género:</Strong> {user.formatted_gender || "—"}
				</Text>
				<Text>
					<Strong>Fecha de nacimiento:</Strong>{" "}
					{user.formatted_birth_date || "—"}
				</Text>
				<Text>
					<Strong>País:</Strong> {user.country || "—"}
				</Text>
				<Text>
					<Strong>Estado:</Strong> {user.state || "—"}
				</Text>
				<Text>
					<Strong>Perfil completo:</Strong>{" "}
					{user.profile_is_complete ? "Sí" : "No"}
				</Text>
			</div>
			<div className="space-y-2">
				<Text className="text-sm font-medium">Verificación</Text>
				<div className="flex flex-wrap gap-2">
					<Badge
						color={user.email_verified_at ? "famedic-lime" : "slate"}
					>
						{user.email_verified_at ? (
							<CheckCircleIcon className="size-4" />
						) : (
							<XCircleIcon className="size-4" />
						)}
						{user.email_verified_at
							? "Correo verificado"
							: "Correo no verificado"}
					</Badge>
					<Badge
						color={user.phone_verified_at ? "famedic-lime" : "slate"}
					>
						{user.phone_verified_at ? (
							<CheckCircleIcon className="size-4" />
						) : (
							<XCircleIcon className="size-4" />
						)}
						{user.phone_verified_at
							? "Teléfono verificado"
							: "Teléfono no verificado"}
					</Badge>
				</div>
			</div>
		</div>
	);
}

function CustomerCard({ customer }) {
	if (!customer) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Subheading>Cliente</Subheading>
				<Text className="text-sm">Este usuario no tiene cliente asociado.</Text>
			</div>
		);
	}

	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Cliente</Subheading>
			<div className="space-y-1 text-sm">
				<Text>
					<Strong>ID cliente:</Strong> {customer.id}
				</Text>
				<Text>
					<Strong>Creado:</Strong> {customer.created_at}
				</Text>
				<Text>
					<Strong>Membresía médica activa:</Strong>{" "}
					{customer.medical_attention_subscription_is_active
						? "Sí"
						: "No"}
				</Text>
			</div>
		</div>
	);
}

function AddressesCard({ customer }) {
	if (!customer || !customer.addresses || customer.addresses.length === 0) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Subheading>Direcciones</Subheading>
				<EmptyListCard />
			</div>
		);
	}

	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Direcciones</Subheading>
			<ul className="space-y-2 text-sm">
				{customer.addresses.map((address) => (
					<li key={address.id}>
						<Text>
							<Strong>{address.alias || "Dirección"}</Strong>
						</Text>
						<Text className="whitespace-pre-line">
							{address.formatted_address || address.full_address}
						</Text>
					</li>
				))}
			</ul>
		</div>
	);
}

function ContactsCard({ customer }) {
	const contacts = customer?.contacts;

	if (!customer) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Subheading>Contactos</Subheading>
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Este usuario no tiene un registro de cliente; los contactos se
					guardan por cliente.
				</Text>
			</div>
		);
	}

	if (!contacts || contacts.length === 0) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Subheading>Contactos</Subheading>
				<Text className="mb-2 text-sm text-zinc-600 dark:text-zinc-400">
					Este cliente no tiene contactos registrados en la cuenta.
				</Text>
				<EmptyListCard />
			</div>
		);
	}

	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Contactos</Subheading>
			<Text className="text-xs text-zinc-500">
				{contacts.length} contacto{contacts.length === 1 ? "" : "s"}
				{contacts.some((c) => c.deleted_at) ? " (algunos eliminados)" : ""}
			</Text>
			<ul className="space-y-3 text-sm">
				{contacts.map((contact) => (
					<li key={contact.id} className="border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
						<div className="flex flex-wrap items-center gap-2">
							<Text>
								<Strong>
									{contact.full_name ||
										[contact.name, contact.paternal_lastname, contact.maternal_lastname]
											.filter(Boolean)
											.join(" ")}
								</Strong>
							</Text>
							{contact.deleted_at && (
								<Badge color="red">Eliminado</Badge>
							)}
						</div>
						<Text className="text-zinc-600 dark:text-zinc-400">
							<Strong>Tel:</Strong>{" "}
							{contact.phone_for_display ||
								(typeof contact.phone === "string"
									? contact.phone
									: "—")}
						</Text>
						{(contact.formatted_birth_date || contact.formatted_gender) && (
							<Text className="text-xs text-zinc-500">
								{contact.formatted_birth_date}
								{contact.formatted_birth_date && contact.formatted_gender
									? " · "
									: ""}
								{contact.formatted_gender}
							</Text>
						)}
					</li>
				))}
			</ul>
		</div>
	);
}

function tipoPersonaLabel(tipo) {
	if (tipo === "fisica") {
		return "Persona física";
	}
	if (tipo === "moral") {
		return "Persona moral";
	}
	return tipo || "—";
}

function formatAdminDateTime(value) {
	if (!value) {
		return "—";
	}
	try {
		const d = new Date(value);
		if (Number.isNaN(d.getTime())) {
			return String(value);
		}
		return d.toLocaleString("es-MX", {
			dateStyle: "medium",
			timeStyle: "short",
		});
	} catch {
		return String(value);
	}
}

function TaxProfilesCard({ customer, canViewTaxProfilesAdmin }) {
	const profiles =
		customer?.tax_profiles ?? customer?.taxProfiles ?? [];

	if (!customer) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Subheading>Perfiles fiscales</Subheading>
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Requiere un cliente asociado al usuario.
				</Text>
			</div>
		);
	}

	if (!profiles || profiles.length === 0) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Subheading>Perfiles fiscales</Subheading>
				<Text className="mb-2 text-sm text-zinc-600 dark:text-zinc-400">
					No hay perfiles en la tabla <Strong>tax_profiles</Strong> para este
					cliente.
				</Text>
				<EmptyListCard />
			</div>
		);
	}

	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<Subheading>Perfiles fiscales</Subheading>
				{canViewTaxProfilesAdmin && (
					<Button
						outline
						size="sm"
						href={route("admin.tax-profiles.show", {
							customer: customer.id,
						})}
					>
						Abrir vista de perfiles fiscales
					</Button>
				)}
			</div>
			<Text className="text-xs text-zinc-500">
				{profiles.length} perfil{profiles.length === 1 ? "" : "es"} (tabla{" "}
				<Strong>tax_profiles</Strong>, <Strong>customer_id</Strong> ={" "}
				{customer.id})
			</Text>
			<ul className="space-y-4 text-sm">
				{profiles.map((p) => (
					<li
						key={p.id}
						className="border-b border-zinc-100 pb-4 last:border-0 last:pb-0 dark:border-zinc-800"
					>
						<div className="flex flex-wrap items-center gap-2">
							<Text className="font-medium text-zinc-900 dark:text-zinc-100">
								{p.razon_social || p.name || "Sin nombre"}
							</Text>
							{p.deleted_at && <Badge color="red">Eliminado</Badge>}
							{p.verificado_automaticamente && (
								<Badge color="emerald">Verificado automáticamente</Badge>
							)}
						</div>
						<div className="mt-2 flex flex-wrap gap-2">
							<Badge color="slate" className="font-normal">
								<CalendarIcon className="size-3.5 shrink-0" />
								Registrado: {formatAdminDateTime(p.created_at)}
							</Badge>
							<Badge color="zinc" className="font-normal">
								<CalendarIcon className="size-3.5 shrink-0" />
								Modificado: {formatAdminDateTime(p.updated_at)}
							</Badge>
						</div>
						<div className="mt-2 grid gap-1 text-zinc-700 dark:text-zinc-300">
							<Text>
								<Strong>RFC:</Strong> {p.rfc || "—"}
							</Text>
							<Text>
								<Strong>Código postal:</Strong> {p.zipcode || "—"}
							</Text>
							<Text>
								<Strong>Tipo:</Strong> {tipoPersonaLabel(p.tipo_persona)}
							</Text>
							<Text>
								<Strong>Régimen:</Strong>{" "}
								{p.formatted_tax_regime || p.tax_regime || "—"}
							</Text>
							<Text>
								<Strong>Uso CFDI:</Strong>{" "}
								{p.formatted_cfdi_use || p.cfdi_use || "—"}
							</Text>
							{p.domicilio_fiscal && (
								<Text className="whitespace-pre-line text-xs text-zinc-600 dark:text-zinc-400">
									<Strong>Domicilio fiscal:</Strong> {p.domicilio_fiscal}
								</Text>
							)}
						</div>
					</li>
				))}
			</ul>
		</div>
	);
}

function PurchasesCard({ customer }) {
	if (!customer) {
		return null;
	}

	const lab = customer.laboratory_purchases || [];
	const pharmacy = customer.online_pharmacy_purchases || [];
	const subs = customer.medical_attention_subscriptions || [];

	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Compras y suscripciones (últimas)</Subheading>

			<Subsection title="Compras de laboratorio" items={lab}>
				{lab.length === 0 ? (
					<EmptyListCard />
				) : (
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>ID</TableHeader>
								<TableHeader>Total</TableHeader>
								<TableHeader>Fecha</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{lab.map((purchase) => (
								<TableRow key={purchase.id}>
									<TableCell>{purchase.id}</TableCell>
									<TableCell>
										{purchase.formatted_total}
									</TableCell>
									<TableCell>
										{purchase.formatted_created_at}
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				)}
			</Subsection>

			<Subsection title="Compras farmacia en línea" items={pharmacy}>
				{pharmacy.length === 0 ? (
					<EmptyListCard />
				) : (
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>ID</TableHeader>
								<TableHeader>Total</TableHeader>
								<TableHeader>Fecha</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{pharmacy.map((purchase) => (
								<TableRow key={purchase.id}>
									<TableCell>{purchase.id}</TableCell>
									<TableCell>
										{purchase.formatted_total}
									</TableCell>
									<TableCell>
										{purchase.formatted_created_at}
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				)}
			</Subsection>

			<Subsection title="Suscripciones médicas" items={subs}>
				{subs.length === 0 ? (
					<EmptyListCard />
				) : (
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>ID</TableHeader>
								<TableHeader>Precio</TableHeader>
								<TableHeader>Inicio</TableHeader>
								<TableHeader>Fin</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{subs.map((sub) => (
								<TableRow key={sub.id}>
									<TableCell>{sub.id}</TableCell>
									<TableCell>{sub.formatted_price}</TableCell>
									<TableCell>{sub.formatted_start_date}</TableCell>
									<TableCell>{sub.formatted_end_date}</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				)}
			</Subsection>
		</div>
	);
}

function Subsection({ title, items, children }) {
	return (
		<div className="space-y-2">
			<Text className="text-sm font-medium">
				{title}{" "}
				<span className="text-xs text-zinc-500">
					({items.length} registro{items.length === 1 ? "" : "s"})
				</span>
			</Text>
			{children}
		</div>
	);
}

function UserCartsSection({ carts, canViewCartDetails }) {
	const active = carts.filter((c) => c.display_status === "active");
	const abandoned = carts.filter((c) => c.display_status === "abandoned");

	const badgeFor = (status) => {
		if (status === "completed") {
			return { color: "blue", label: "Comprado" };
		}
		if (status === "abandoned") {
			return { color: "red", label: "Abandonado" };
		}
		return { color: "green", label: "Activo" };
	};

	return (
		<div className="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex flex-wrap items-center gap-2">
				<ShoppingCartIcon className="size-5 text-zinc-500" />
				<Subheading>Carritos del usuario</Subheading>
			</div>
			<div className="flex flex-wrap gap-3 text-sm text-zinc-600 dark:text-zinc-300">
				<span>
					<Strong>Activos:</Strong> {active.length}
				</span>
				<span>
					<Strong>Abandonados:</Strong> {abandoned.length}
				</span>
			</div>
			{carts.length === 0 ? (
				<Text className="text-sm">No hay carritos registrados en monitoreo.</Text>
			) : (
				<div className="overflow-x-auto">
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Tipo</TableHeader>
								<TableHeader>Productos</TableHeader>
								<TableHeader>Total</TableHeader>
								<TableHeader>Estatus</TableHeader>
								<TableHeader></TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{carts.map((cart) => {
								const b = badgeFor(cart.display_status);
								return (
									<TableRow key={cart.id}>
										<TableCell>{cart.type_label}</TableCell>
										<TableCell>{cart.items_count}</TableCell>
										<TableCell>{cart.total_formatted}</TableCell>
										<TableCell>
											<Badge color={b.color}>{b.label}</Badge>
										</TableCell>
										<TableCell>
											{canViewCartDetails ? (
												<Button
													href={route("admin.carts.show", {
														cart: cart.id,
													})}
													outline
													size="sm"
												>
													Ver detalle
												</Button>
											) : (
												<Text className="text-xs text-zinc-500">
													Sin permiso
												</Text>
											)}
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</div>
			)}
		</div>
	);
}

function EfevooTokensCard({ tokens }) {
	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Tokens de Efevoo</Subheading>
			{tokens.length === 0 ? (
				<EmptyListCard />
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Alias / tarjeta</TableHeader>
							<TableHeader>Entorno</TableHeader>
							<TableHeader>Estatus</TableHeader>
							<TableHeader>Transacciones</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{tokens.map((token) => (
							<TableRow key={token.id}>
								<TableCell>
									<div className="space-y-1">
										<div className="flex items-center gap-2">
											<CreditCardIcon className="size-4 text-zinc-400" />
											<span className="font-medium">
												{token.alias || "Sin alias"}
											</span>
										</div>
										<div className="text-xs text-zinc-500">
											{token.card_brand || "Tarjeta"} ••••{" "}
											{token.card_last_four}
										</div>
									</div>
								</TableCell>
								<TableCell>
									<Badge
										color={
											token.environment === "production"
												? "emerald"
												: "slate"
										}
									>
										{token.environment === "production"
											? "Producción"
											: "Pruebas"}
									</Badge>
								</TableCell>
								<TableCell>
									<Badge
										color={
											token.is_active ? "famedic-lime" : "slate"
										}
									>
										{token.is_active ? "Activo" : "Inactivo"}
									</Badge>
								</TableCell>
								<TableCell>
									<Text className="text-sm">
										{token.transactions_count || 0}
									</Text>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</div>
	);
}

function EfevooTransactionsCard({ transactions }) {
	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Transacciones Efevoo (recientes)</Subheading>
			{transactions.length === 0 ? (
				<EmptyListCard />
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>ID</TableHeader>
							<TableHeader>Referencia</TableHeader>
							<TableHeader>Monto</TableHeader>
							<TableHeader>Estatus</TableHeader>
							<TableHeader>Fecha</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{transactions.map((tx) => (
							<TableRow key={tx.id}>
								<TableCell>{tx.id}</TableCell>
								<TableCell>{tx.reference}</TableCell>
								<TableCell>
									{tx.amount} {tx.currency}
								</TableCell>
								<TableCell>{tx.status}</TableCell>
								<TableCell>{tx.processed_at || tx.created_at}</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</div>
	);
}

function NotificationsCard({ notifications = [], unreadCount = 0 }) {
	const requestResults = (purchaseId) => {
		if (!purchaseId) return;
		router.post(
			route("admin.laboratory-purchases.fetch-results", {
				laboratoryPurchase: purchaseId,
			}),
			{},
			{ preserveScroll: true },
		);
	};

	return (
		<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Notificaciones de laboratorio</Subheading>
			<div className="flex items-center gap-2 text-sm">
				<Badge color={unreadCount > 0 ? "red" : "slate"}>
					<BellAlertIcon className="size-4" />
					{unreadCount} sin leer
				</Badge>
				<Text className="text-xs text-zinc-500">
					Total registradas: {notifications.length}
				</Text>
			</div>
			{notifications.length === 0 ? (
				<EmptyListCard />
			) : (
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Orden</TableHeader>
							<TableHeader>Línea negocio</TableHeader>
							<TableHeader>Tipo</TableHeader>
							<TableHeader>Estatus</TableHeader>
							<TableHeader>Fecha</TableHeader>
							<TableHeader></TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{notifications.map((n) => (
							<TableRow key={n.id}>
								<TableCell>{n.gda_order_id || "—"}</TableCell>
								<TableCell>
									<Text className="text-xs">{n.lineanegocio || "—"}</Text>
								</TableCell>
								<TableCell>{n.notification_type}</TableCell>
								<TableCell>{n.status}</TableCell>
								<TableCell>
									<Text className="text-xs">
										{n.created_at
											? new Date(n.created_at).toLocaleString("es-MX")
											: "—"}
									</Text>
								</TableCell>
								<TableCell>
									{(n.notification_type === "Notificaion-Resultados" ||
										n.lineanegocio === "Notificaion-Resultados") &&
										n.laboratory_purchase_id && (
											<Button
												outline
												size="sm"
												onClick={() =>
													requestResults(n.laboratory_purchase_id)
												}
											>
												Solicitar resultados
											</Button>
										)}
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			)}
		</div>
	);
}

