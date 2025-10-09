export default function PaymentMethodStep({
	data,
	setData,
	errors,
	clearErrors,
	description = "Selecciona el método de pago que deseas utilizar para tu pedido.",
	paymentMethods,
	hasOdessaPay,
	addCardReturnUrl,
	forceMobile = false,
	...props
}) {
	const selectedPaymentMethod = useMemo(() => {
		if (data.payment_method === "odessa") {
			return "odessa";
		}
		return paymentMethods.find(
			(paymentMethod) => paymentMethod.id === data.payment_method,
		);
	}, [data.payment_method]);

	const stepHeading = useMemo(() => {
		return data.payment_method
			? "Método de pago"
			: "Selecciona el método de pago";
	}, [data.payment_method]);

	return (
		<CheckoutStep
			{...props}
			IconComponent={CreditCardIcon}
			heading={stepHeading}
			description={description}
			selectedContent={
				selectedPaymentMethod === "odessa" ? (
					<div>
						<div className="flex gap-1">
							<img
								src="/images/odessa.png"
								alt="odessa"
								className="h-6 w-6"
							/>
							<Text>odessa</Text>
						</div>
						<div>
							<Text>
								<Code>
									<span className="text-orange-600 dark:text-orange-400">
										Cobro a caja de ahorro
									</span>
								</Code>
							</Text>
						</div>
					</div>
				) : (
					<div>
						<div>
							<div className="flex items-center gap-2">
								<CreditCardBrand
									brand={selectedPaymentMethod?.card.brand}
								/>
								<Code>{selectedPaymentMethod?.card.last4}</Code>
							</div>
							<Text className="truncate">
								<span className="truncate text-sm">
									{
										selectedPaymentMethod?.billing_details
											.name
									}
								</span>
							</Text>
							<Text>
								<span className="text-xs">
									{selectedPaymentMethod?.card.exp_month} /{" "}
									{selectedPaymentMethod?.card.exp_year}
								</span>
							</Text>
						</div>
					</div>
				)
			}
			formContent={
				<PaymentMethodSelection
					forceMobile={forceMobile}
					addCardReturnUrl={addCardReturnUrl}
					setData={setData}
					paymentMethods={paymentMethods}
					hasOdessaPay={hasOdessaPay}
					clearErrors={clearErrors}
				/>
			}
			onClickEdit={() => setData("payment_method", null)}
		/>
	);
}

function PaymentMethodSelection({
	setData,
	addCardReturnUrl,
	paymentMethods,
	hasOdessaPay,
	clearErrors,
	forceMobile = false,
}) {
	const close = useClose();

	const selectPaymentMethod = (paymentMethod) => {
		setData("payment_method", paymentMethod.id);
		clearErrors("payment_method");
		close();
	};

	const addCardUrl = useMemo(() => {
		return route("payment-methods.create", {
			return_url: addCardReturnUrl,
		});
	}, [addCardReturnUrl]);

	return (
		<ul
			className={`mt-3 grid gap-8 ${!forceMobile ? "sm:grid-cols-2" : ""}`}
		>
			{hasOdessaPay && (
				<CheckoutSelectionCard
					onClick={() => selectPaymentMethod({ id: "odessa" })}
					className="min-h-[13rem]"
				>
					<div className="flex h-full flex-col justify-between">
						<div className="flex justify-between">
							<img
								src="/images/odessa.png"
								alt="odessa"
								className="h-6 w-6"
							/>
							<Text>odessa</Text>
						</div>
						<div className="mb-2">
							<Code>
								<span className="text-orange-600 dark:text-orange-400">
									Cobro a caja de ahorro
								</span>
							</Code>
						</div>
					</div>
				</CheckoutSelectionCard>
			)}
			{paymentMethods.map((paymentMethod) => (
				<CheckoutSelectionCard
					onClick={() => selectPaymentMethod(paymentMethod)}
					key={paymentMethod.id}
					className="min-h-[13rem]"
				>
					<div className="flex h-full flex-col justify-between">
						<div className="mb-2 flex justify-end">
							<CreditCardBrand brand={paymentMethod.card.brand} />
						</div>
						<div>
							<Text>
								<Code>
									**** **** **** {paymentMethod.card.last4}
								</Code>
							</Text>
							<Text className="truncate">
								<span className="truncate text-sm">
									{paymentMethod.billing_details.name}
								</span>
							</Text>
							<Text>
								<span className="text-xs">
									{paymentMethod.card.exp_month} /{" "}
									{paymentMethod.card.exp_year}
								</span>
							</Text>
						</div>
					</div>
				</CheckoutSelectionCard>
			))}
			<CheckoutSelectionCard
				href={addCardUrl}
				heading="Nueva tarjeta"
				IconComponent={PlusIcon}
				greenIcon
				className="min-h-[13rem]"
			>
				<Text className="line-clamp-3 max-w-64">
					Puedes agregar una nueva tarjeta y guardarla. Tu información
					esta siempre protegida.
				</Text>
			</CheckoutSelectionCard>
		</ul>
	);
}

import { useMemo } from "react";
import { Text, Code } from "@/Components/Catalyst/text";
import { useClose } from "@headlessui/react";
import { PlusIcon } from "@heroicons/react/16/solid";
import CheckoutStep from "@/Components/Checkout/CheckoutStep";
import { CreditCardIcon } from "@heroicons/react/24/solid";
import CheckoutSelectionCard from "@/Components/Checkout/CheckoutSelectionCard";
import CreditCardBrand from "@/Components/CreditCardBrand";
