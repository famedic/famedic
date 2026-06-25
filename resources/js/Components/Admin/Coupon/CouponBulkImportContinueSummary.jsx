import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";

export default function CouponBulkImportContinueSummary({
	assignRegistered = 0,
	createPending = 0,
	skipInvalid = 0,
	ignoreDuplicates = 0,
}) {
	return (
		<CouponSectionCard title="Qué sucederá al continuar">
			<ul className="space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
				<li>
					Se asignarán <strong>{assignRegistered}</strong> crédito(s) a usuarios registrados
				</li>
				<li>
					Se crearán <strong>{createPending}</strong> beneficiario(s) pendientes de registro
				</li>
				<li>
					Se omitirán <strong>{skipInvalid}</strong> registro(s) inválidos
				</li>
				<li>
					Se ignorarán <strong>{ignoreDuplicates}</strong> duplicado(s)
				</li>
			</ul>
		</CouponSectionCard>
	);
}
