import { BENEFICIARY_PREVIEW_STATUS, isConfirmableBeneficiaryStatus } from "@/lib/couponBeneficiaryAssign";

export const BULK_IMPORT_LARGE_FILE_THRESHOLD = 500;
export const BULK_IMPORT_PAGE_SIZE = 100;

export const BULK_IMPORT_FILTER_OPTIONS = [
	{ id: "all", label: "Todos" },
	{ id: "registered", label: "Registrados" },
	{ id: "pending", label: "Pendientes de registro" },
	{ id: "invalid", label: "Inválidos" },
	{ id: "duplicate", label: "Duplicados" },
	{ id: "selected", label: "Seleccionados" },
	{ id: "unselected", label: "No seleccionados" },
];

const DUPLICATE_STATUSES = new Set([
	"duplicate_in_file",
	"already_beneficiary",
	"already_assigned",
]);

const OBSERVATION_BY_STATUS = {
	valid_registered_user: "Usuario encontrado en la plataforma",
	valid_pending_user: "No tiene cuenta; se guardará como pendiente",
	invalid_email: "Correo inválido",
	duplicate_in_file: "Duplicado en archivo",
	already_beneficiary: "Ya existe en la campaña",
	already_assigned: "Ya tiene saldo asignado en la campaña",
	no_account: "Sin cuenta en la plataforma; no se asignará en este flujo",
};

export function resolveBulkRowStatus(row) {
	if (row.status) {
		return row.status;
	}
	if (row.exists === true) {
		return "valid_registered_user";
	}
	if (row.exists === false) {
		return "valid_pending_user";
	}
	return "invalid_email";
}

export function getBulkRowStatusMeta(row) {
	const status = resolveBulkRowStatus(row);
	if (BENEFICIARY_PREVIEW_STATUS[status]) {
		return BENEFICIARY_PREVIEW_STATUS[status];
	}
	if (status === "no_account") {
		return { label: "Sin cuenta", color: "red" };
	}
	return { label: status, color: "zinc" };
}

export function getBulkRowObservation(row) {
	const status = resolveBulkRowStatus(row);
	if (row.messages?.length) {
		return row.messages.join(" ");
	}
	return OBSERVATION_BY_STATUS[status] ?? "—";
}

export function isBulkRowConfirmable(row) {
	const status = resolveBulkRowStatus(row);
	return isConfirmableBeneficiaryStatus(status);
}

export function isBulkRowDuplicate(row) {
	return DUPLICATE_STATUSES.has(resolveBulkRowStatus(row));
}

export function isBulkRowInvalid(row) {
	const status = resolveBulkRowStatus(row);
	return status === "invalid_email";
}

export function computeBulkImportSummary(rows) {
	const summary = {
		total: rows.length,
		selected: 0,
		registered: 0,
		pending: 0,
		invalid: 0,
		duplicate: 0,
		skipped: 0,
		confirmableSelected: 0,
	};

	for (const row of rows) {
		const status = resolveBulkRowStatus(row);
		if (row.include) {
			summary.selected += 1;
			if (isConfirmableBeneficiaryStatus(status)) {
				summary.confirmableSelected += 1;
			}
		}

		if (status === "valid_registered_user") {
			summary.registered += 1;
		} else if (status === "valid_pending_user") {
			summary.pending += 1;
		} else if (DUPLICATE_STATUSES.has(status)) {
			summary.duplicate += 1;
			summary.skipped += 1;
		} else if (status === "invalid_email") {
			summary.invalid += 1;
			summary.skipped += 1;
		}
	}

	return summary;
}

export function mergeBulkPreviewSummary(rows, apiSummary) {
	const computed = computeBulkImportSummary(rows);
	if (!apiSummary || typeof apiSummary !== "object") {
		return computed;
	}

	return {
		...computed,
		total: apiSummary.total ?? computed.total,
		registered: apiSummary.valid_registered_user ?? computed.registered,
		pending: apiSummary.valid_pending_user ?? computed.pending,
		duplicate:
			(apiSummary.duplicate_in_file ?? 0) +
			(apiSummary.already_beneficiary ?? 0) +
			(apiSummary.already_assigned ?? 0),
	};
}

export function filterBulkImportRows(rows, { filter = "all", search = "" } = {}) {
	const q = search.trim().toLowerCase();

	return rows.filter((row, index) => {
		const status = resolveBulkRowStatus(row);

		if (filter === "registered" && status !== "valid_registered_user") return false;
		if (filter === "pending" && status !== "valid_pending_user") return false;
		if (filter === "invalid" && status !== "invalid_email") {
			return false;
		}
		if (filter === "duplicate" && !DUPLICATE_STATUSES.has(status)) return false;
		if (filter === "selected" && !row.include) return false;
		if (filter === "unselected" && row.include) return false;

		if (q === "") return true;

		const haystack = [
			row.email,
			row.first_name,
			row.paternal_lastname,
			row.maternal_lastname,
			row.user_name,
			getBulkRowObservation(row),
		]
			.filter(Boolean)
			.join(" ")
			.toLowerCase();

		return haystack.includes(q);
	});
}

export function paginateBulkImportRows(rows, page, pageSize = BULK_IMPORT_PAGE_SIZE) {
	const total = rows.length;
	const totalPages = Math.max(1, Math.ceil(total / pageSize));
	const safePage = Math.min(Math.max(1, page), totalPages);
	const start = (safePage - 1) * pageSize;

	return {
		page: safePage,
		totalPages,
		pageSize,
		total,
		items: rows.slice(start, start + pageSize),
		showingFrom: total === 0 ? 0 : start + 1,
		showingTo: Math.min(start + pageSize, total),
	};
}

export function buildContinueSummary(summary) {
	return summary;
}

export function computeContinueSummaryFromRows(rows) {
	let assignRegistered = 0;
	let createPending = 0;

	for (const row of rows) {
		if (!row.include) continue;
		const status = resolveBulkRowStatus(row);
		if (status === "valid_registered_user") assignRegistered += 1;
		if (status === "valid_pending_user") createPending += 1;
	}

	const summary = computeBulkImportSummary(rows);

	return {
		assignRegistered,
		createPending,
		skipInvalid: summary.invalid,
		ignoreDuplicates: summary.duplicate,
		selected: summary.selected,
	};
}
