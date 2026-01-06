import ApplicationLogo from "@/Components/ApplicationLogo";
import { Head } from "@inertiajs/react";
import { Heading } from "@/Components/Catalyst/heading";
import Notification from "@/Components/Notification";
import OdessaLogo from "@/Components/OdessaLogo";
import { LinkIcon } from "@heroicons/react/20/solid";
import { Button } from "@/Components/Catalyst/button";
import useTrackingEvents from "@/Hooks/useTrackingEvents";

export default function AuthLayout({
	title,
	header,
	children,
	showOdessaLogo = false,
}) {
	useTrackingEvents();

	return (
		<>
			<Head title={title} />			

			<div className="min-h-screen bg-gradient-to-b from-white to-blue-50 dark:from-slate-950 dark:to-slate-900">
				<div className="container mx-auto px-4 py-8 sm:px-6 lg:px-8">
					{/* Header con logo */}
					<div className="mb-8 sm:mb-10 text-center">
						<Button
							plain
							href={route("welcome")}
							className="inline-flex items-center gap-3 hover:opacity-80 transition-opacity"
						>
							{showOdessaLogo && (
								<>
									<OdessaLogo className="h-10 w-auto sm:h-12" />
									<LinkIcon className="h-6 w-6 sm:h-7 sm:w-7 text-blue-500" />
								</>
							)}
							<ApplicationLogo className="h-10 w-auto sm:h-12" />
							<Heading className="text-2xl font-bold text-gray-900 dark:text-white sm:text-3xl">
								Famedic MX
							</Heading>
						</Button>
						<p className="mt-2 sm:mt-3 text-sm text-gray-600 dark:text-gray-400 sm:text-lg">
							Salud y tecnología a bajo costo • Cobertura nacional en México
						</p>
					</div>

					{/* Contenedor principal */}
					<div className="mx-auto max-w-4xl">
						<div className="overflow-hidden rounded-2xl shadow-2xl">
							<div className="grid md:grid-cols-5">
								{/* Panel informativo */}
								<div className="bg-gradient-to-br from-blue-600 to-blue-800 p-6 text-white sm:p-8 md:col-span-2">
									<div className="flex h-full flex-col justify-between">
										<div>
											<h3 className="mb-4 text-xl font-bold sm:mb-6 sm:text-2xl">
												Beneficios de tu cuenta
											</h3>
											<ul className="space-y-3 sm:space-y-4">
												<li className="flex items-start gap-3">
													<svg className="mt-0.5 h-5 w-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
														<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
													</svg>
													<span className="text-sm sm:text-base">Consultas médicas 24/7 para toda tu familia</span>
												</li>
												<li className="flex items-start gap-3">
													<svg className="mt-0.5 h-5 w-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
														<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
													</svg>
													<span className="text-sm sm:text-base">Estudios de laboratorio e imagen con descuento de hasta 60%</span>
												</li>
												<li className="flex items-start gap-3">
													<svg className="mt-0.5 h-5 w-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
														<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
													</svg>
													<span className="text-sm sm:text-base">Solicita tus medicamentos directo a tu(s) domicilio(s)</span>
												</li>
												<li className="flex items-start gap-3">
													<svg className="mt-0.5 h-5 w-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
														<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
													</svg>
													<span className="text-sm sm:text-base">Accede a tus resultados clínicos</span>
												</li>
												<li className="flex items-start gap-3">
													<svg className="mt-0.5 h-5 w-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
														<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
													</svg>
													<span className="text-sm sm:text-base">Solicita la factura de tus servicios</span>
												</li>
												<li className="flex items-start gap-3">
													<svg className="mt-0.5 h-5 w-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
														<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
													</svg>
													<span className="text-sm sm:text-base">Recibe asesoría psicológica, nutricional y legal</span>
												</li>												
											</ul>
										</div>
										
										<div className="mt-6 pt-6 border-t border-blue-400/30 sm:mt-8">
											<p className="text-xs opacity-90 sm:text-sm">
												<strong>Asistencia al:</strong>{" "}
												<a 
													href="tel:8128601893" 
													className="hover:underline"
												>
													81 2860 1893
												</a>
											</p>
											<div className="mt-2 flex items-center gap-2 text-xs sm:mt-3 sm:text-sm">
												<svg 
													className="h-4 w-4 sm:h-5 sm:w-5" 
													viewBox="0 0 60 36" 
													fill="none" 
													xmlns="http://www.w3.org/2000/svg"
												>
													<rect width="20" height="36" fill="#006341"/>
													<rect x="20" width="20" height="36" fill="white"/>
													<rect x="40" width="20" height="36" fill="#C8102E"/>
												</svg>
												<span>Cobertura en todo México</span>
											</div>
										</div>
									</div>
								</div>

								{/* Panel del formulario */}
								<div className="bg-white p-6 dark:bg-slate-800 sm:p-8 md:col-span-3">
									<div className="mx-auto max-w-md">
										{header && (
											<div className="space-y-3 mb-6 sm:mb-8">
												{header}
											</div>
										)}

										<div className="space-y-6">
											{children}
										</div>
									</div>
								</div>
							</div>
						</div>

						{/* Pie de página */}
						<div className="mt-6 text-center text-xs text-gray-600 dark:text-gray-400 sm:mt-8 sm:text-sm">
							<p>
								¿Problemas técnicos? Contacta a{" "}
								<a 
									href="mailto:contacto@famedic.com.mx" 
									className="font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 hover:underline"
								>
									contacto@famedic.com.mx
								</a>
							</p>
							<p className="mt-1">
								© {new Date().getFullYear()} Famedic. Todos los derechos reservados.
							</p>
						</div>
					</div>
				</div>
			</div>

			<Notification />
		</>
	);
}