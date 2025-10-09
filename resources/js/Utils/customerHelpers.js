// Helper functions to get customer data based on account type

export function getCustomerFullName(customer) {
	if (customer.customerable_type === "App\\Models\\FamilyAccount") {
		return customer.customerable?.full_name || null;
	}
	return customer.user?.full_name || null;
}

export function getCustomerBirthDate(customer) {
	if (customer.customerable_type === "App\\Models\\FamilyAccount") {
		return customer.customerable?.formatted_birth_date || null;
	}
	return customer.user?.formatted_birth_date || null;
}

export function getCustomerPhone(customer) {
	if (customer.customerable_type === "App\\Models\\FamilyAccount") {
		// Family accounts don't have their own phone, use parent's phone
		return customer.customerable?.parentCustomer?.user?.full_phone || null;
	}
	return customer.user?.full_phone || null;
}

export function getCustomerEmail(customer) {
	if (customer.customerable_type === "App\\Models\\FamilyAccount") {
		// Family accounts don't have their own email, use parent's email
		return customer.customerable?.parentCustomer?.user?.email || null;
	}
	return customer.user?.email || null;
}

export function getCustomerGender(customer) {
	if (customer.customerable_type === "App\\Models\\FamilyAccount") {
		return customer.customerable?.formatted_gender || null;
	}
	return customer.user?.formatted_gender || null;
}
