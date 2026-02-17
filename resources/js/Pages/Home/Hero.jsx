export default function Hero({ invitationUrl, auth }) {
	const modules = [
		{
			name: "Atención médica 24hrs",
			description:
				"Consulta con un médico experto para ti y tu familia y obtén un diagnóstico preciso sin salir de casa o donde quiera que te encuentres",
			routeName: "medical-attention",
			imageSrc:
				"https://images.pexels.com/photos/5998445/pexels-photo-5998445.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2",
		},
		{
			name: "Laboratorios",
			description:
				"Ordena aquí tus estudios de laboratorio al costo más bajo y obtén automáticamente descuentos exclusivos de hasta el 50%.",
			routeName: "laboratory-brand-selection",
			imageSrc:
				"https://images.pexels.com/photos/4176850/pexels-photo-4176850.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2",
		},
		/*{
			name: "Farmacia en línea",
			description:
				"Accede al inventario más grande de medicamentos con entrega directa a tu domicilio.",
			routeName: "online-pharmacy",
			imageSrc:
				"https://images.pexels.com/photos/3683037/pexels-photo-3683037.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2",
		},*/
	];

	return (
		<div className="relative -mt-32">
			{/* Background image and overlap desktop*/}
			<div className="absolute inset-0 top-0 hidden lg:-left-8 lg:-right-8 lg:flex lg:flex-col">
				<BackgroundImage />
				<div className="h-32 w-full" />
			</div>

			<div className="relative mx-auto px-4 pb-96 text-center lg:px-8 lg:pb-0 xl:max-w-3xl">
				{/* Background image and overlap mobile */}
				<div className="absolute inset-0 flex flex-col rounded-lg lg:hidden">
					<BackgroundImage />

					<div className="h-48 w-full" />
				</div>
				<div className="relative pb-16 pt-32">
					<h1 className="font-poppins text-4xl font-medium tracking-tight text-white sm:text-5xl lg:text-6xl">
						<span className="text-famedic-lime">Bien</span>venido a
						tu espacio de salud y{" "}
						<span className="text-famedic-light">bien</span>estar
					</h1>

					{auth?.user && invitationUrl && (
						<div className="mt-6 text-center">
							<InvitationLink invitationUrl={invitationUrl} />
						</div>
					)}
				</div>
			</div>

			<section className="relative -mt-96 lg:mt-0">
				<div className="mx-auto grid grid-cols-1 gap-y-6 sm:max-lg:max-w-[32rem] lg:grid-cols-3 lg:gap-x-8 lg:gap-y-0">
					{modules.map((module) => (
						<ModuleCard key={module.name} module={module} />
					))}
				</div>
			</section>
		</div>
	);
}

function BackgroundImage() {
	return (
		<div className="relative w-full flex-1">
			<div className="absolute inset-0 max-lg:-mx-4">
				<img
					alt=""
					src="https://images.pexels.com/photos/3279209/pexels-photo-3279209.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
					className="h-full w-full object-cover object-center lg:rounded-2xl"
				/>
			</div>
			<div className="absolute inset-0 bg-gray-900 opacity-50 max-lg:-mx-4 lg:rounded-2xl" />
		</div>
	);
}

function ModuleCard({ module }) {
	return (
		<Card
			hoverable
			href={route(module.routeName)}
			key={module.name}
			className="group relative flex flex-col justify-between lg:h-auto"
		>
			<img
				alt={module.imageAlt}
				src={module.imageSrc}
				className="aspect-video rounded-t-lg object-contain"
			/>

			{module.name === "Laboratorios" && (
				<LaboratoryLogos className="absolute bottom-52 left-6 lg:bottom-[56%] xl:bottom-48" />
			)}
			<div className="flex flex-1 items-end rounded-lg">
				<div className="flex h-full w-full flex-col justify-between rounded-b-lg p-6">
					<Text>{module.description}</Text>

					<div className="mt-4">
						<Button className="pointer-events-none">
							<span className="font-semibold">{module.name}</span>
							<ArrowRightIcon className="transform transition-transform sm:group-hover:translate-x-1" />
						</Button>
					</div>
				</div>
			</div>
		</Card>
	);
}

function LaboratoryLogos({ className }) {
	const laboratoryBrands = usePage().props.laboratoryBrands;

	return (
		<div className={clsx("flex items-center gap-1", className)}>
			{Object.entries(laboratoryBrands).map(([key, brand]) => (
				<Avatar
					square
					key={key}
					src={`/images/gda/${brand.imageSrc}`}
					className="size-10 overflow-hidden bg-zinc-50 [&>img]:scale-[1.6] [&>img]:object-contain"
				/>
			))}
		</div>
	);
}

import clsx from "clsx";
import { ArrowRightIcon } from "@heroicons/react/20/solid";
import { usePage } from "@inertiajs/react";
import { Avatar } from "@/Components/Catalyst/avatar";
import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import InvitationLink from "@/Pages/Home/InvitationLink";
