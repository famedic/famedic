import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import { formatMxnFromCents } from "@/lib/couponFormat";

function formatMxFromCents(cents) {
	return formatMxnFromCents(cents);
}

export default function CouponRuleSummary({
	title = "Reglas y aprobaciones",
	approvalsPreview = 0,
	approvalRealtime,
	rulesForUi,
	beneficiaryHint,
}) {
	return (
		<CouponSectionCard title={title} bodyClassName="space-y-4">
			<div className="rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 text-sm dark:border-zinc-600 dark:bg-zinc-950/50">
				<p className="text-zinc-800 dark:text-zinc-200">
					Aprobaciones estimadas: <strong>{approvalsPreview}</strong>
					{beneficiaryHint ? <> ({beneficiaryHint})</> : null}
				</p>
				{approvalRealtime ? (
					<p className="mt-2 text-zinc-700 dark:text-zinc-300">
						<strong>{approvalRealtime.title}.</strong> {approvalRealtime.detail}
					</p>
				) : null}
			</div>
			{(rulesForUi?.amount_rules?.length ?? 0) > 0 && (
				<div>
					<p className="text-sm font-medium text-zinc-900 dark:text-white">
						Rangos por monto
					</p>
					<ul className="mt-2 list-inside list-disc text-sm text-zinc-700 dark:text-zinc-300">
						{rulesForUi.amount_rules.map((r, i) => (
							<li key={i}>
								{formatMxFromCents(r.min_amount_cents ?? 0)} —{" "}
								{r.max_amount_cents != null
									? formatMxFromCents(r.max_amount_cents)
									: "∞"}{" "}
								→ {r.required_approvals ?? 0} aprobación(es)
							</li>
						))}
					</ul>
				</div>
			)}
			{(rulesForUi?.beneficiary_rules?.length ?? 0) > 0 && (
				<div>
					<p className="text-sm font-medium text-zinc-900 dark:text-white">
						Rangos por beneficiarios
					</p>
					<ul className="mt-2 list-inside list-disc text-sm text-zinc-700 dark:text-zinc-300">
						{rulesForUi.beneficiary_rules.map((r, i) => (
							<li key={i}>
								{r.min_beneficiaries} — {r.max_beneficiaries ?? "∞"} personas →{" "}
								{r.required_approvals} aprobación(es)
							</li>
						))}
					</ul>
				</div>
			)}
		</CouponSectionCard>
	);
}
