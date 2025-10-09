import { Badge } from "@/Components/Catalyst/badge";
import OdessaLogo from "@/Components/OdessaLogo";

/**
 * Reusable ODESSA-branded orange badge component
 * Can be used for payment methods, customer types, or any ODESSA-related branding
 *
 * @param {string} reference - Optional reference ID to display
 * @param {string} className - Additional CSS classes
 * @param {ReactNode} children - Optional children to override the default "ODESSA" text
 */
export default function OdessaBadge({ reference, className = "", children }) {
	return (
		<Badge color="orange" className={className}>
			<OdessaLogo className="size-4 shrink-0" data-slot="icon" />
			{children || "ODESSA"}
			{reference && ` ${reference}`}
		</Badge>
	);
}
