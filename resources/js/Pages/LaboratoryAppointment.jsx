import FocusedLayout from "@/Layouts/FocusedLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { CheckIcon, PhoneIcon } from "@heroicons/react/20/solid";
import {
	DevicePhoneMobileIcon,
	ArrowLeftIcon,
} from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { motion } from "framer-motion";
import { useState, useEffect } from "react";
import { router } from "@inertiajs/react";

export default function LaboratoryAppointmentCreation({
	laboratoryAppointment,
	auth,
}) {
	const [showCheck, setShowCheck] = useState(true);

	useEffect(() => {
		const intervalId = setInterval(() => {
			router.reload();
		}, 10000);
		return () => clearInterval(intervalId);
	}, []);

	return (
		<FocusedLayout title="Cita de laboratorio">
			<div className="mx-auto max-w-2xl pt-20">
				<div className="flex justify-center">
					<div className="relative mx-auto inline-flex">
						<motion.div
							initial={{ scale: 0.5 }}
							animate={{ scale: 1 }}
							exit={{ scale: 0 }}
							transition={{ duration: 3 }}
							onAnimationComplete={() => {
								setShowCheck(false);
							}}
						>
							{showCheck ? (
								<CheckIcon className="size-32 text-green-600 dark:text-green-200" />
							) : (
								<>
									<div className="absolute left-1/2 size-6 animate-bounce rounded-full bg-green-400 dark:bg-green-300"></div>
									<PhoneIcon className="relative size-32 animate-[shake_2s_infinite] fill-green-600 dark:fill-green-200" />
								</>
							)}
						</motion.div>
					</div>
				</div>
				<div className="text-center">
					<GradientHeading>
						<span className="text-center">
							Llama ahora para agendar tu cita
						</span>
					</GradientHeading>
					<a href="tel:5566515232">
						<Button>
							<PhoneIcon />
							(55) 6651 5232
						</Button>
					</a>
					<Text className="mt-6 flex flex-wrap items-center justify-center">
						<span>
							Si no te es posible llamar en este momento, nuestro
							equipo se pondrá en contacto contigo al
						</span>
						<Badge color="sky" className="mx-1">
							<DevicePhoneMobileIcon className="size-4" />
							{auth.user.phone}.
						</Badge>
						<span>Si tu número ha cambiado, puedes</span>
						<TextLink href={route("user.edit")} className="mx-1">
							actualizarlo aquí.
						</TextLink>
					</Text>

					<Text className="mt-4">
						Una vez que hayas confirmado tu cita, podrás continuar
						con tu compra.
					</Text>

					<div className="mt-10">
						<Button
							plain
							href={route("laboratory.shopping-cart", {
								laboratory_brand: laboratoryAppointment.brand,
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
