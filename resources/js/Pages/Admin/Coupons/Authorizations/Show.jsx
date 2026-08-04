import { useState } from "react";
import { router } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import CouponAuthorizationOtpModal from "@/Components/Admin/Coupon/CouponAuthorizationOtpModal";
import CouponAuthorizationRejectModal from "@/Components/Admin/Coupon/CouponAuthorizationRejectModal";
import CouponOtpSecurityNotice from "@/Components/Admin/Coupon/CouponOtpSecurityNotice";
import { formatShortDateTime } from "@/lib/couponFormat";

function ParticipantRow({ participant }) {
	return (
		<div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
			<div>
				<p className="text-sm font-medium">
					{participant.label}
					{participant.is_me && (
						<span className="ml-2 text-xs font-normal text-zinc-500">(tú)</span>
					)}
				</p>
				{participant.email && <p className="text-xs text-zinc-500">{participant.email}</p>}
			</div>
			<div className="text-right">
				<Badge
					color={
						participant.status === "approved"
							? "green"
							: participant.status === "rejected"
								? "red"
								: "amber"
					}
				>
					{participant.status === "approved"
						? "Aprobó"
						: participant.status === "rejected"
							? "Rechazó"
							: "Pendiente"}
				</Badge>
				{participant.acted_at && (
					<p className="mt-1 text-xs text-zinc-500">{formatShortDateTime(participant.acted_at)}</p>
				)}
				{participant.comment && (
					<p className="mt-1 max-w-xs text-xs text-zinc-600 dark:text-zinc-400">{participant.comment}</p>
				)}
			</div>
		</div>
	);
}

export default function AuthorizationsShow({ authorization }) {
	const [approveOpen, setApproveOpen] = useState(false);
	const [rejectOpen, setRejectOpen] = useState(false);
	const [submitting, setSubmitting] = useState(false);

	const coupon = authorization?.coupon ?? {};
	const promo = authorization?.promo_code;
	const assignment = authorization?.assignment_request;
	const master = authorization?.master_activation;
	const approvalRequestId = assignment?.id ?? null;

	const progress = assignment
		? {
				current: assignment.current_approvals,
				required: assignment.required_approvals,
				remaining: assignment.remaining_approvals,
			}
		: master
			? {
					current: master.current_approvals,
					required: master.required_approvals,
					remaining: master.remaining_approvals,
				}
			: null;

	const handleOtpSuccess = (result) => {
		setSubmitting(true);
		router.post(
			route("admin.coupons.authorizations.approve", coupon.id),
			{
				otp_verification_token: result.verification_token,
				approval_request_id: approvalRequestId,
			},
			{
				onFinish: () => {
					setSubmitting(false);
					setApproveOpen(false);
				},
			},
		);
	};

	return (
		<AdminLayout title={`Autorización #${coupon.id}`}>
			<div className="space-y-8">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<div className="flex flex-wrap items-center gap-2">
							<Badge color="zinc">{authorization.credit_type_label}</Badge>
							<Badge color="purple">Pendiente de autorización</Badge>
						</div>
						<Heading className="mt-2">
							{coupon.code || promo?.code || coupon.description || `Crédito #${coupon.id}`}
						</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Revisa la información antes de aprobar o rechazar.
						</Text>
					</div>
					<div className="flex flex-col items-stretch gap-3 sm:items-end">
						{authorization.i_can_approve && (
							<CouponOtpSecurityNotice variant="approval" compact className="sm:text-right" />
						)}
						<div className="flex flex-wrap gap-2">
						<Button href={route("admin.coupons.authorizations.index")} outline>
							Volver a la bandeja
						</Button>
						{authorization.i_can_approve && (
							<Button color="famedic-lime" onClick={() => setApproveOpen(true)} disabled={submitting}>
								Aprobar
							</Button>
						)}
						{authorization.i_can_reject && (
							<Button color="red" outline onClick={() => setRejectOpen(true)} disabled={submitting}>
								Rechazar
							</Button>
						)}
						</div>
					</div>
				</div>

				{authorization.is_creator && (
					<div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-100">
						Eres el creador de esta solicitud. Un autorizador distinto debe aprobarla o rechazarla.
					</div>
				)}

				{progress && (
					<CouponSectionCard title="Estado de aprobaciones">
						<p className="text-lg font-semibold">
							{progress.current}/{progress.required} firmas recibidas
						</p>
						{progress.remaining > 0 ? (
							<Text className="mt-1 text-zinc-600 dark:text-zinc-400">
								Faltan {progress.remaining} aprobación(es) para completar la solicitud.
							</Text>
						) : (
							<Text className="mt-1 text-emerald-700 dark:text-emerald-300">
								La solicitud tiene todas las firmas requeridas.
							</Text>
						)}
					</CouponSectionCard>
				)}

				<div className="grid gap-6 lg:grid-cols-2">
					<CouponSectionCard title="Detalle">
						<dl className="space-y-3 text-sm">
							<div>
								<dt className="text-zinc-500">Tipo</dt>
								<dd>{coupon.type_label || authorization.credit_type_label}</dd>
							</div>
							<div>
								<dt className="text-zinc-500">Monto</dt>
								<dd className="font-semibold">{coupon.formatted_amount}</dd>
							</div>
							<div>
								<dt className="text-zinc-500">Descripción</dt>
								<dd>{coupon.description || "—"}</dd>
							</div>
							<div>
								<dt className="text-zinc-500">Concepto</dt>
								<dd>{coupon.concept || "—"}</dd>
							</div>
							<div>
								<dt className="text-zinc-500">Compra mínima</dt>
								<dd>{coupon.formatted_min_purchase || "Sin requisito"}</dd>
							</div>
							<div>
								<dt className="text-zinc-500">Beneficiarios máx.</dt>
								<dd>{coupon.max_beneficiaries ?? "Sin límite"}</dd>
							</div>
							<div>
								<dt className="text-zinc-500">Vigencia</dt>
								<dd>
									{coupon.valid_from
										? `Desde ${formatShortDateTime(coupon.valid_from)}`
										: "Sin inicio"}
									{coupon.expires_at
										? ` · Hasta ${formatShortDateTime(coupon.expires_at)}`
										: " · Sin fin"}
								</dd>
							</div>
							<div>
								<dt className="text-zinc-500">Creador</dt>
								<dd>
									{coupon.creator?.name || "—"}
									{coupon.creator?.email && (
										<span className="block text-xs text-zinc-500">{coupon.creator.email}</span>
									)}
								</dd>
							</div>
						</dl>
					</CouponSectionCard>

					{promo && (
						<CouponSectionCard title="Código promocional">
							<dl className="space-y-3 text-sm">
								<div>
									<dt className="text-zinc-500">Código</dt>
									<dd className="font-mono">{promo.code}</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Usos</dt>
									<dd>
										{promo.redemptions_count}
										{promo.max_redemptions != null ? ` / ${promo.max_redemptions}` : " / ilimitado"}
									</dd>
								</div>
								<div>
									<dt className="text-zinc-500">Máx. por usuario</dt>
									<dd>{promo.max_uses_per_user}</dd>
								</div>
							</dl>
						</CouponSectionCard>
					)}

					{assignment && (
						<CouponSectionCard title="Asignación solicitada" className="lg:col-span-2">
							<div className="space-y-4">
								{assignment.requested_by && (
									<p className="text-sm">
										<span className="text-zinc-500">Solicitante:</span>{" "}
										<strong>{assignment.requested_by.name}</strong> ({assignment.requested_by.email})
									</p>
								)}
								{assignment.pre_approval_only && (
									<Badge color="sky">Pre-aprobación de crédito/campaña</Badge>
								)}
								{assignment.beneficiary_count > 0 && (
									<div>
										<Subheading level={4}>Beneficiarios ({assignment.beneficiary_count})</Subheading>
										<ul className="mt-2 list-inside list-disc text-sm text-zinc-700 dark:text-zinc-300">
											{assignment.beneficiary_emails.map((email) => (
												<li key={email}>{email}</li>
											))}
										</ul>
									</div>
								)}
							</div>
						</CouponSectionCard>
					)}
				</div>

				{assignment?.participants?.length > 0 && (
					<CouponSectionCard title="Historial de decisiones">
						<div className="space-y-2">
							{assignment.participants.map((participant) => (
								<ParticipantRow key={participant.administrator_id} participant={participant} />
							))}
						</div>
					</CouponSectionCard>
				)}

				<CouponAuthorizationOtpModal
					isOpen={approveOpen}
					couponId={coupon.id}
					approvalRequestId={approvalRequestId}
					onSuccess={handleOtpSuccess}
					onClose={() => setApproveOpen(false)}
				/>

				<CouponAuthorizationRejectModal
					isOpen={rejectOpen}
					couponId={coupon.id}
					approvalRequestId={approvalRequestId}
					onClose={() => setRejectOpen(false)}
				/>
			</div>
		</AdminLayout>
	);
}
