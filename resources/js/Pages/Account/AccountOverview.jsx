import Card from "@/Components/Card";
import { Link, usePage } from "@inertiajs/react";
import {
	BuildingLibraryIcon,
	ClockIcon,
	CreditCardIcon,
	IdentificationIcon,
	MapPinIcon,
	ShoppingBagIcon,
} from "@heroicons/react/24/outline";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import clsx from "clsx";

function initials(user) {
	const parts = [user?.name, user?.paternal_lastname].filter(Boolean);
	const letters = parts.map((part) => part.trim().charAt(0)).join("");

	return (letters || "U").toUpperCase().slice(0, 2);
}

function phoneLabel(user) {
	if (typeof user?.phone === "string" && user.phone.trim()) {
		return user.phone;
	}

	return user?.full_phone || "";
}

function memberSince(value) {
	if (!value) {
		return null;
	}

	const date = new Date(String(value).replace(" ", "T"));

	if (Number.isNaN(date.getTime())) {
		return null;
	}

	const formatted = new Intl.DateTimeFormat("es-MX", {
		month: "long",
		year: "numeric",
	}).format(date);

	return formatted.charAt(0).toUpperCase() + formatted.slice(1);
}

function StatusPill({ warning = false, children }) {
	return (
		<span
			className={clsx(
				"inline-flex items-center rounded-md px-2 py-1 text-xs font-medium",
				warning
					? "bg-amber-300 text-amber-950"
					: "bg-famedic-lime text-famedic-darker",
			)}
		>
			{children}
		</span>
	);
}

function NextStep({ step }) {
	const className =
		"flex items-center justify-between gap-4 rounded-lg bg-famedic-lime px-4 py-3 text-famedic-darker transition hover:bg-famedic-lime/80";
	const content = (
		<>
			<span>
				<span className="block text-xs font-medium uppercase tracking-wide text-famedic-darker/70">
					Siguiente paso
				</span>
				<span className="mt-0.5 block font-poppins text-sm font-semibold">
					{step.title}
				</span>
				<span className="mt-0.5 block text-sm text-famedic-darker/80">
					{step.detail}
				</span>
			</span>
			<ChevronRightIcon className="size-5 shrink-0 text-famedic-darker" />
		</>
	);

	if (step.href.startsWith("#")) {
		return (
			<a href={step.href} className={className}>
				{content}
			</a>
		);
	}

	return (
		<Link href={step.href} className={className}>
			{content}
		</Link>
	);
}

function countLabel(count, singular, plural) {
	const total = Number(count) || 0;

	return `${total} ${total === 1 ? singular : plural}`;
}

export default function AccountOverview() {
	const {
		auth,
		mustVerifyEmail,
		mustVerifyPhone,
		accountOverview,
		pendingPurchasesSummary,
		medicalAttentionSubscriptionIsActive,
		hasOdessaAfiliateAccount,
	} = usePage().props;

	const user = auth.user;
	const overview = {
		addresses: 0,
		payment_methods: 0,
		tax_profiles: 0,
		contacts: 0,
		orders: 0,
		...accountOverview,
	};
	const pendingPurchases = Number(pendingPurchasesSummary?.total ?? 0);
	const since = memberSince(user?.created_at);
	const phone = phoneLabel(user);

	const nextStep = mustVerifyEmail
		? {
				href: "#contacto",
				title: "Verifica tu correo",
				detail: "Así recibes confirmaciones de pedidos y puedes recuperar el acceso.",
			}
		: mustVerifyPhone
			? {
					href: "#contacto",
					title: "Verifica tu teléfono",
					detail: "Lo usamos para avisarte del estado de tus estudios y compras.",
				}
			: overview.addresses < 1
				? {
						href: route("addresses.index"),
						title: "Agrega una dirección",
						detail: "La vas a necesitar para servicios a domicilio y para facturar.",
					}
				: overview.payment_methods < 1
					? {
							href: route("payment-methods.index"),
							title: "Guarda un método de pago",
							detail: "Tus próximas compras quedan listas en menos pasos.",
						}
					: overview.tax_profiles < 1
						? {
								href: route("tax-profiles.index"),
								title: "Crea un perfil fiscal",
								detail: "Tenlo listo para facturar una compra cuando la necesites.",
							}
						: null;

	const shortcuts = [
		{
			label: "Mis pedidos",
			value: countLabel(overview.orders, "pedido", "pedidos"),
			href: route("laboratory-purchases.index"),
			icon: ShoppingBagIcon,
		},
		{
			label: "Compras pendientes",
			value:
				pendingPurchases > 0
					? countLabel(pendingPurchases, "por terminar", "por terminar")
					: "Nada pendiente",
			href: route("user.purchases.index"),
			icon: ClockIcon,
			attention: pendingPurchases > 0,
		},
		{
			label: "Direcciones",
			value:
				overview.addresses > 0
					? countLabel(overview.addresses, "guardada", "guardadas")
					: "Agregar dirección",
			href: route("addresses.index"),
			icon: MapPinIcon,
			attention: overview.addresses < 1,
		},
		{
			label: "Métodos de pago",
			value:
				overview.payment_methods > 0
					? countLabel(overview.payment_methods, "tarjeta", "tarjetas")
					: "Agregar tarjeta",
			href: route("payment-methods.index"),
			icon: CreditCardIcon,
			attention: overview.payment_methods < 1,
		},
		{
			label: "Perfiles fiscales",
			value:
				overview.tax_profiles > 0
					? countLabel(overview.tax_profiles, "perfil", "perfiles")
					: "Agregar perfil",
			href: route("tax-profiles.index"),
			icon: BuildingLibraryIcon,
			attention: overview.tax_profiles < 1,
		},
		{
			label: "Pacientes frecuentes",
			value:
				overview.contacts > 0
					? countLabel(overview.contacts, "paciente", "pacientes")
					: "Agregar paciente",
			href: route("contacts.index"),
			icon: IdentificationIcon,
		},
	];

	return (
		<div className="space-y-5">
			<Card className="overflow-hidden dark:!ring-slate-600">
				<div className="bg-famedic-darker px-5 py-6 text-white sm:px-7">
					<div className="flex flex-col gap-5 sm:flex-row sm:items-center">
						<div
							className="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-famedic-lime font-poppins text-xl font-semibold text-famedic-darker"
							aria-hidden="true"
						>
							{initials(user)}
						</div>

						<div className="min-w-0 flex-1">
							<p className="text-xs font-medium uppercase tracking-[0.14em] text-famedic-lime">
								Mi cuenta
							</p>
							<h1 className="mt-1 truncate font-poppins text-2xl font-semibold tracking-tight sm:text-3xl">
								{user.full_name || "Tu perfil"}
							</h1>
							<p className="mt-1 truncate text-sm text-white/70">
								{user.email}
							</p>
							{phone && (
								<p className="truncate text-sm text-white/70">
									{phone}
								</p>
							)}
						</div>

						<div className="flex flex-wrap gap-2 sm:max-w-xs sm:justify-end">
							<StatusPill warning={mustVerifyEmail}>
								{mustVerifyEmail
									? "Correo sin verificar"
									: "Correo verificado"}
							</StatusPill>
							<StatusPill warning={mustVerifyPhone}>
								{mustVerifyPhone
									? "Teléfono sin verificar"
									: "Teléfono verificado"}
							</StatusPill>
							{hasOdessaAfiliateAccount && (
								<StatusPill>Odessa</StatusPill>
							)}
							{medicalAttentionSubscriptionIsActive && (
								<StatusPill>Atención médica activa</StatusPill>
							)}
						</div>
					</div>
				</div>

				<dl className="grid grid-cols-3 divide-x divide-slate-200 bg-white dark:divide-slate-600 dark:bg-slate-800">
					<div className="px-4 py-4 sm:px-6">
						<dt className="text-xs text-zinc-500 dark:text-slate-300">
							Pedidos
						</dt>
						<dd className="mt-1 font-poppins text-lg font-semibold text-famedic-darker dark:text-white">
							{overview.orders}
						</dd>
					</div>
					<div className="px-4 py-4 sm:px-6">
						<dt className="text-xs text-zinc-500 dark:text-slate-300">
							Pendientes
						</dt>
						<dd className="mt-1 font-poppins text-lg font-semibold text-famedic-darker dark:text-white">
							{pendingPurchases}
						</dd>
					</div>
					<div className="px-4 py-4 sm:px-6">
						<dt className="text-xs text-zinc-500 dark:text-slate-300">
							Miembro desde
						</dt>
						<dd className="mt-1 font-poppins text-sm font-semibold leading-tight text-famedic-darker dark:text-white">
							{since || "—"}
						</dd>
					</div>
				</dl>
			</Card>

			{nextStep && <NextStep step={nextStep} />}

			<div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
				{shortcuts.map((item) => (
					<Card
						key={item.label}
						href={item.href}
						hoverable
						className="flex items-center gap-3 p-4 dark:!bg-slate-800 dark:!ring-slate-600"
					>
						<span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-famedic-darker/5 text-famedic-darker dark:bg-famedic-lime/15 dark:text-famedic-lime">
							<item.icon className="size-5" />
						</span>
						<span className="min-w-0 flex-1">
							<span className="block text-sm font-medium text-zinc-600 dark:text-slate-300">
								{item.label}
							</span>
							<span
								className={clsx(
									"block truncate font-poppins text-sm font-semibold",
									item.attention
										? "text-amber-700 dark:text-amber-300"
										: "text-famedic-darker dark:text-white",
								)}
							>
								{item.value}
							</span>
						</span>
						<ChevronRightIcon className="size-4 shrink-0 text-zinc-400 dark:text-slate-300" />
					</Card>
				))}
			</div>
		</div>
	);
}
