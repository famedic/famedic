/**
 * ID del perfil predeterminado para preselección en facturación.
 * Solo presentación; el backend valida ownership y estado activo.
 */
export function getDefaultTaxProfileId(taxProfiles = []) {
	const defaultProfile = taxProfiles.find(
		(profile) => profile?.is_default === true,
	);
	return defaultProfile?.id ?? null;
}
