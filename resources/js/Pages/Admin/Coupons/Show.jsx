import { useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Badge } from "@/Components/Catalyst/badge";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";

function formatShortDateTime(iso) {
	if (!iso) return "—";
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "short",
		timeStyle: "short",
	});
}

export default function CouponsShow({
	coupon,
	beneficiaryRows,
	authorizationRecipientEmail,
	mailSetupHint,
}) {
	const {
		data: authData,
		setData: setAuthData,
		post: postAuth,
		errors: authErrors,
		processing: authProcessing,
	} = useForm({ code: "" });
	const { post: postResend, processing: resendProcessing } = useForm({});
	const [revokeTarget, setRevokeTarget] = useState(null);
	const { delete: destroy, processing: revoking } = useForm({});

	const submitAuth = (e) => {
		e.preventDefault();
		postAuth(route("admin.coupons.authorize", coupon.id));
	};

	const resendCode = () => {
		postResend(route("admin.coupons.resend-authorization", coupon.id));
	};

	const confirmRevoke = () => {
		if (!revokeTarget || revoking) return;
		destroy(
			route("admin.coupons.assignments.destroy", {
				coupon: revokeTarget.couponId,
				couponUser: revokeTarget.assignmentId,
			}),
			{ preserveScroll: true },
		);
	};

	const pending = coupon.approval_status === "pending_authorization";

	return (
		<AdminLayout title={`Cupón #${coupon.id}`}>
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div>
					<Heading>Cupón #{coupon.id}</Heading>
					<p className="mt-1 text-base/6 text-zinc-700 dark:text-zinc-300 sm:text-sm/6">
						{coupon.description || "Sin descripción"}
					</p>
				</div>
				<div className="flex flex-wrap gap-2">
					<Button href={route("admin.coupons.index")} plain>
						Volver al listado
					</Button>
					<Button href={route("admin.coupons.assign")} outline>
						Asignar beneficiario
					</Button>
					<Button href={route("admin.coupons.edit", coupon.id)} outline>
						Editar
					</Button>
				</div>
			</div>

			<dl className="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Código</dt>
					<dd className="mt-0.5 font-medium text-zinc-900 dark:text-zinc-100">
						{coupon.code || "—"}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Monto por beneficiario</dt>
					<dd className="mt-0.5 font-medium text-zinc-900 dark:text-zinc-100">
						{(coupon.amount_cents / 100).toLocaleString("es-MX", {
							style: "currency",
							currency: "MXN",
						})}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Máx. beneficiarios</dt>
					<dd className="mt-0.5 font-medium text-zinc-900 dark:text-zinc-100">
						{coupon.max_beneficiaries != null ? coupon.max_beneficiaries : "Sin límite"}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Creado</dt>
					<dd className="mt-0.5 text-zinc-800 dark:text-zinc-200">
						{formatShortDateTime(coupon.created_at)}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Creado por</dt>
					<dd className="mt-0.5 text-zinc-800 dark:text-zinc-200">
						{coupon.created_by_user
							? `${coupon.created_by_user.full_name} · ${coupon.created_by_user.email}`
							: "—"}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Última edición por</dt>
					<dd className="mt-0.5 text-zinc-800 dark:text-zinc-200">
						{coupon.updated_by_user
							? `${coupon.updated_by_user.full_name} · ${coupon.updated_by_user.email}`
							: "—"}
					</dd>
				</div>
				<div>
					<dt className="text-zinc-500 dark:text-zinc-400">Autorizado por</dt>
					<dd className="mt-0.5 text-zinc-800 dark:text-zinc-200">
						{coupon.authorized_by_user
							? `${coupon.authorized_by_user.full_name} · ${coupon.authorized_by_user.email}`
							: pending
								? "Pendiente"
								: "—"}
					</dd>
				</div>
			</dl>

			<div className="mt-4 flex flex-wrap gap-2">
				{pending ? (
					<Badge color="purple">Pendiente de autorización</Badge>
				) : (
					<Badge color="emerald">Autorizado</Badge>
				)}
				{coupon.is_active ? (
					<Badge color="emerald">Activo</Badge>
				) : (
					<Badge color="zinc">Inactivo</Badge>
				)}
			</div>

			{pending && mailSetupHint && (
				<div
					className="mt-6 rounded-lg border border-amber-300/80 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-950/50 dark:text-amber-100"
					role="status"
				>
					<p className="font-medium">Entorno de correo</p>
					<p className="mt-1 leading-relaxed">{mailSetupHint}</p>
				</div>
			)}

			{pending && (
				<form
					onSubmit={submitAuth}
					className="mt-6 max-w-lg space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/90 p-5 shadow-sm dark:border-zinc-600 dark:bg-zinc-800/60"
				>
					<div className="flex flex-wrap items-start justify-between gap-3">
						<Subheading className="text-famedic-darker dark:text-white">
							Autorizar cupón
						</Subheading>
						<Button
							type="button"
							outline
							disabled={resendProcessing || authProcessing}
							onClick={resendCode}
						>
							{resendProcessing ? "Enviando…" : "Reenviar código"}
						</Button>
					</div>
					{authorizationRecipientEmail && (
						<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
							Correo del autorizador:{" "}
							<span className="break-all font-normal text-zinc-700 dark:text-zinc-300">
								{authorizationRecipientEmail}
							</span>
						</p>
					)}
					<p className="text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
						Ingresa el código de 6 dígitos enviado a ese correo (definido en Reglas de
						cupones). El código{" "}
						<strong className="font-semibold text-zinc-900 dark:text-white">
							vence a los 5 minutos
						</strong>
						. Si no llegó, usa &quot;Reenviar código&quot;: se genera uno nuevo y el
						anterior deja de valer.
					</p>
					{coupon.authorization_code_expires_at && (
						<p className="text-sm text-zinc-600 dark:text-zinc-400">
							Válido hasta:{" "}
							<span className="font-medium text-zinc-800 dark:text-zinc-200">
								{formatShortDateTime(coupon.authorization_code_expires_at)}
							</span>
						</p>
					)}
					<Field>
						<Label>Código</Label>
						<Input
							value={authData.code}
							onChange={(e) => setAuthData("code", e.target.value)}
							maxLength={32}
							autoComplete="one-time-code"
							className="font-mono text-lg tracking-widest"
						/>
						{authErrors.code && (
							<p className="text-sm text-red-600 dark:text-red-400">
								{authErrors.code}
							</p>
						)}
					</Field>
					<Button type="submit" disabled={authProcessing} color="emerald">
						Confirmar autorización
					</Button>
				</form>
			)}

			<Subheading className="mt-10 text-famedic-darker dark:text-white">
				Beneficiarios y uso
			</Subheading>
			<Text className="mt-1 !text-zinc-600 dark:!text-zinc-400">
				Cada fila corresponde a un cupón hijo asignado a un usuario (o una asignación
				directa en cupones creados antes de campañas).
			</Text>

			<div className="mt-4 overflow-x-auto">
				<Table>
					<TableHead>
						<TableRow>
							<TableHeader>Usuario</TableHeader>
							<TableHeader>Correo</TableHeader>
							<TableHeader>Cliente</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader>Asignado</TableHeader>
							<TableHeader>Usado</TableHeader>
							<TableHeader>Compra</TableHeader>
							<TableHeader />
						</TableRow>
					</TableHead>
					<TableBody>
						{beneficiaryRows.length === 0 ? (
							<TableRow>
								<TableCell
									colSpan={8}
									className="text-zinc-500 dark:text-zinc-400"
								>
									Aún no hay beneficiarios.
								</TableCell>
							</TableRow>
						) : (
							beneficiaryRows.map((row, idx) => (
								<TableRow key={`${row.coupon_id}-${row.assignment_id ?? idx}`}>
									<TableCell className="text-zinc-900 dark:text-zinc-100">
										{row.user?.full_name?.trim() || "—"}
									</TableCell>
									<TableCell className="max-w-[12rem] break-all text-sm text-zinc-800 dark:text-zinc-200">
										{row.user?.email || "—"}
									</TableCell>
									<TableCell>
										{row.customer_admin_url ? (
											<Button href={row.customer_admin_url} plain>
												Ver cliente
											</Button>
										) : (
											<span className="text-zinc-500 dark:text-zinc-400">—</span>
										)}
									</TableCell>
									<TableCell>
										{row.used_at ? (
											<Badge color="blue">Usado</Badge>
										) : (
											<Badge color="amber">Pendiente</Badge>
										)}
									</TableCell>
									<TableCell className="text-xs text-zinc-600 dark:text-zinc-400">
										{formatShortDateTime(row.assigned_at)}
									</TableCell>
									<TableCell className="text-xs text-zinc-600 dark:text-zinc-400">
										{formatShortDateTime(row.used_at)}
									</TableCell>
									<TableCell>
										{row.transaction?.purchase_url ? (
											<Button href={row.transaction.purchase_url} plain>
												{row.transaction.purchase_type === "lab"
													? "Laboratorio"
													: "Farmacia"}
											</Button>
										) : (
											<span className="text-zinc-500 dark:text-zinc-400">—</span>
										)}
									</TableCell>
									<TableCell className="text-right">
										{!row.used_at && row.assignment_id ? (
											<Button
												plain
												className="text-red-600 dark:text-red-400"
												onClick={() =>
													setRevokeTarget({
														couponId: row.coupon_id,
														assignmentId: row.assignment_id,
														email: row.user?.email,
													})
												}
											>
												Quitar
											</Button>
										) : null}
									</TableCell>
								</TableRow>
							))
						)}
					</TableBody>
				</Table>
			</div>

			<DeleteConfirmationModal
				isOpen={!!revokeTarget}
				close={() => setRevokeTarget(null)}
				title="Quitar asignación"
				description={
					revokeTarget
						? `Se eliminará la asignación para ${revokeTarget.email ?? "el usuario"}.`
						: ""
				}
				processing={revoking}
				destroy={confirmRevoke}
			/>
		</AdminLayout>
	);
}
