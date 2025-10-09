import { Code } from "@/Components/Catalyst/text";
import OdessaBadge from "@/Components/OdessaBadge";
import CreditCardBrand from "@/Components/CreditCardBrand";

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

	const isOdessa = transaction.payment_method === "odessa";

	// ODESSA payment display
	if (isOdessa) {
		const reference = showReference ? transaction.reference_id : null;
		return <OdessaBadge reference={reference} />;
	}

	// Stripe payment with credit card details
	return (
		<div className="flex items-center gap-2">
			<CreditCardBrand brand={transaction.details?.card_brand} />
			<Code>{transaction.details?.card_last_four}</Code>
		</div>
	);
}
