import { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Badge } from "@/Components/Catalyst/badge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { PlusIcon, ClipboardDocumentIcon } from "@heroicons/react/16/solid";
import { formatShortDateTime } from "@/lib/couponFormat";
import { couponValiditySummary } from "@/lib/couponEligibilityUi";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import AuthorizationInboxLink from "@/Components/Admin/Coupon/AuthorizationInboxLink";

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
	if (!promo.master_coupon_active) {
		return <Badge color="zinc">Cupón inactivo</Badge>;
	}
	return <Badge color="green">Activo</Badge>;
}

export default function PromoCodesIndex({ promoCodes, filters = {} }) {
	const [search, setSearch] = useState(filters.search ?? "");

	const applySearch = (e) => {
		e.preventDefault();
		router.get(route("admin.coupons.promo-codes.index"), { search }, { preserveState: true });
	};

	return (
		<AdminLayout title="Códigos promocionales">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Códigos promocionales</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Códigos compartidos para checkout de laboratorio.
						</Text>
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<AuthorizationInboxLink />
						<Button href={route("admin.coupons.promo-codes.create")} color="lime">
							<PlusIcon className="size-4" />
							Crear código
						</Button>
					</div>
				</div>

				<CouponSectionCard title="Buscar">
					<form onSubmit={applySearch} className="flex flex-wrap items-end gap-3">
						<Field className="min-w-[16rem] flex-1">
							<Label>Código o descripción</Label>
							<Input
								value={search}
								onChange={(e) => setSearch(e.target.value)}
								placeholder="EVENTO10"
							/>
						</Field>
						<Button type="submit" outline>
							Buscar
						</Button>
					</form>
				</CouponSectionCard>

				{(promoCodes?.data?.length ?? 0) === 0 ? (
					<CouponSectionCard title="Sin resultados">
						<Text>No hay códigos promocionales compartidos.</Text>
					</CouponSectionCard>
				) : (
					<PaginatedTable paginatedData={promoCodes}>
						<Table dense>
							<TableHead>
								<TableRow>
									<TableHeader>Código</TableHeader>
									<TableHeader>Descripción</TableHeader>
									<TableHeader>Descuento</TableHeader>
									<TableHeader>Vigencia</TableHeader>
									<TableHeader>Usos</TableHeader>
									<TableHeader>Restantes</TableHeader>
									<TableHeader>Por usuario</TableHeader>
									<TableHeader>Estado</TableHeader>
									<TableHeader>Creado</TableHeader>
									<TableHeader className="text-right">Acciones</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{promoCodes.data.map((promo) => (
									<TableRow key={promo.id}>
										<TableCell className="font-mono font-semibold">{promo.code}</TableCell>
										<TableCell>{promo.description || "—"}</TableCell>
										<TableCell>{promo.formatted_amount}</TableCell>
										<TableCell className="text-sm">
											{couponValiditySummary({
												valid_from: promo.valid_from,
												expires_at: promo.expires_at,
											})}
										</TableCell>
										<TableCell>
											{promo.redemptions_count} / {promo.max_redemptions ?? "∞"}
										</TableCell>
										<TableCell>
											{promo.remaining_uses != null ? promo.remaining_uses : "∞"}
										</TableCell>
										<TableCell>{promo.max_uses_per_user}</TableCell>
										<TableCell>{statusBadge(promo)}</TableCell>
										<TableCell className="text-sm">
											{formatShortDateTime(promo.created_at)}
										</TableCell>
										<TableCell className="text-right">
											<div className="flex justify-end gap-2">
												<Button
													type="button"
													plain
													title="Copiar código"
													onClick={() => copyToClipboard(promo.code)}
												>
													<ClipboardDocumentIcon className="size-4" />
												</Button>
												<Button
													href={route("admin.coupons.promo-codes.show", promo.id)}
													outline
												>
													Ver
												</Button>
											</div>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</PaginatedTable>
				)}
			</div>
		</AdminLayout>
	);
}
