/** @param {{ labResultsOtpRequired?: boolean } | undefined} pageProps */
export function isLabResultsOtpRequired(pageProps) {
	return Boolean(pageProps?.labResultsOtpRequired);
}
