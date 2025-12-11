import { Link } from "@inertiajs/react";
import useTrackingEvents from "@/Hooks/useTrackingEvents";
import { Testimonials } from "@/Pages/Welcome/testimonials";
import clsx from "clsx";
import FamedicLayout from "@/Layouts/FamedicLayout";

export default function Home() {
	useTrackingEvents();

	return (
		<FamedicLayout title="¡Bienvenido!">
			<div className="space-y-12 lg:-mx-8">
				<Hero />

				<Benefits />

				<div id="preguntas">
					<Testimonials />
				</div>
			</div>
		</FamedicLayout>
	);
}

function Hero() {
	return (
		<div className="overflow-hidden">
			<div className="lg:flex">
				<div className="mx-auto grid max-w-2xl grid-cols-1 gap-x-12 gap-y-16 lg:mx-0 lg:min-w-full lg:max-w-none lg:flex-none lg:gap-y-8">
					<div className="mx-6 mt-24 lg:col-end-1 lg:mx-8 lg:w-full lg:max-w-lg lg:pb-8">
						<Title>
							Todo lo que necesitas para
							<span className="ml-2 text-famedic-light">
								cuidar tu salud
							</span>
							, en un solo lugar.
						</Title>
						<div className="mt-10 flex">
							<RoundedLink href={"home"}>
								¡Únete o ingresa!
							</RoundedLink>
						</div>
					</div>
					<div className="flex flex-wrap items-start justify-end gap-6 sm:gap-8 lg:contents">
						<div className="w-0 flex-auto lg:ml-auto lg:w-auto lg:flex-none lg:self-end">
							<img
								alt=""
								src="/images/welcome/yoga.jpg"
								className="aspect-[7/5] w-[37rem] max-w-none rounded-2xl bg-gray-50 object-cover shadow-xl"
							/>
						</div>
						<div className="contents lg:col-span-2 lg:col-end-2 lg:ml-auto lg:flex lg:w-[37rem] lg:items-start lg:justify-end lg:gap-x-8">
							<div className="order-first flex w-64 flex-none justify-end self-end lg:w-auto">
								<img
									alt=""
									src="/images/welcome/elderly.jpeg"
									className="aspect-[4/3] w-[24rem] max-w-none flex-none rounded-2xl bg-gray-500 object-cover shadow-xl"
								/>
							</div>
							<div className="flex w-96 flex-auto justify-end lg:w-auto lg:flex-none">
								<img
									alt=""
									src="/images/welcome/family.jpg"
									className="aspect-[7/5] w-[37rem] max-w-none flex-none rounded-2xl bg-gray-50 object-cover shadow-xl"
								/>
							</div>
							<div className="hidden sm:block sm:w-0 sm:flex-auto lg:w-auto lg:flex-none">
								<img
									alt=""
									src="/images/welcome/doctor.jpg"
									className="aspect-[4/3] w-[24rem] max-w-none rounded-2xl bg-gray-50 object-cover shadow-xl"
								/>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}

function Benefits({ className }) {
	const benefits = [
		{
			name: "Médico Familiar 24/7",
			description:
				"Disfruta atención médica en línea, en cualquier momento, con doctores certificados y sin salir de casa. Prueba un mes completamente gratis, sin compromiso ni necesidad de tarjeta de crédito.",

			img: "https://images.pexels.com/photos/6749773/pexels-photo-6749773.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2",
			classes: "bg-famedic-darker",
		},
		{
			name: "Estudios de Laboratorio a Precios Exclusivos",
			description:
				"Ahorra hasta un 50% en estudios clínicos y de imagen con los laboratorios más prestigiosos. Calidad y servicio de primer nivel para cuidar tu salud.",

			// img: "https://tailwindui.com/plus-assets/img/component-images/bento-03-performance.png",
			classes: "bg-famedic-lime",
		},
		{
			name: "Historial de Compras y Resultados",
			description:
				"Consulta tus compras, facturas y resultados de laboratorio en un solo lugar. Mantén toda tu información de salud organizada y accesible.",
			// img: "https://tailwindui.com/plus-assets/img/component-images/bento-03-security.png",
			classes: "bg-famedic-darker",
		},
		{
			name: "Garantía y Seguridad",
			description:
				"Todas tus compras y movimientos están protegidos por famedic, garantizando transacciones seguras y brindándote total tranquilidad.",
			// img: "/images/welcome/online-pharmacy.jpg",
			classes: "bg-famedic-lime",
		},
	];

	return (
		<div id="servicios" className={className}>
			<Subtitle className="text-center">
				Tu plataforma de salud digital
			</Subtitle>
			<Title className="text-center">
				Accede a los beneficios de tu membresía
			</Title>
			<div className="mt-10 grid gap-4 sm:mt-16 lg:grid-cols-3 lg:grid-rows-2">
				<div className="relative lg:row-span-2">
					<div
						className={clsx(
							benefits[0].classes,
							"absolute inset-px rounded-lg lg:rounded-l-[2rem]",
						)}
					></div>
					<div className="relative flex h-full flex-col overflow-hidden rounded-[calc(theme(borderRadius.lg)+1px)] lg:rounded-l-[calc(2rem+1px)]">
						<div className="px-8 pb-3 pt-8 sm:px-10 sm:pb-0 sm:pt-10">
							<p className="mt-2 text-3xl font-medium tracking-tight text-famedic-lime max-lg:text-center">
								{benefits[0].name}
							</p>
							<p className="mt-2 max-w-lg text-sm/6 text-white max-lg:text-center">
								{benefits[0].description}
							</p>
						</div>
						<div className="relative min-h-[30rem] w-full grow [container-type:inline-size] max-lg:mx-auto max-lg:max-w-sm">
							<div className="absolute inset-x-10 bottom-0 top-10 overflow-hidden rounded-t-[12cqw] border-x-[3cqw] border-t-[3cqw] border-famedic-light bg-gray-900 shadow-2xl">
								<img
									className="size-full object-cover object-top"
									src={benefits[0].img}
									alt=""
								/>
							</div>
						</div>
					</div>
					<div className="pointer-events-none absolute inset-px rounded-lg shadow ring-1 ring-black/5 lg:rounded-l-[2rem]"></div>
				</div>
				<div className="relative max-lg:row-start-1">
					<div
						className={clsx(
							benefits[1].classes,
							"absolute inset-px rounded-lg max-lg:rounded-t-[2rem]",
						)}
					></div>
					<div className="relative flex h-full flex-col overflow-hidden rounded-[calc(theme(borderRadius.lg)+1px)] max-lg:rounded-t-[calc(2rem+1px)]">
						<div className="px-8 pt-8 sm:px-10 sm:pt-10">
							<p className="mt-2 text-3xl font-medium tracking-tight text-gray-950 max-lg:text-center">
								{benefits[1].name}
							</p>
							<p className="mt-2 max-w-lg text-sm/6 text-gray-600 max-lg:text-center">
								{benefits[1].description}
							</p>
						</div>
						<div className="flex flex-1 items-center justify-center px-8 max-lg:pb-12 max-lg:pt-10 sm:px-10 lg:pb-2">
							<img
								className="w-full max-lg:max-w-xs"
								src={benefits[1].img}
								alt=""
							/>
						</div>
					</div>
					<div className="pointer-events-none absolute inset-px rounded-lg shadow ring-1 ring-black/5 max-lg:rounded-t-[2rem]"></div>
				</div>
				<div className="relative max-lg:row-start-3 lg:col-start-2 lg:row-start-2">
					<div
						className={clsx(
							benefits[2].classes,
							"absolute inset-px rounded-lg",
						)}
					></div>
					<div className="relative flex h-full flex-col overflow-hidden rounded-[calc(theme(borderRadius.lg)+1px)]">
						<div className="px-8 pt-8 sm:px-10 sm:pt-10">
							<p className="mt-2 text-3xl font-medium tracking-tight text-famedic-lime max-lg:text-center">
								{benefits[2].name}
							</p>
							<p className="mt-2 max-w-lg text-sm/6 text-white max-lg:text-center">
								{benefits[2].description}
							</p>
						</div>
						<div className="flex flex-1 items-center [container-type:inline-size] max-lg:py-6 lg:pb-2">
							<img
								className="h-[min(152px,40cqw)] object-cover"
								src={benefits[2].img}
								alt=""
							/>
						</div>
					</div>
					<div className="pointer-events-none absolute inset-px rounded-lg shadow ring-1 ring-black/5"></div>
				</div>
				<div className="relative lg:row-span-2">
					<div className="absolute inset-px rounded-lg bg-white max-lg:rounded-b-[2rem] lg:rounded-r-[2rem]"></div>
					<div className="relative flex h-full flex-col overflow-hidden rounded-[calc(theme(borderRadius.lg)+1px)] max-lg:rounded-b-[calc(2rem+1px)] lg:rounded-r-[calc(2rem+1px)]">
						<div className="px-8 pb-3 pt-8 sm:px-10 sm:pb-0 sm:pt-10">
							<p className="mt-2 text-3xl font-medium tracking-tight text-gray-950 max-lg:text-center">
								{benefits[3].name}
							</p>
							<p className="mt-2 max-w-lg text-sm/6 text-gray-600 max-lg:text-center">
								{benefits[3].description}
							</p>
						</div>
						{/* <div className="relative min-h-[30rem] w-full grow">
								<div className="absolute bottom-0 left-10 right-0 top-10 overflow-hidden rounded-tl-xl bg-gray-900 shadow-2xl">
									<div className="flex bg-gray-800/40 ring-1 ring-white/5">
										<div className="-mb-px flex text-sm/6 font-medium text-gray-400">
											<div className="border-b border-r border-b-white/20 border-r-white/10 bg-white/5 px-4 py-2 text-white">
												NotificationSetting.jsx
											</div>
											<div className="border-r border-gray-600/10 px-4 py-2">
												App.jsx
											</div>
										</div>
									</div>
									<div className="px-6 pb-14 pt-6">
									</div>
								</div>
							</div> */}
					</div>
					<div className="pointer-events-none absolute inset-px rounded-lg shadow ring-1 ring-black/5 max-lg:rounded-b-[2rem] lg:rounded-r-[2rem]"></div>
				</div>
			</div>
		</div>
	);
}

function RoundedLink({ children, href, className }) {
	return (
		<Link
			href={href}
			className={clsx(
				"inline-flex items-center justify-center whitespace-nowrap rounded-full border border-transparent bg-famedic-lime px-4 py-[calc(theme(spacing.2)-1px)] font-poppins font-semibold text-famedic-darker shadow-md data-[disabled]:bg-gray-950 data-[hover]:bg-famedic-lime/20 data-[disabled]:opacity-40",
				className,
			)}
		>
			{children}
		</Link>
	);
}

function Title({ children, level = 1, className }) {
	let Element = `h${level}`;

	return (
		<Element
			className={clsx(
				"font-poppins text-4xl font-semibold tracking-tight text-famedic-darker sm:text-5xl dark:text-slate-300",
				className,
			)}
		>
			{children}
		</Element>
	);
}

function Subtitle({ children, level = 2, className }) {
	let Element = `h${level}`;

	return (
		<Element
			className={clsx(
				"font-poppins text-3xl/7 font-semibold text-famedic-light",
				className,
			)}
		>
			{children}
		</Element>
	);
}
