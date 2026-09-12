import CatalogLandingTemplate from "./CatalogLandingTemplate";
import ConversionLandingTemplate from "./ConversionLandingTemplate";
import EditorialLandingTemplate from "./EditorialLandingTemplate";

export const landingTemplates = {
	conversion: ConversionLandingTemplate,
	editorial: EditorialLandingTemplate,
	catalog: CatalogLandingTemplate,
};

export function resolveLandingTemplate(value) {
	return landingTemplates[value] || landingTemplates.conversion;
}
