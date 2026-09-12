import { Fragment } from "react";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import {
	CalendarDaysIcon,
	ChevronRightIcon,
	DocumentTextIcon,
	MagnifyingGlassIcon,
} from "@heroicons/react/20/solid";

const STEPS = [
	["1", "Elige tu estudio", "Explora los estudios disponibles y agrégalos a tu carrito.", MagnifyingGlassIcon],
	["2", "Agenda tu cita", "Selecciona la sucursal, fecha y horario que más te convenga.", CalendarDaysIcon],
	["3", "Realízate tu estudio", "Acude a tu cita y recibe tus resultados de forma segura.", DocumentTextIcon],
];

export default function CampaignSteps() {
	return (
		<section className="rounded-2xl bg-sky-50 px-4 py-7 ring-1 ring-sky-100 sm:px-8 md:py-9">
			<div className="text-center">
				<Subheading className="font-poppins text-2xl font-semibold text-famedic-darker">
					¿Cómo funciona?
				</Subheading>
				<Text className="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-600">
					Realiza tu estudio en solo 3 pasos y cuida tu salud con Famedic.
				</Text>
			</div>
			<div className="mt-6 grid gap-4 md:mt-8 md:grid-cols-[1fr_auto_1fr_auto_1fr] md:items-center md:gap-6">
				{STEPS.map(([number, title, description, Icon], index) => (
					<Fragment key={number}>
						<div className="flex items-start gap-4 rounded-2xl bg-white/80 p-4 shadow-sm ring-1 ring-sky-100 md:block md:bg-transparent md:p-0 md:shadow-none md:ring-0 md:text-left">
							<div className="relative flex size-14 shrink-0 items-center justify-center rounded-full bg-white text-sky-800 shadow-sm ring-1 ring-sky-100 md:size-20">
								<Icon className="size-7 md:size-10" aria-hidden="true" />
								<span className="absolute -right-1 -top-2 flex size-7 items-center justify-center rounded-full bg-famedic-lime text-xs font-semibold text-famedic-dark ring-4 ring-sky-50 md:size-8 md:text-sm md:ring-white">
									{number}
								</span>
							</div>
							<div className="min-w-0 pt-1 md:mt-4 md:pt-0">
								<Text className="font-semibold text-famedic-darker">{title}</Text>
								<Text className="mt-1 text-sm leading-6 text-slate-600">{description}</Text>
							</div>
						</div>
						{index < STEPS.length - 1 && (
							<div className="hidden justify-center text-famedic-darker/60 md:flex" aria-hidden="true">
								<ChevronRightIcon className="size-7" />
							</div>
						)}
					</Fragment>
				))}
			</div>
		</section>
	);
}
