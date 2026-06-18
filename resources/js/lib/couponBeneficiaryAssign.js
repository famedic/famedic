export const BENEFICIARY_PREVIEW_STATUS = {
	valid_registered_user: {
		label: "Registrado",
		color: "emerald",
	},
	valid_pending_user: {
		label: "Pendiente de registro",
		color: "amber",
	},
	invalid_email: {
		label: "Inválido",
		color: "red",
	},
	duplicate_in_file: {
		label: "Duplicado",
		color: "orange",
	},
	already_beneficiary: {
		label: "Omitido",
		color: "zinc",
	},
	already_assigned: {
		label: "Omitido",
		color: "zinc",
	},
	no_account: {
		label: "Sin cuenta",
		color: "red",
	},
};

export function beneficiaryRowFromMatrix(row) {
	return {
		email: row.email?.trim() ?? "",
		first_name: row.first_name?.trim() || null,
		paternal_lastname: row.paternal_lastname?.trim() || null,
		maternal_lastname: row.maternal_lastname?.trim() || null,
	};
}

export function isConfirmableBeneficiaryStatus(status) {
	return status === "valid_registered_user" || status === "valid_pending_user";
}

/** Lookup de asignación manual: registrado o pendiente de registro. */
export function isMatrixRowLookupConfirmable(lookup) {
	if (!lookup) {
		return false;
	}

	return lookup.status === "found" || lookup.status === "missing";
}
