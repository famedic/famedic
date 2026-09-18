import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import Card from "@/Components/Card";
import CreditCardBrand from "@/Components/CreditCardBrand";
import { Badge } from "@/Components/Catalyst/badge";
import { router } from "@inertiajs/react";
import { checkoutSelectedStorePresentation } from "@/lib/laboratoryCompatibleStores";
import {
	UserCircleIcon,
	MapPinIcon,
	CreditCardIcon,
	BuildingStorefrontIcon,
	PencilIcon,
} from "@heroicons/react/24/outline";

const PAYPAL_LOGO_LIGHT = "https://cdn.simpleicons.org/paypal/003087";
const PAYPAL_LOGO_DARK = "https://cdn.simpleicons.org/paypal/FFFFFF";

export default function ConfirmationStep({
	data,
	contacts,
	addresses,
	paymentMethods,
	hasOdessaPay,
	hasPayPal,
	selectedCoupon,
	laboratoryAppointment,
	onEditStep,
	includePaymentSection = true,
	embedded = false,
	summaryDetails = null,
	studyItems = null,
	selectedLaboratoryStore = null,
	laboratoryBrand = null,
	selectedStoreError = null,
	showPreferredStore = false,
	requiresAppointment = false,
}) {
	const selectedContact = laboratoryAppointment
		? {
				full_name: laboratoryAppointment.patient_full_name,
				formatted_gender: laboratoryAppointment.formatted_patient_gender,
				formatted_birth_date:
					laboratoryAppointment.formatted_patient_birth_date,
				phone: laboratoryAppointment.patient_phone,
			}
		: contacts.find((c) => c.id == data.contact);

	const selectedAddress = addresses.find((a) => a.id == data.address);

	const paymentLabel = getPaymentLabel(
		data.payment_method,
		paymentMethods,
		hasPayPal,
		hasOdessaPay,
	);

	const totalRow = summaryDetails?.[summaryDetails.length - 1] ?? null;

	return (
		<div className="space-y-4">
			{!embedded && (
				<header className="min-w-0">
					<Subheading className="text-lg sm:text-xl">
						Revisa y confirma tu compra
					</Subheading>
					<Text className="mt-1.5 text-sm text-zinc-600 dark:text-slate-400">
						Verifica que tus datos sean correctos antes de
						confirmar.
					</Text>
				</header>
			)}

			{embedded && totalRow && (
				<div className="rounded-lg border border-zinc-200 bg-zinc-50/60 px-4 py-3 dark:border-slate-700 dark:bg-slate-800/40">
					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						{totalRow.label}
					</Text>
					<Text className="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
						{totalRow.value}
					</Text>
				</div>
			)}

			<div className="space-y-3">
				<ConfirmationSection
					icon={UserCircleIcon}
					title="Paciente"
					onEdit={() => onEditStep("patient")}
					canEdit={!laboratoryAppointment}
				>
					{selectedContact ? (
						<DetailBlock
							name={selectedContact.full_name}
							details={[
								selectedContact.formatted_gender,
								selectedContact.formatted_birth_date,
								selectedContact.phone,
							]}
						/>
					) : (
						<Text className="text-red-600 dark:text-red-400">
							No se ha seleccionado un paciente
						</Text>
					)}
				</ConfirmationSection>

				<ConfirmationSection
					icon={MapPinIcon}
					title="Dirección"
					onEdit={() => onEditStep("address")}
				>
					{selectedAddress ? (
						<DetailBlock
							name={`${selectedAddress.street} ${selectedAddress.number}`}
							details={[
								`${selectedAddress.neighborhood}, ${selectedAddress.zipcode}`,
								`${selectedAddress.state}, ${selectedAddress.city}`,
							]}
						/>
					) : (
						<Text className="text-red-600 dark:text-red-400">
							No se ha seleccionado una dirección
						</Text>
					)}
				</ConfirmationSection>

				{showPreferredStore && laboratoryBrand && (
					<PreferredLaboratoryStoreSection
						selection={selectedLaboratoryStore}
						laboratoryBrand={laboratoryBrand}
						error={selectedStoreError}
						requiresAppointment={requiresAppointment}
						laboratoryAppointment={laboratoryAppointment}
					/>
				)}

				{includePaymentSection && (
					<ConfirmationSection
						icon={CreditCardIcon}
						title="Método de pago"
						onEdit={() => onEditStep("payment")}
					>
						{paymentLabel ? (
							<PaymentSummary
								paymentMethod={data.payment_method}
								paymentMethods={paymentMethods}
								selectedCoupon={selectedCoupon}
							/>
						) : (
							<Text className="text-red-600 dark:text-red-400">
								No se ha seleccionado un método de pago
							</Text>
						)}
					</ConfirmationSection>
				)}

				{laboratoryAppointment?.laboratory_store && (
					<ConfirmationSection
						icon={BuildingStorefrontIcon}
						title="Cita en laboratorio"
						canEdit={false}
						highlight
					>
						<LaboratoryAppointmentSummary
							laboratoryAppointment={laboratoryAppointment}
						/>
						{!laboratoryAppointment.confirmed_at && (
							<Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400">
								Tu cita y horario se confirmarán contigo después
								del pago.
							</Text>
						)}
					</ConfirmationSection>
				)}
			</div>
		</div>
	);
}

function ConfirmationSection({
	icon: Icon,
	title,
	onEdit,
	canEdit = true,
	highlight = false,
	editLabel = "Cambiar",
	children,
}) {
	return (
		<Card
			className={
				highlight
					? "border-sky-200/80 bg-sky-50/30 p-3.5 sm:p-4 dark:border-sky-900/50 dark:bg-sky-950/15"
					: "border-zinc-200/80 p-3.5 sm:p-4 dark:border-zinc-700/80"
			}
		>
			<div className="flex items-center justify-between gap-3">
				<div className="flex min-w-0 items-center gap-2">
					<Icon className="size-4 shrink-0 text-famedic-dark dark:text-famedic-lime" />
					<Subheading className="text-sm">{title}</Subheading>
				</div>
				{canEdit && (
					<Button
						plain
						onClick={onEdit}
						type="button"
						className="shrink-0 !px-2 text-sm"
					>
						<PencilIcon className="size-3.5" />
						{editLabel}
					</Button>
				)}
			</div>
			<div className="mt-2.5 pl-6">{children}</div>
		</Card>
	);
}

function PreferredLaboratoryStoreSection({
	selection,
	laboratoryBrand,
	error = null,
	requiresAppointment = false,
	laboratoryAppointment = null,
}) {
	const presentation = checkoutSelectedStorePresentation(selection);
	const cartUrl = route("laboratory.shopping-cart", {
		laboratory_brand: laboratoryBrand.value,
	});
	const goToCart = () => router.visit(cartUrl);

	if (presentation.state === "missing") {
		return (
			<ConfirmationSection
				icon={BuildingStorefrontIcon}
				title="Sucursal preferida"
				onEdit={goToCart}
				editLabel="Elegir sucursal"
			>
				<div className="space-y-1">
					<Text className="font-medium text-zinc-900 dark:text-zinc-100">
						No seleccionada · Opcional
					</Text>
					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						Puedes continuar sin seleccionar una sucursal.
					</Text>
					{requiresAppointment &&
						!laboratoryAppointment?.confirmed_at && (
							<Text className="pt-1 text-sm text-zinc-600 dark:text-slate-400">
								La cita y horario se confirman contigo después.
							</Text>
						)}
				</div>
			</ConfirmationSection>
		);
	}

	const isValid = presentation.state === "valid";

	return (
		<ConfirmationSection
			icon={BuildingStorefrontIcon}
			title="Sucursal preferida"
			onEdit={goToCart}
			editLabel={isValid ? "Cambiar" : presentation.actionLabel}
			highlight={!isValid}
		>
			<div className="space-y-1">
				{isValid && presentation.storeName ? (
					<DetailBlock
						name={presentation.storeName}
						details={[
							error || presentation.message,
							"Preferencia opcional",
							"Puedes cambiarla",
						]}
					/>
				) : (
					<>
						<Text className="font-medium text-zinc-900 dark:text-zinc-100">
							{presentation.title}
						</Text>
						{(error || presentation.message) && (
							<Text className="text-sm text-zinc-600 dark:text-slate-400">
								{error || presentation.message}
							</Text>
						)}
					</>
				)}
				{requiresAppointment &&
					!laboratoryAppointment?.confirmed_at && (
						<Text className="pt-1 text-sm text-zinc-600 dark:text-slate-400">
							La cita y horario se confirman contigo después.
						</Text>
					)}
			</div>
		</ConfirmationSection>
	);
}

function DetailBlock({ name, details }) {
	return (
		<div className="space-y-0.5">
			<Text className="font-medium text-zinc-900 dark:text-zinc-100">
				{name}
			</Text>
			{details.filter(Boolean).map((detail, i) => (
				<Text
					key={i}
					className="text-sm text-zinc-600 dark:text-slate-400"
				>
					{detail}
				</Text>
			))}
		</div>
	);
}

function LaboratoryAppointmentSummary({ laboratoryAppointment }) {
	const store = laboratoryAppointment.laboratory_store;
	const storeName = store?.name
		? store.name.charAt(0).toUpperCase() + store.name.slice(1).toLowerCase()
		: null;

	return (
		<div className="space-y-1.5">
			<Text className="font-medium text-zinc-900 dark:text-zinc-100">
				Sucursal:{" "}
				<span className="font-semibold">{storeName}</span>
			</Text>
			{store?.address && (
				<Text className="text-sm text-zinc-600 dark:text-slate-400">
					{store.address}
				</Text>
			)}
			{laboratoryAppointment.formatted_appointment_date && (
				<Badge color="sky" className="mt-1">
					{laboratoryAppointment.formatted_appointment_date}
				</Badge>
			)}
		</div>
	);
}

function PaymentSummary({ paymentMethod, paymentMethods, selectedCoupon }) {
	if (paymentMethod === "paypal") {
		return (
			<div className="flex items-center gap-2.5">
				<img
					src={PAYPAL_LOGO_LIGHT}
					alt="PayPal"
					className="h-5 w-auto dark:hidden"
				/>
				<img
					src={PAYPAL_LOGO_DARK}
					alt="PayPal"
					className="hidden h-5 w-auto dark:block"
				/>
				<Text className="font-medium">PayPal</Text>
			</div>
		);
	}

	if (paymentMethod === "odessa") {
		return (
			<DetailBlock
				name="Caja de ahorro Odessa"
				details={["Cobro directo a tu caja de ahorro"]}
			/>
		);
	}

	if (paymentMethod === "coupon_balance") {
		return (
			<DetailBlock
				name="Saldo a favor (cupón)"
				details={[
					selectedCoupon
						? `Cupón aplicado por ${(selectedCoupon.remaining_cents / 100).toLocaleString("es-MX", { style: "currency", currency: "MXN" })}`
						: "El total se cubre con tu saldo disponible",
				]}
			/>
		);
	}

	const card = paymentMethods.find(
		(pm) => String(pm.id) === String(paymentMethod),
	);

	if (card) {
		return (
			<div className="flex items-center gap-2.5">
				<CreditCardBrand brand={card.card?.brand} className="size-6" />
				<div>
					<Text className="font-medium">**** {card.card?.last4}</Text>
					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						{card.billing_details?.name}
					</Text>
				</div>
			</div>
		);
	}

	return null;
}

function getPaymentLabel(
	paymentMethod,
	paymentMethods,
	hasPayPal,
	hasOdessaPay,
) {
	if (!paymentMethod) return null;
	if (paymentMethod === "paypal" && hasPayPal) return "PayPal";
	if (paymentMethod === "odessa" && hasOdessaPay)
		return "Caja de ahorro Odessa";
	if (paymentMethod === "coupon_balance") return "Saldo a favor";
	const card = paymentMethods.find(
		(pm) => String(pm.id) === String(paymentMethod),
	);
	if (card) return `Tarjeta **** ${card.card?.last4}`;
	return null;
}
