/**
 * Oculta un teléfono mostrando solo los últimos dígitos (alineado con mask_phone en PHP).
 * @param {string|null|undefined} phone
 * @returns {string}
 */
export function maskPhone(phone) {
  if (phone == null || phone === "") return "";
  const digits = String(phone).replace(/\D/g, "");
  if (!digits) return "***";
  const len = digits.length;
  if (len <= 2) {
    return `${"*".repeat(6)}${digits.slice(-2)}`;
  }
  const last = len >= 4 ? digits.slice(-4) : digits.slice(-2);
  return len >= 4 ? `*** *** ${last}` : `******${last}`;
}

/**
 * Oculta un correo (alineado con mask_email en PHP).
 * @param {string|null|undefined} email
 * @returns {string}
 */
export function maskEmail(email) {
  if (email == null || email === "") return "";
  const s = String(email);
  const at = s.indexOf("@");
  if (at < 0) return "***@***";
  const local = s.slice(0, at);
  const domain = s.slice(at + 1);
  if (local.length <= 1) {
    return `*@${domain}`;
  }
  const first = local.slice(0, 1);
  const starsLen = Math.max(3, local.length - 1);
  return `${first}${"*".repeat(starsLen)}@${domain}`;
}
