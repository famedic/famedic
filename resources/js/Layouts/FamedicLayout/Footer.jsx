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
	return (
		<footer className={clsx("pb-6", className)}>
			<div>
				<Divider className="mb-8 mt-8" />
				<div className="flex flex-col flex-wrap items-center justify-center gap-1 sm:flex-row lg:mt-6">
					<PhoneIcon className="hidden size-6 shrink-0 fill-zinc-950 sm:block dark:fill-white" />

					<Text>¿Necesitas ayuda? Puedes contactarnos al </Text>
					<div className="flex items-center gap-1">
						<PhoneIcon className="size-6 shrink-0 fill-zinc-950 sm:hidden dark:fill-white" />

						<Anchor href="tel:8128601893">81 2860 1893</Anchor>
					</div>
				</div>
				<Divider className="mb-8 mt-8" />
			</div>
			<div className="xl:grid xl:grid-cols-3 xl:gap-8">
				<div className="space-y-2">
					<NavbarItem className="inline-block" href="/">
						<ApplicationLogo className="h-6 w-auto" />
						<Text>
							<Strong className="!font-poppins">Famedic</Strong>
						</Text>
					</NavbarItem>
					<Text>
						<span className="font-poppins">
							Salud y tecnología a bajo costo.
						</span>
					</Text>
				</div>
				<div className="mt-12 grid gap-12 sm:grid-cols-2 lg:col-span-2 xl:mt-0">
					{links}
					<div>
						<Subheading>Legal</Subheading>

						<ul role="list" className="mt-6 space-y-4">
							{navigation.legal.map((item) => (
								<li key={item.name}>
									<Text>
										<TextLink
											className="group flex items-center no-underline hover:underline"
											href={item.href}
										>
											{item.name}
											<ArrowRightIcon className="ml-1 size-5 opacity-0 transition-all duration-300 group-hover:opacity-100" />
										</TextLink>
									</Text>
								</li>
							))}
						</ul>
					</div>
				</div>
			</div>
			<div className="mt-12 space-y-3">
				<div className="flex gap-4">
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
