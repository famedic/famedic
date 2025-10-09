export default function CTA() {
	return (
		<Card
			hoverable
			href={route("laboratory-brand-selection", {
				category: "Chequeos y Paquetes",
			})}
			className="group relative block"
		>
			<div className="px-6 pb-16 pt-48 sm:pb-24">
				<div>
					<Subheading className="!leading-[3rem] md:max-w-md md:!leading-[4rem] xl:max-w-lg">
						<span className="text-4xl font-bold tracking-tight md:text-5xl">
							<i>Chequeos y paquetes</i>
							<br />a un{" "}
							<span className="animate-pulse bg-famedic-lime !text-famedic-darker">
								precio especial
							</span>
						</span>
					</Subheading>

					<ul className="mt-6 space-y-1">
						{[
							"CHEQUEO GENERAL ESENCIAL",
							"CHEQUEO GENERAL PLUS",
							"PERFIL HOMBRE",
							"PERFIL MUJER MENOR 40",
							"PERFIL MUJER MAYOR 40",
						].map((feature, idx) => (
							<li
								key={idx}
								className="flex gap-2 text-sm text-zinc-700 dark:text-slate-200"
							>
								<CheckIcon className="mt-1 size-4 flex-shrink-0 text-famedic-light" />
								<Text>{feature}</Text>
							</li>
						))}
					</ul>

					<div className="mt-6">
						<Subheading className="flex items-center sm:group-hover:underline">
							Laboratorios
							<ArrowRightIcon className="ml-1 size-5 transform transition-transform sm:group-hover:translate-x-1 sm:group-hover:scale-125" />
						</Subheading>
					</div>
				</div>

				<div className="absolute inset-0 overflow-hidden rounded-lg">
					<div className="absolute -top-32 left-[45%] -translate-x-[45%] transform md:top-6 md:translate-x-0">
						<div className="flex min-w-max space-x-6 md:ml-3 lg:space-x-8">
							<div className="flex space-x-6 md:flex-col md:space-x-0 md:space-y-6 lg:space-y-8">
								<div className="flex-shrink-0">
									<img
										alt=""
										src="https://images.pexels.com/photos/8460348/pexels-photo-8460348.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
										className="h-64 w-64 rounded-lg object-cover sm:group-hover:ring-2 sm:group-hover:ring-blue-950 md:h-72 md:w-72 dark:sm:group-hover:ring-white"
									/>
								</div>

								<div className="mt-6 flex-shrink-0 md:mt-0">
									<img
										alt=""
										src="https://images.pexels.com/photos/7659564/pexels-photo-7659564.jpeg?auto=compress&cs=tinysrgb&w=1200"
										className="h-64 w-64 rounded-lg object-cover sm:group-hover:ring-2 sm:group-hover:ring-blue-950 md:h-72 md:w-72 dark:sm:group-hover:ring-white"
									/>
								</div>
							</div>
							<div className="flex space-x-6 md:-mt-20 md:flex-col md:space-x-0 md:space-y-6 lg:space-y-8">
								<div className="flex-shrink-0">
									<img
										alt=""
										src="https://images.pexels.com/photos/3900424/pexels-photo-3900424.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
										className="h-64 w-64 rounded-lg object-cover sm:group-hover:ring-2 sm:group-hover:ring-blue-950 md:h-72 md:w-72 dark:sm:group-hover:ring-white"
									/>
								</div>

								<div className="mt-6 flex-shrink-0 md:mt-0">
									<img
										alt=""
										src="https://images.pexels.com/photos/9629677/pexels-photo-9629677.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
										className="h-64 w-64 rounded-lg object-cover sm:group-hover:ring-2 sm:group-hover:ring-blue-950 md:h-72 md:w-72 dark:sm:group-hover:ring-white"
									/>
								</div>
							</div>
							<div className="flex space-x-6 md:flex-col md:space-x-0 md:space-y-6 lg:space-y-8">
								<div className="flex-shrink-0">
									<img
										alt=""
										src="https://images.pexels.com/photos/6303776/pexels-photo-6303776.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
										className="h-64 w-64 rounded-lg object-cover sm:group-hover:ring-2 sm:group-hover:ring-blue-950 md:h-72 md:w-72 dark:sm:group-hover:ring-white"
									/>
								</div>

								<div className="mt-6 flex-shrink-0 md:mt-0">
									<img
										alt=""
										src="https://images.pexels.com/photos/5257644/pexels-photo-5257644.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
										className="h-64 w-64 rounded-lg object-cover sm:group-hover:ring-2 sm:group-hover:ring-blue-950 md:h-72 md:w-72 dark:sm:group-hover:ring-white"
									/>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</Card>
	);
}

import { ArrowRightIcon } from "@heroicons/react/20/solid";
import { Subheading } from "@/Components/Catalyst/heading";
import Card from "@/Components/Card";
import { CheckIcon } from "@heroicons/react/16/solid";
import { Text } from "@/Components/Catalyst/text";
