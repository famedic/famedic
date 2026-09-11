import ApplicationLogo from "@/Components/ApplicationLogo";
import { Text, Strong, TextLink, Anchor } from "@/Components/Catalyst/text";
import { PhoneIcon } from "@heroicons/react/16/solid";
import { Divider } from "@/Components/Catalyst/divider";
import { ArrowRightIcon } from "@heroicons/react/20/solid";
import { Subheading } from "@/Components/Catalyst/heading";
import { NavbarItem } from "@/Components/Catalyst/navbar";
import FooterCopyrights from "@/Components/FooterCopyrights";
import clsx from "clsx";
import CreditCardBrand from "@/Components/CreditCardBrand";

const navigation = {
	legal: [
		{ name: "Política de privacidad", href: route("privacy-policy") },
		{
			name: "Términos y condiciones de servicio",
			href: route("terms-of-service"),
		},
	],
};

export default function Footer({ className, links }) {
	const hideHelpBand = route().current("user.support");

	return (
		<footer className={clsx("pb-5", className)}>
			{!hideHelpBand && (
				<div className="mb-6 mt-6 rounded-2xl border border-emerald-100/80 bg-emerald-50/50 px-4 py-3.5 sm:px-5 dark:border-emerald-900/40 dark:bg-emerald-950/15">
					<p className="flex flex-wrap items-center justify-center gap-x-1.5 gap-y-1 text-sm text-zinc-700 dark:text-zinc-300">
						<PhoneIcon
							className="size-5 shrink-0 text-teal-700 dark:text-teal-400"
							aria-hidden="true"
						/>
						<span>¿Necesitas ayuda? Puedes contactarnos al</span>
						<Anchor
							href="tel:8128601893"
							className="font-semibold text-teal-700 no-underline hover:underline dark:text-teal-300"
						>
							81 2860 1893
						</Anchor>
					</p>
				</div>
			)}

			<Divider className="mb-6" />

			<div className="xl:grid xl:grid-cols-3 xl:gap-8">
				<div className="space-y-2">
					<NavbarItem className="inline-block" href="/">
						<ApplicationLogo className="h-6 w-auto" />
						<Text>
							<Strong className="!font-poppins">Famedic</Strong>
						</Text>
					</NavbarItem>
					<div className="space-y-1.5">
						<Text className="font-poppins text-sm">
							Salud y tecnología a bajo costo.
						</Text>
						<Text className="font-poppins text-sm">
							Servicios con cobertura en todo México.
						</Text>
						<div className="mt-2 flex items-center gap-4">
							<svg
								className="size-7"
								viewBox="0 0 60 36"
								fill="none"
								xmlns="http://www.w3.org/2000/svg"
								aria-hidden="true"
							>
								<rect width="20" height="36" fill="#006341" />
								<rect x="20" width="20" height="36" fill="white" />
								<rect x="40" width="20" height="36" fill="#C8102E" />
								<g transform="translate(30, 18)">
									<circle
										r="7"
										fill="#8C9157"
										stroke="#006341"
										strokeWidth="0.5"
									/>
									<circle r="4.5" fill="#006341" />
									<circle r="2.5" fill="white" />
								</g>
							</svg>
						</div>
					</div>
				</div>
				<div className="mt-8 grid gap-8 sm:grid-cols-2 lg:col-span-2 xl:mt-0">
					{links}
					<div>
						<Subheading className="!text-sm">Legal</Subheading>

						<ul role="list" className="mt-4 space-y-2.5">
							{navigation.legal.map((item) => (
								<li key={item.name}>
									<Text className="text-sm">
										<TextLink
											className="group flex items-center no-underline hover:underline"
											href={item.href}
										>
											{item.name}
											<ArrowRightIcon className="ml-1 size-4 opacity-0 transition-all duration-300 group-hover:opacity-100" />
										</TextLink>
									</Text>
								</li>
							))}
						</ul>
					</div>
				</div>
			</div>
			<div className="mt-8 space-y-2.5">
				<div className="flex gap-3">
					<CreditCardBrand brand="visa" />
					<CreditCardBrand brand="mastercard" />
					<CreditCardBrand brand="amex" />
					<img
						src="/images/odessa.png"
						alt="odessa"
						className="size-6"
					/>
				</div>
				<FooterCopyrights />
			</div>
		</footer>
	);
}
