export const BENEFICIARY_PREVIEW_STATUS = {
	valid_registered_user: {
		label: "Usuario registrado: se asignará saldo",
		color: "emerald",
	},
	valid_pending_user: {
		label: "Pendiente de registro: se guardará como beneficiario pendiente",
		color: "amber",
	},
	invalid_email: {
		label: "Error: correo inválido",
		color: "red",
	},
	duplicate_in_file: {
		label: "Duplicado",
		color: "orange",
	},
	already_beneficiary: {
		label: "Ya asignado",
		color: "zinc",
	},
	already_assigned: {
		label: "Ya asignado",
		color: "zinc",
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
