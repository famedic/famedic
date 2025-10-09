import FamedicLayout from "@/Layouts/FamedicLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Anchor, Code, Strong, Text } from "@/Components/Catalyst/text";
import { GiftIcon, CheckIcon, QrCodeIcon } from "@heroicons/react/24/solid";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import PaymentMethodStep from "@/Components/Checkout/PaymentMethodStep";
import { Badge } from "@/Components/Catalyst/badge";
import {
	CalendarDaysIcon,
	PhoneIcon,
	UserGroupIcon,
} from "@heroicons/react/16/solid";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

export default function MedicalAttention({
	hasOdessaAfiliateAccount,
	medicalAttentionSubscriptionIsActive,
	formattedMedicalAttentionSubscriptionExpiresAt,
	medicalAttentionIdentifier,
	familyAccounts,
	paymentMethods,
	formattedPrice,
}) {
	let [isOpen, setIsOpen] = useState(false);

	return (
		<FamedicLayout title="Atención médica">
			<GradientHeading>Atención médica</GradientHeading>

			{medicalAttentionSubscriptionIsActive ? (
				<>
					<SubscriptionHero
						formattedMedicalAttentionSubscriptionExpiresAt={
							formattedMedicalAttentionSubscriptionExpiresAt
						}
						medicalAttentionIdentifier={medicalAttentionIdentifier}
						familyAccounts={familyAccounts}
					/>
					<SubscriptionDetails />
				</>
			) : (
				<>
					<SubscribeHero
						setIsOpen={setIsOpen}
						formattedMedicalAttentionSubscriptionExpiresAt={
							formattedMedicalAttentionSubscriptionExpiresAt
						}
						formattedPrice={formattedPrice}
						paymentMethods={paymentMethods}
					/>
					<SubscribeToMedicalAttention
						hasOdessaAfiliateAccount={hasOdessaAfiliateAccount}
						isOpen={isOpen}
						setIsOpen={setIsOpen}
						paymentMethods={paymentMethods}
						formattedPrice={formattedPrice}
						formattedMedicalAttentionSubscriptionExpiresAt={
							formattedMedicalAttentionSubscriptionExpiresAt
						}
					/>
				</>
			)}
		</FamedicLayout>
	);
}

function SubscribeHero({
	setIsOpen,
	formattedMedicalAttentionSubscriptionExpiresAt,
	formattedPrice,
}) {
	return (
		<div className="">
			<div className="relative isolate overflow-hidden rounded-t-2xl bg-gradient-to-b from-zinc-300/10 dark:from-slate-800/20">
				<div className="relative pb-24 pt-10 lg:grid lg:grid-cols-2 lg:gap-x-8 lg:px-8 lg:py-40">
					<div className="px-6 lg:px-0 lg:pt-4">
						<div className="mx-auto max-w-2xl">
							<div className="max-w-2xl">
								<h1 className="text-pretty text-5xl font-semibold tracking-tight text-famedic-dark dark:text-white">
									{formattedMedicalAttentionSubscriptionExpiresAt ? (
										<>
											Brindamos atención médica a tu
											familia por menos de{" "}
											<span className="text-famedic-light">
												$1mxn al día
											</span>
										</>
									) : (
										<>
											Prueba{" "}
											<span className="text-famedic-light">
												1 mes de atención
											</span>{" "}
											médica para ti y tu familia sin
											costo!
										</>
									)}
								</h1>

								<Text className="mt-6">
									Protege a tu familia con acceso ilimitado a
									atención médica profesional 24/7 desde
									cualquier lugar. Prueba el servicio 1 mes
									completamente gratis. Al terminar tu período
									de prueba, podrás elegir adquirir una
									membresía familiar por menos de $1 peso al
									día para todas las consultas médicas,
									asistencia psicológica y asesoría
									nutricional para tu familia por todo un año.
								</Text>

								{!formattedMedicalAttentionSubscriptionExpiresAt ? (
									<div className="mt-10 flex items-center gap-x-6">
										<Button onClick={() => setIsOpen(true)}>
											<GiftIcon className="text-green-200" />
											Activar mi prueba sin costo
										</Button>
									</div>
								) : (
									<div className="mt-10 flex items-center gap-x-6">
										<Button onClick={() => setIsOpen(true)}>
											Suscribirse por {formattedPrice}
										</Button>
									</div>
								)}
							</div>
						</div>
					</div>
					<div className="mt-20 sm:mt-24 md:mx-auto md:max-w-2xl lg:mx-0 lg:mt-0 lg:w-screen">
						<video
							controls
							poster="https://images.pexels.com/photos/5998445/pexels-photo-5998445.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
							className="w-full rounded-2xl object-cover"
						>
							<source
								src="/images/murguia.mp4"
								type="video/mp4"
							/>
							Your browser does not support the video tag.
						</video>
					</div>
				</div>
			</div>
		</div>
	);
}

function SubscriptionHero({
	formattedMedicalAttentionSubscriptionExpiresAt,
	medicalAttentionIdentifier,
	familyAccounts,
}) {
	return (
		<div className="">
			<div className="relative isolate overflow-hidden rounded-t-2xl bg-gradient-to-b from-white dark:from-slate-800/50">
				<div className="relative pb-24 pt-10 lg:grid lg:grid-cols-2 lg:gap-x-8 lg:px-8 lg:py-40">
					<div className="mx-auto flex max-w-sm flex-col items-center space-y-10 px-6 lg:-mt-24 lg:px-0">
						<div className="text-center">
							<Badge color="slate">
								<QrCodeIcon className="size-4" />
								Número de identificación
							</Badge>
							<Text>
								<span className="text-5xl text-famedic-dark dark:text-famedic-light">
									{medicalAttentionIdentifier}
								</span>
							</Text>
						</div>
						<div className="text-center">
							<Badge color="slate">
								<CalendarDaysIcon className="size-4" />
								Vigencia
							</Badge>
							<Text>
								{formattedMedicalAttentionSubscriptionExpiresAt}
							</Text>
						</div>
						<div className="text-center">
							<div>
								<Badge color="slate">
									<UserGroupIcon className="size-4" />
									Familiares
								</Badge>
							</div>
							{familyAccounts.length > 0 && (
								<Table>
									<TableHead>
										<TableRow>
											<TableHeader>Familiar</TableHeader>
											<TableHeader className="text-right">
												Número de identificación
											</TableHeader>
										</TableRow>
									</TableHead>
									<TableBody>
										{familyAccounts.map((familyAccount) => (
											<TableRow key={familyAccount.id}>
												<TableCell>
													{familyAccount.full_name}
												</TableCell>
												<TableCell className="text-right">
													<Code>
														{
															familyAccount
																.customer
																.medical_attention_identifier
														}
													</Code>
												</TableCell>
											</TableRow>
										))}
									</TableBody>
								</Table>
							)}
							<Button
								href={route("family.index")}
								outline
								className="mt-6"
							>
								Gestionar familia
							</Button>
						</div>
						<Text className="text-center">
							Marca o presiona el siguiente número para iniciar
							una conversación con un doctor y obtener la atención
							médica que necesitas.
						</Text>
						<Anchor href="tel:+525594540058">
							<Button>
								<PhoneIcon />
								55 9454 0058
							</Button>
						</Anchor>
					</div>
					<div className="mt-20 sm:mt-24 md:mx-auto md:max-w-2xl lg:mx-0 lg:mt-0 lg:w-screen">
						<video
							controls
							poster="https://images.pexels.com/photos/5998445/pexels-photo-5998445.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
							className="w-full rounded-2xl object-cover"
						>
							<source
								src="/images/murguia.mp4"
								type="video/mp4"
							/>
							Your browser does not support the video tag.
						</video>
					</div>
				</div>
			</div>
		</div>
	);
}

function SubscribeToMedicalAttention({
	isOpen,
	setIsOpen,
	paymentMethods,
	formattedPrice,
	formattedMedicalAttentionSubscriptionExpiresAt,
}) {
	const { data, setData, post, processing, errors, clearErrors } = useForm({
		payment_method: null,
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (formattedMedicalAttentionSubscriptionExpiresAt) {
				post(route("medical-attention.subscription"));
			} else {
				post(route("free-medical-attention.subscription"));
			}
		}
	};

	return (
		<Dialog open={isOpen} onClose={setIsOpen}>
			<form onSubmit={submit}>
				<DialogTitle>
					{formattedMedicalAttentionSubscriptionExpiresAt
						? "Suscribirse por " + formattedPrice
						: "Comenzar prueba gratuita"}
				</DialogTitle>
				<DialogDescription className="space-y-4">
					{!formattedMedicalAttentionSubscriptionExpiresAt && (
						<p>
							No se necesita una tarjeta de crédito para el
							período de prueba.
						</p>
					)}

					<CoverageDetails />

					{formattedMedicalAttentionSubscriptionExpiresAt && (
						<PaymentMethodStep
							forceMobile={true}
							data={data}
							setData={setData}
							errors={errors}
							error={errors.payment_method}
							clearErrors={clearErrors}
							paymentMethods={paymentMethods}
							hasOdessaPay={true}
							addCardReturnUrl={route("medical-attention")}
						/>
					)}
				</DialogDescription>
				<DialogActions>
					<Button
						disabled={processing}
						dusk="cancel"
						plain
						type="button"
						onClick={() => setIsOpen(false)}
						autoFocus
					>
						Cancelar
					</Button>
					<Button
						disabled={processing}
						type="submit"
						className={processing && "opacity-0"}
					>
						{formattedMedicalAttentionSubscriptionExpiresAt
							? "Suscribirse por " + formattedPrice
							: "Comenzar periodo de prueba"}
					</Button>
				</DialogActions>
			</form>
		</Dialog>
	);
}

function SubscriptionDetails() {
	return (
		<div className="lg:!-mt-24 lg:px-12">
			<div className="mt-12 grid grid-cols-2 gap-6">
				<CoverageDetails />
			</div>
		</div>
	);
}

function CoverageDetails() {
	const hasOdessaAfiliateAccount = usePage().props.hasOdessaAfiliateAccount;

	return (
		<div className="space-y-6">
			<div>
				<Subheading>A QUIEN CUBRE</Subheading>
				<ul>
					<li className="flex items-center gap-x-2">
						<CheckIcon className="size-4 min-w-4 stroke-green-200" />
						<Text>Titular</Text>
					</li>
					<li className="flex items-center gap-x-2">
						<CheckIcon className="size-4 min-w-4 stroke-green-200" />
						<Text>Cónyuge</Text>
					</li>
					<li className="flex items-center gap-x-2">
						<CheckIcon className="size-4 min-w-4 stroke-green-200" />
						<Text>Hijos</Text>
					</li>
				</ul>
			</div>
			<div className="space-y-2">
				<Subheading>QUE INCLUYE</Subheading>

				<ol className="list-inside list-decimal space-y-4 marker:text-famedic-light">
					<li>
						<Strong>
							<span className="text-famedic-light">
								Asistencia telemedicina ilimitadas 24/7
							</span>
						</Strong>
						<ul className="list-inside list-disc marker:!text-famedic-dark">
							<li>
								<Text>
									Conecta al paciente con médicos generales a
									través de Videoconferencia y Chat 24/7
								</Text>
							</li>
						</ul>
					</li>

					{hasOdessaAfiliateAccount && (
						<>
							<li>
								<Strong>
									<span className="text-famedic-light">
										Médico en casa hasta 3 veces al año
									</span>
								</Strong>
								<ul className="list-inside list-disc marker:!text-famedic-dark">
									<li>
										<Text>
											Consultas médicas a domicilio
										</Text>
									</li>
								</ul>
							</li>
							<li>
								<Strong>
									<span className="text-famedic-light">
										Ambulancia en emergencia hasta 1 evento
										al año
									</span>
								</Strong>
								<ul className="list-inside list-disc marker:!text-famedic-dark">
									<li>
										<Text>Ambulancia terrestre</Text>
									</li>
								</ul>
							</li>
						</>
					)}
					<li>
						<Strong>
							<span className="text-famedic-light">
								Asistencias telefónicas ilimitadas
							</span>
						</Strong>
						<ul className="list-inside list-disc marker:!text-famedic-dark">
							<li>
								<Text>Psicológica</Text>
							</li>
							<li>
								<Text>Nutricional</Text>
							</li>
							<li>
								<Text>Legal</Text>
							</li>
						</ul>
					</li>
					{hasOdessaAfiliateAccount && (
						<li>
							<Strong>
								<span className="text-famedic-light">
									Reembolso de 3 medicamentos por familia por
									año de hasta $350 pesos en cada evento
								</span>
							</Strong>
							<ul className="list-inside list-disc marker:!text-famedic-dark">
								<li>
									<Text>
										Reembolso derivado de la consulta con el
										médico general (telemedicina)
									</Text>
								</li>
							</ul>
						</li>
					)}
				</ol>
			</div>
		</div>
	);
}
