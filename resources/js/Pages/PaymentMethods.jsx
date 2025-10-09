import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Text, Code } from "@/Components/Catalyst/text";
import { PlusIcon } from "@heroicons/react/16/solid";
import { TrashIcon, CreditCardIcon } from "@heroicons/react/24/outline";
import PaymentMethodDeleteConfirmation from "@/Pages/PaymentMethods/PaymentMethodDeleteConfirmation";
import { useState } from "react";
import CreditCardBrand from "@/Components/CreditCardBrand";
import SettingsCard from "@/Components/SettingsCard";

export default function PaymentMethods({ paymentMethods, hasOdessaPay }) {
	const [paymentMethodToDelete, setPaymentMethodToDelete] = useState(null);

	return (
		<SettingsLayout title="Mis métodos de pago">
			<div className="flex flex-wrap items-center justify-between gap-4">
				<GradientHeading noDivider>Mis métodos de pago</GradientHeading>

				<Button
					dusk="createPaymentMethod"
					preserveState
					preserveScroll
					href={route("payment-methods.create")}
				>
					<PlusIcon />
					Agregar tarjeta
				</Button>
			</div>

			<Divider className="my-10 mt-6" />

			<PaymentMethodsList
				paymentMethods={paymentMethods}
				setPaymentMethodToDelete={setPaymentMethodToDelete}
				hasOdessaPay={hasOdessaPay}
			/>

			<PaymentMethodDeleteConfirmation
				isOpen={!!paymentMethodToDelete}
				close={() => setPaymentMethodToDelete(null)}
				paymentMethod={paymentMethodToDelete}
			/>
		</SettingsLayout>
	);
}

function PaymentMethodsList({
	paymentMethods,
	setPaymentMethodToDelete,
	hasOdessaPay,
}) {
	return (
		<ul className="flex flex-wrap gap-8">
			{hasOdessaPay && <OdessaPaymentMethod />}
			{paymentMethods.map((paymentMethod) => (
				<SettingsCard
					key={paymentMethod.id}
					actions={
						<div className="flex w-full items-end justify-between gap-4">
							<Text>
								<span className="text-xs">
									{paymentMethod.card.exp_month} /{" "}
									{paymentMethod.card.exp_year}
								</span>
							</Text>
							<Button
								dusk={`deletePaymentMethod-${paymentMethod.id}`}
								onClick={() =>
									setPaymentMethodToDelete(paymentMethod)
								}
								outline
							>
								<TrashIcon className="stroke-red-400" />
								Eliminar
							</Button>
						</div>
					}
					className="min-h-[11.5rem] lg:w-auto lg:min-w-[20rem]"
				>
					<div className="flex justify-between">
						<CreditCardIcon className="size-6 stroke-zinc-500/40" />
						<CreditCardBrand brand={paymentMethod.card.brand} />
					</div>
					<Text className="mt-8">
						<Code>**** **** **** {paymentMethod.card.last4}</Code>
					</Text>
					<Text className="truncate">
						<span className="truncate text-sm">
							{paymentMethod.billing_details.name}
						</span>
					</Text>
				</SettingsCard>
			))}
		</ul>
	);
}

function OdessaPaymentMethod() {
	return (
		<SettingsCard className="min-h-[11.5rem] lg:w-auto lg:min-w-[20rem]">
			<div className="flex justify-between">
				<img
					src="/images/odessa.png"
					alt="odessa"
					className="h-6 w-6"
				/>
				<Text>odessa</Text>
			</div>
			<div className="mt-8">
				<Code>
					<span className="text-orange-600 dark:text-orange-400">
						Cobro a caja de ahorro
					</span>
				</Code>
			</div>
		</SettingsCard>
	);
}
