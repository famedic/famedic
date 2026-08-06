/**
 * Metadatos de procedencia del ActiveCampaign Operations Center.
 * Solo frontend — no altera DTOs ni servicios.
 */

/** @typedef {{ source: string, mode: string, quality: string, ttl?: string|null, endpoint?: string|null, service?: string|null, owner?: string }} Provenance */

/** @type {Record<string, Provenance>} */
export const EXECUTIVE_KPI_PROVENANCE = {
	contacts_total: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		endpoint: "customers COUNT(*)",
		service: "ActiveCampaignOperationsPlatformService",
		owner: "Platform / CRM",
	},
	synced_today: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		endpoint: "customers.ac_last_sync_at",
		service: "ActiveCampaignOperationsService::mirror",
		owner: "Integrations",
	},
	purchases_today: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		endpoint: "activecampaign_dispatches (purchase*)",
		service: "ActiveCampaignOperationsPlatformService",
		owner: "Integrations",
	},
	abandoned: {
		source: "PROXY",
		mode: "ESTIMATED",
		quality: "D",
		endpoint: "activecampaign_dispatches (%abandon%)",
		service: "ActiveCampaignOperationsPlatformService",
		owner: "Ecommerce",
	},
	memberships: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "B",
		endpoint: "activecampaign_dispatches (%membership%)",
		service: "ActiveCampaignOperationsPlatformService",
		owner: "Memberships",
	},
	webhooks: {
		source: "PROXY",
		mode: "ESTIMATED",
		quality: "F",
		endpoint: "inbound webhooks (no instrumentado)",
		service: "—",
		owner: "Integrations",
	},
	automations: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "B",
		endpoint: "activecampaign_dispatches (periodo)",
		service: "ActiveCampaignOperationsPlatformService",
		owner: "Automation Center",
	},
	api_errors: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		endpoint: "activecampaign_dispatches STATUS_FAILED",
		service: "ActiveCampaignOperationsService::synchronization",
		owner: "Integrations",
	},
};

/** @type {Record<string, Provenance>} */
export const SECTION_PROVENANCE = {
	executive: {
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "B",
		service: "ActiveCampaignOperationsPlatformService::buildExecutive",
		owner: "Operations",
	},
	funnel: {
		source: "FAMEDIC_DATABASE",
		mode: "CALCULATED",
		quality: "B",
		endpoint: "users · customers · purchases · memberships",
		service: "ActiveCampaignOperationsPlatformService::buildFunnel",
		owner: "Growth",
	},
	laboratories: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		endpoint: "laboratory_purchases GROUP BY brand",
		service: "ActiveCampaignLaboratoryIntelligenceService",
		owner: "Labs",
	},
	memberships: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "B",
		endpoint: "medical_attention_subscriptions",
		service: "ActiveCampaignMembershipIntelligenceService",
		owner: "Memberships",
	},
	purchases: {
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "B",
		endpoint: "lab + pharmacy + memberships",
		service: "ActiveCampaignEcommerceIntelligenceService",
		owner: "Ecommerce",
	},
	automations: {
		source: "HYBRID",
		mode: "LOCAL",
		quality: "B",
		endpoint: "Automation catalog + dispatches",
		service: "ActiveCampaignAutomationCenterService",
		owner: "Automation Center",
	},
	contact_health: {
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "C",
		endpoint: "mirror cache sample + domain counts",
		service: "ActiveCampaignOperationsPlatformService::buildContactHealth",
		owner: "CRM",
	},
	alerts: {
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "B",
		service: "ActiveCampaignOperationsPlatformService::buildAlerts",
		owner: "SRE / Integrations",
	},
	analytics: {
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "B",
		endpoint: "Chart.js series from platform DTOs",
		service: "ActiveCampaignOperationsPlatformService::buildAnalytics",
		owner: "Analytics",
	},
	api_health: {
		source: "ACTIVECAMPAIGN_API",
		mode: "LIVE",
		quality: "A",
		endpoint: "GET /api/3 (health probe)",
		ttl: null,
		service: "ActiveCampaignOperationsService::health",
		owner: "Integrations",
	},
	sync: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		endpoint: "activecampaign_dispatches (hoy)",
		service: "ActiveCampaignOperationsService::synchronization",
		owner: "Integrations",
	},
	mirror: {
		source: "ACTIVECAMPAIGN_MIRROR",
		mode: "CACHE",
		quality: "B",
		ttl: "5 min",
		endpoint: "ActiveCampaignCacheService snapshots",
		service: "ActiveCampaignOperationsService::mirror",
		owner: "Integrations",
	},
	intelligence: {
		source: "ACTIVECAMPAIGN_MIRROR",
		mode: "CACHE",
		quality: "B",
		ttl: "5 min",
		endpoint: "mirror snapshots (sample ≤100)",
		service: "ActiveCampaignOperationsService::contactIntelligence",
		owner: "CRM",
	},
	activity: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		endpoint: "activecampaign_dispatches recent",
		service: "ActiveCampaignOperationsService::activity",
		owner: "Integrations",
	},
	diagnostics: {
		source: "HYBRID",
		mode: "LIVE",
		quality: "A",
		endpoint: "test-api / diagnostic actions",
		service: "ActiveCampaignOperationsService::diagnostics",
		owner: "Integrations",
	},
	search: {
		source: "HYBRID",
		mode: "LIVE",
		quality: "B",
		endpoint: "users · contacts · dispatches · AC findContact",
		service: "ActiveCampaignOperationsPlatformService::searchGlobal",
		owner: "Operations",
	},
	filters: {
		source: "FAMEDIC_DATABASE",
		mode: "LOCAL",
		quality: "A",
		service: "ActiveCampaignDashboardFilter",
		owner: "Operations",
	},
	exports: {
		source: "HYBRID",
		mode: "CALCULATED",
		quality: "B",
		endpoint: "GET …/export (csv|xlsx|pdf)",
		service: "ActiveCampaignOperationsExport",
		owner: "Operations",
	},
};

export function provenanceForKpi(key) {
	return (
		EXECUTIVE_KPI_PROVENANCE[key] || {
			source: "HYBRID",
			mode: "CALCULATED",
			quality: "C",
		}
	);
}

export function provenanceForSection(key) {
	return (
		SECTION_PROVENANCE[key] || {
			source: "HYBRID",
			mode: "LOCAL",
			quality: "C",
		}
	);
}
