const actions = [
	{
		title: "Mis pedidos",
		description: "Consulta tus pedidos de laboratorio y farmacia en línea.",
		href: route("laboratory-purchases.index"),
		icon: ShoppingBagIcon,
	},
	{
		title: "Mi familia",
		description: "Consulta y gestiona los miembros de tu familia.",
		href: route("family.index"),
		icon: UsersIcon,
	},
	{
		title: "Mis perfiles fiscales",
		description: "Consulta tus facturas y gestiona tus perfiles fiscales.",
		href: route("tax-profiles.index"),
		icon: BuildingLibraryIcon,
	},
	{
		title: "Mis direcciones",
		description: "Consulta y gestiona tus direcciones.",
		href: route("addresses.index"),
		icon: MapIcon,
	},
	{
		title: "Métodos de pago",
		description: "Consulta y gestiona tus métodos de pago.",
		href: route("payment-methods.index"),
		icon: CreditCardIcon,
	},
	{
		title: "Mis pacientes frecuentes",
		description: "Consulta y gestiona tus pacientes registrados.",
		href: route("contacts.index"),
		icon: IdentificationIcon,
	},
];

export default function QuickLinks() {
	return (
		<div>
			<Heading>Ligas rápidas</Heading>
			<div className="mt-4 grid gap-6 rounded-lg sm:grid-cols-2 md:grid-cols-3">
				{actions.map((action) => (
					<Card
						hoverable
						href={action.href}
						key={action.title}
						className="group relative p-6 lg:h-auto"
					>
						<span className="inline-flex rounded-lg p-3 text-gray-200 ring-4 ring-gray-200 sm:group-hover:scale-110 sm:group-hover:text-famedic-dark sm:group-hover:ring-famedic-dark dark:text-slate-700 dark:ring-slate-700 dark:sm:group-hover:text-white dark:sm:group-hover:ring-white">
							<action.icon
								aria-hidden="true"
								className="h-6 w-6"
							/>
						</span>
						<div className="mt-8">
							<Subheading className="sm:group-hover:underline">
								{action.title}
							</Subheading>
							<Text className="mt-2">{action.description}</Text>
						</div>

						{/* Corner arrow */}
						<span
							aria-hidden="true"
							className="pointer-events-none absolute right-6 top-6 text-gray-300 transition-transform sm:group-hover:-translate-y-1 sm:group-hover:translate-x-1 sm:group-hover:scale-110 sm:group-hover:transform sm:group-hover:text-famedic-dark dark:text-slate-700 dark:sm:group-hover:text-white"
						>
							<svg
								fill="currentColor"
								viewBox="0 0 24 24"
								className="h-6 w-6"
							>
								<path d="M20 4h1a1 1 0 00-1-1v1zm-1 12a1 1 0 102 0h-2zM8 3a1 1 0 000 2V3zM3.293 19.293a1 1 0 101.414 1.414l-1.414-1.414zM19 4v12h2V4h-2zm1-1H8v2h12V3zm-.707.293l-16 16 1.414 1.414 16-16-1.414-1.414z" />
							</svg>
						</span>
					</Card>
				))}
			</div>
		</div>
	);
}

import {
	IdentificationIcon,
	CreditCardIcon,
	ShoppingBagIcon,
	UsersIcon,
	MapIcon,
	BuildingLibraryIcon,
} from "@heroicons/react/20/solid";
import Card from "@/Components/Card";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
