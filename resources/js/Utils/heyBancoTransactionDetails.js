export function getHeyBancoTransactionDetails(transaction) {
	if (!transaction || transaction.payment_method !== "hey_banco") {
		return null;
	}

	const details =
		transaction.details && typeof transaction.details === "object"
			? transaction.details
			: {};
	const tokenInfo =
		details.token_info && typeof details.token_info === "object"
			? details.token_info
			: {};
	const paymentDetails =
		details.payment_details && typeof details.payment_details === "object"
			? details.payment_details
			: {};

	return {
		cardBrand: tokenInfo.card_brand || details.card_brand,
		cardLastFour: tokenInfo.card_last_four || details.card_last_four,
		alias: tokenInfo.alias,
		banregioReference:
			paymentDetails.banregio_reference || transaction.gateway_transaction_id,
		authorizationCode:
			paymentDetails.authorization_code || transaction.gateway_authorization_code,
		clientReference: paymentDetails.reference || transaction.reference_id,
		folio: paymentDetails.folio,
	};
}
