import FocusedLayout from "@/Layouts/FocusedLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Strong, Text } from "@/Components/Catalyst/text";
import {
	ArrowLeftIcon,
	CalendarDateRangeIcon,
	CheckIcon,
} from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";

export default function LaboratoryAppointmentCreation({ laboratoryBrand }) {
	return (
		<FocusedLayout title="Cita de laboratorio">
			<div className="mx-auto max-w-2xl pt-20">
				<div className="mb-8 flex justify-center">
					<Badge color="sky">
						<CheckIcon className="size-4" />
						Solicitar una cita es fácil y rápido
					</Badge>
				</div>
				<div className="text-center">
					<GradientHeading>
						Necesitas una cita para continuar
					</GradientHeading>

					<Text>
						Para ciertos estudios de laboratorio, agendar una cita
						nos permite asegurarnos de que la sucursal cuente con el
						equipo necesario y que se cumplan todos los requisitos
						previos. Así, podemos ofrecerte un servicio preciso y de
						alta calidad.
					</Text>

					<div className="mt-10 flex flex-col items-center justify-center gap-20">
						<Button
							href={route("laboratory-appointments.store", {
								laboratory_brand: laboratoryBrand,
							})}
							method="post"
							as="button"
							type="button"
							className="animate-pulse hover:animate-none"
						>
							<CalendarDateRangeIcon />
							Agendar cita ahora
						</Button>
						<Button
							plain
							href={route("laboratory.shopping-cart", {
								laboratory_brand: laboratoryBrand,
							})}
						>
							<ArrowLeftIcon />
							Regresar al carrito
						</Button>
					</div>
				</div>
			</div>
		</FocusedLayout>
	);
}
