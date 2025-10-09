/**
 * Converts Laravel pagination URLs to use custom parameter names
 * This fixes the issue where multiple paginated tables on the same page
 * interfere with each other's pagination state
 *
 * @param {string} url - The original pagination URL
 * @param {string} paramName - The custom parameter name (e.g., 'lab_page', 'pharmacy_page')
 * @returns {string} - Modified URL with custom parameter name
 */
export function convertPaginationUrl(url, paramName) {
	if (!url) return null;

	try {
		const urlObj = new URL(url);
		const pageValue = urlObj.searchParams.get("page");

		if (pageValue) {
			// Remove the default 'page' parameter
			urlObj.searchParams.delete("page");
			// Add the custom parameter name
			urlObj.searchParams.set(paramName, pageValue);
		}

		return urlObj.toString();
	} catch (error) {
		console.error("Error converting pagination URL:", error);
		return url;
	}
}
/**
 * Converts all pagination links in a Laravel pagination object
 * to use a custom parameter name
 *
 * @param {Object} paginationData - Laravel pagination object
 * @param {string} paramName - The custom parameter name
 * @returns {Object} - Modified pagination object
 */
export function convertPaginationData(paginationData, paramName) {
	if (!paginationData) return null;

	return {
		...paginationData,
		prev_page_url: convertPaginationUrl(
			paginationData.prev_page_url,
			paramName,
		),
		next_page_url: convertPaginationUrl(
			paginationData.next_page_url,
			paramName,
		),
		links: paginationData.links.map((link) => ({
			...link,
			url: convertPaginationUrl(link.url, paramName),
		})),
	};
}
