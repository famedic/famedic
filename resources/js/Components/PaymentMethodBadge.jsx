import { Code } from "@/Components/Catalyst/text";
import OdessaBadge from "@/Components/OdessaBadge";
import CreditCardBrand from "@/Components/CreditCardBrand";
import EfevooPayBadge from "@/Components/EfevooPayBadge";

/**
 * Centralized component for displaying payment method information
 * Handles ODESSA payments with orange branding and Stripe payments with credit card details
 *
 * @param {Object} transaction - Transaction object containing payment_method, details, and reference_id
 * @param {boolean} showReference - Whether to show reference ID for ODESSA payments
 */
export default function PaymentMethodBadge({
	transaction,
	showReference = false,
}) {
	if (!transaction) return null;

	const { payment_method, details, reference_id, gateway_transaction_id } = transaction;
	
	// ODESSA payment display
	if (payment_method === "odessa") {
		const reference = showReference ? reference_id : null;
		return <OdessaBadge reference={reference} />;
	}

	// EfevooPay payment display
	if (payment_method === "efevoopay") {
		return (
			<div className="flex flex-col gap-1">
				<EfevooPayBadge />
				{/* Mostrar detalles de EfevooPay si están disponibles */}
				{(details?.authorization_code || gateway_transaction_id) && (
					<div className="text-xs text-gray-500 space-y-0.5">
						{gateway_transaction_id && (
							<div className="truncate" title={`ID: ${gateway_transaction_id}`}>
								ID: {gateway_transaction_id.substring(0, 8)}...
							</div>
						)}
						{details?.authorization_code && (
							<div>Auth: {details.authorization_code}</div>
						)}
					</div>
				)}
			</div>
		);
	}

	// Stripe payment with credit card details
	if (payment_method === "stripe") {
		return (
			<div className="flex items-center gap-2">
				<CreditCardBrand brand={details?.card_brand} />
				<Code>{details?.card_last_four}</Code>
			</div>
		);
	}

	// Default/unknown payment method
	return (
		<div className="text-xs text-gray-500">
			{payment_method || "Desconocido"}
		</div>
	);
}