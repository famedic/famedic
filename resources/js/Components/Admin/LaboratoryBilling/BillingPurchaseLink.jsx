import { Button } from "@/Components/Catalyst/button";
import { ArrowTopRightOnSquareIcon } from "@heroicons/react/16/solid";

/**
 * Enlace al pedido de laboratorio con apariencia de acción (icono de abrir).
 */
export default function BillingPurchaseLink({
	href,
	label,
	className,
}) {
	const text = label || "—";

	if (!href) {
		return <span className={className}>{text}</span>;
	}

	return (
		<Button
			href={href}
			outline
			className={className}
			title="Ver pedido"
			aria-label={`Ver pedido ${text}`}
		>
			<span className="max-w-[10rem] truncate font-medium">{text}</span>
			<ArrowTopRightOnSquareIcon data-slot="icon" />
		</Button>
	);
}
