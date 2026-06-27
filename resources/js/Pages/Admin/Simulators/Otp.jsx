import { useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Listbox, ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";
import SecurityVerificationModal from "@/Components/SecurityVerificationModal";
import { Link } from "@inertiajs/react";
import { ArrowLeftIcon, ShieldCheckIcon } from "@heroicons/react/24/outline";

export default function OtpSimulator({ purchases, labResultsOtpRequired, resendSeconds, trustMinutes }) {
	const [selectedPurchaseId, setSelectedPurchaseId] = useState(purchases[0]?.id ?? null);
	const [showModal, setShowModal] = useState(false);
	const [successMessage, setSuccessMessage] = useState("");

	const selectedPurchase = useMemo(
		() => purchases.find((p) => p.id === selectedPurchaseId) ?? null,
		[purchases, selectedPurchaseId],
	);

	const apiUrls = useMemo(() => {
		if (!selectedPurchaseId) return null;
		return {
			status: route("admin.simulators.otp.status", { laboratory_purchase: selectedPurchaseId }),
			send: route("admin.simulators.otp.send", { laboratory_purchase: selectedPurchaseId }),
			resend: route("admin.simulators.otp.resend", { laboratory_purchase: selectedPurchaseId }),
			verify: route("admin.simulators.otp.verify", { laboratory_purchase: selectedPurchaseId }),
		};
	}, [selectedPurchaseId]);

	return (
		<AdminLayout title="Simulador OTP">
			<div className="space-y-6">
				<div className="flex flex-wrap items-start gap-4">
					<Button href={route("admin.simulators.index")} outline>
						<ArrowLeftIcon className="size-4" />
						Simuladores
					</Button>
				</div>

				<div>
					<Heading>Simulador OTP</Heading>
					<Text className="mt-2 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Vas a simular el envío de un código de verificación por <Strong>SMS</Strong> o{" "}
						<Strong>correo</Strong> usando la misma notificación del flujo de resultados de laboratorio. El
						código llegará a <Strong>tu usuario administrador</Strong>, no al paciente del pedido.
					</Text>
				</div>

				<div className="flex flex-wrap gap-2">
					<Badge color={labResultsOtpRequired ? "emerald" : "amber"}>
						{labResultsOtpRequired
							? "OTP activo en pacientes (LAB_RESULTS_OTP_REQUIRED)"
							: "OTP desactivado en pacientes (producción actual)"}
					</Badge>
					<Badge color="zinc">Reenvío: {resendSeconds}s</Badge>
					<Badge color="zinc">Confianza paciente: {trustMinutes} min (no aplica aquí)</Badge>
				</div>

				<div className="rounded-2xl border border-amber-300/60 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-950/30 dark:text-amber-100">
					Esta pantalla no desbloquea resultados reales ni modifica la sesión OTP de pacientes. Solo registra
					eventos <code className="rounded bg-amber-100/80 px-1 text-xs dark:bg-amber-900/50">simulator_otp_*</code>{" "}
					en el log de acceso OTP.
				</div>

				<div className="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
					<div className="flex items-start gap-3">
						<div className="rounded-2xl bg-emerald-100 p-2.5 dark:bg-emerald-950/40">
							<ShieldCheckIcon className="size-6 text-emerald-700 dark:text-emerald-300" />
						</div>
						<div className="min-w-0 flex-1 space-y-4">
							<div>
								<Subheading>Contexto del pedido (opcional)</Subheading>
								<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
									Elige un pedido de laboratorio solo para asociar el registro en base de datos; el envío
									siempre va a tus datos de contacto.
								</Text>
							</div>

							<Field>
								<Label>Pedido de laboratorio</Label>
								<Listbox
									value={selectedPurchaseId}
									onChange={setSelectedPurchaseId}
									placeholder="Selecciona un pedido"
									disabled={purchases.length === 0}
								>
									{purchases.map((purchase) => (
										<ListboxOption key={purchase.id} value={purchase.id}>
											<ListboxLabel>
												#{purchase.id}
												{purchase.gda_order_id ? ` · GDA ${purchase.gda_order_id}` : ""} · {purchase.customer_label}{" "}
												· {purchase.created_at}
											</ListboxLabel>
										</ListboxOption>
									))}
								</Listbox>
							</Field>

							{selectedPurchase && (
								<Text className="text-sm text-zinc-600 dark:text-zinc-400">
									Pedido seleccionado: <Strong>#{selectedPurchase.id}</Strong>
									{selectedPurchase.gda_order_id ? ` (GDA ${selectedPurchase.gda_order_id})` : ""}
								</Text>
							)}

							{successMessage && (
								<div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-100">
									{successMessage}
								</div>
							)}

							{purchases.length === 0 && (
								<Text className="text-sm text-amber-700 dark:text-amber-200">
									No hay pedidos de laboratorio recientes para usar como contexto. Crea o consulta un pedido
									en el panel y vuelve a intentar.
								</Text>
							)}

							<Button
								color="famedic-lime"
								disabled={!selectedPurchaseId}
								onClick={() => {
									setSuccessMessage("");
									setShowModal(true);
								}}
							>
								Iniciar simulación OTP
							</Button>
						</div>
					</div>
				</div>

				<Text className="text-xs text-zinc-500 dark:text-slate-500">
					¿Necesitas otra herramienta?{" "}
					<Link href={route("admin.simulators.index")} className="text-famedic-light hover:underline">
						Volver al listado de simuladores
					</Link>
				</Text>
			</div>

			{selectedPurchaseId && apiUrls && (
				<SecurityVerificationModal
					isOpen={showModal}
					purchaseId={selectedPurchaseId}
					variant="simulator"
					apiUrls={apiUrls}
					onClose={() => setShowModal(false)}
					onSuccess={(result) => {
						setShowModal(false);
						setSuccessMessage(result?.message ?? "Simulación completada: código verificado correctamente.");
					}}
				/>
			)}
		</AdminLayout>
	);
}
