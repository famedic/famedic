import { useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import { couponValiditySummary } from "@/lib/couponEligibilityUi";
import { formatShortDateTime } from "@/lib/couponFormat";
import { ClipboardDocumentIcon } from "@heroicons/react/16/solid";

function copyToClipboard(text) {
	if (!text) return;
	void navigator.clipboard?.writeText(text);
}

function statusBadge(promo) {
	if (!promo.is_active) {
		return <Badge color="zinc">Inactivo</Badge>;
	}
	if (promo.master_approval_status === "pending_authorization") {
		return <Badge color="amber">Pendiente autorización</Badge>;
	}
	return <Badge color="green">Activo</Badge>;
}

function redemptionStatusLabel(status) {
	switch (status) {
		case "confirmed":
			return "Confirmada";
		case "validated":
			return "Validada";
		case "released":
			return "Liberada";
		default:
			return status ?? "—";
	}
}

export default function PromoCodesShow({ promoCode }) {
	const [deactivateOpen, setDeactivateOpen] = useState(false);
	const { post, processing } = useForm({ confirm: true });

	const confirmDeactivate = () => {
		post(route("admin.coupons.promo-codes.deactivate", promoCode.id), {
			onSuccess: () => setDeactivateOpen(false),
		});
	};

	return (
		<AdminLayout title={`Código ${promoCode.code}`}>
			<div className="space-y-8">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading className="font-mono">{promoCode.code}</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							{promoCode.description || "Sin descripción"}
						</Text>
						<div className="mt-3">{statusBadge(promoCode)}</div>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button
							type="button"
							outline
							onClick={() => copyToClipboard(promoCode.code)}
						>
							<ClipboardDocumentIcon className="size-4" />
							Copiar código
						</Button>
						<Button
							type="button"
							outline
							onClick={() => copyToClipboard(promoCode.shareable_message)}
						>
							Copiar mensaje
						</Button>
						<Button href={route("admin.coupons.promo-codes.index")} outline>
							Volver
						</Button>
						{promoCode.is_active && (
							<Button color="red" onClick={() => setDeactivateOpen(true)}>
								Desactivar
							</Button>
						)}
					</div>
				</div>

				<CouponSectionCard title="Mensaje compartible">
					<Text className="text-sm leading-relaxed">{promoCode.shareable_message}</Text>
				</CouponSectionCard>

				<div className="grid gap-6 lg:grid-cols-2">
					<CouponSectionCard title="Configuración del código">
						<dl className="space-y-3 text-sm">
							<div className="flex justify-between gap-4">
								<dt className="text-zinc-500">Descuento</dt>
								<dd className="font-medium">{promoCode.formatted_amount}</dd>
							</div>
							<div className="flex justify-between gap-4">
								<dt className="text-zinc-500">Vigencia</dt>
								<dd>{couponValiditySummary(promoCode.coupon ?? promoCode)}</dd>
							</div>
							<div className="flex justify-between gap-4">
								<dt className="text-zinc-500">Usos</dt>
								<dd>
									{promoCode.redemptions_count} / {promoCode.max_redemptions ?? "∞"}
								</dd>
							</div>
							<div className="flex justify-between gap-4">
								<dt className="text-zinc-500">Restantes</dt>
								<dd>{promoCode.remaining_uses ?? "∞"}</dd>
							</div>
							<div className="flex justify-between gap-4">
								<dt className="text-zinc-500">Máx. por usuario</dt>
								<dd>{promoCode.max_uses_per_user}</dd>
							</div>
							<div className="flex justify-between gap-4">
								<dt className="text-zinc-500">Creado</dt>
								<dd>{formatShortDateTime(promoCode.created_at)}</dd>
							</div>
							{promoCode.created_by && (
								<div className="flex justify-between gap-4">
									<dt className="text-zinc-500">Creado por</dt>
									<dd>{promoCode.created_by.name}</dd>
								</div>
							)}
						</dl>
					</CouponSectionCard>

					<CouponSectionCard title="Cupón maestro">
						{promoCode.coupon ? (
							<dl className="space-y-3 text-sm">
								<div className="flex justify-between gap-4">
									<dt className="text-zinc-500">ID</dt>
									<dd>
										<Button
											href={route("admin.coupons.show", promoCode.coupon.id)}
											plain
											className="text-sm"
										>
											#{promoCode.coupon.id}
										</Button>
									</dd>
								</div>
								<div className="flex justify-between gap-4">
									<dt className="text-zinc-500">Monto</dt>
									<dd>{promoCode.formatted_amount}</dd>
								</div>
								<div className="flex justify-between gap-4">
									<dt className="text-zinc-500">Compra mínima</dt>
									<dd>{promoCode.coupon.formatted_min_purchase ?? "Sin requisito"}</dd>
								</div>
								<div className="flex justify-between gap-4">
									<dt className="text-zinc-500">Estado aprobación</dt>
									<dd>{promoCode.coupon.approval_status ?? "—"}</dd>
								</div>
							</dl>
						) : (
							<Text>Sin cupón maestro vinculado.</Text>
						)}
					</CouponSectionCard>
				</div>

				<CouponSectionCard title="Historial de redenciones">
					{(promoCode.redemptions?.length ?? 0) === 0 ? (
						<Text className="text-sm text-zinc-500">Aún no hay redenciones.</Text>
					) : (
						<Table dense>
							<TableHead>
								<TableRow>
									<TableHeader>Usuario</TableHeader>
									<TableHeader>Estado</TableHeader>
									<TableHeader>Descuento</TableHeader>
									<TableHeader>Compra</TableHeader>
									<TableHeader>Validación</TableHeader>
									<TableHeader>Confirmación</TableHeader>
									<TableHeader>Cupón hijo</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{promoCode.redemptions.map((r) => (
									<TableRow key={r.id}>
										<TableCell className="text-sm">
											{r.user?.email ?? (r.customer_id ? `Cliente #${r.customer_id}` : "—")}
										</TableCell>
										<TableCell>{redemptionStatusLabel(r.status)}</TableCell>
										<TableCell>{r.formatted_discount}</TableCell>
										<TableCell className="text-sm">
											{r.purchase_type && r.purchase_id
												? `${r.purchase_type} #${r.purchase_id}`
												: "—"}
										</TableCell>
										<TableCell className="text-sm">
											{formatShortDateTime(r.validated_at)}
										</TableCell>
										<TableCell className="text-sm">
											{formatShortDateTime(r.confirmed_at)}
										</TableCell>
										<TableCell>{r.coupon_id ? `#${r.coupon_id}` : "—"}</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					)}
				</CouponSectionCard>
			</div>

			<DeleteConfirmationModal
				isOpen={deactivateOpen}
				close={() => setDeactivateOpen(false)}
				title="Desactivar código promocional"
				description={`El código ${promoCode.code} dejará de validarse en checkout. No se eliminará del historial.`}
				processing={processing}
				destroy={confirmDeactivate}
				confirmLabel="Desactivar"
			/>
		</AdminLayout>
	);
}
