import { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import CouponMetricCard from "@/Components/Admin/Coupon/CouponMetricCard";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import CouponStatusBadge from "@/Components/Admin/Coupon/CouponStatusBadge";
import CouponActionMenu from "@/Components/Admin/Coupon/CouponActionMenu";
import CouponEmptyState from "@/Components/Admin/Coupon/CouponEmptyState";
import {
	formatMxnFromCents,
	formatShortDateTime,
} from "@/lib/couponFormat";
import { UsersIcon } from "@heroicons/react/24/outline";
import { PlusIcon } from "@heroicons/react/16/solid";

function statusBadge(status) {
	if (status === "registered") {
		return { label: "Registrado", color: "emerald" };
	}
	return { label: "Pendiente de registro", color: "amber" };
}

export default function CouponBeneficiaries({ beneficiaries, summary, filters }) {
	const [showFilters, setShowFilters] = useState(true);
	const [resendingId, setResendingId] = useState(null);

	const { data, setData, get, processing } = useForm({
		search: filters?.search ?? "",
		status: filters?.status ?? "all",
		balance: filters?.balance ?? "all",
		has_pending: filters?.has_pending ?? false,
		assigned_from: filters?.assigned_from ?? "",
		assigned_to: filters?.assigned_to ?? "",
		used_from: filters?.used_from ?? "",
		used_to: filters?.used_to ?? "",
	});

	const applyFilters = (e) => {
		e.preventDefault();
		get(route("admin.coupons.beneficiaries.index"), {
			preserveState: true,
			replace: true,
		});
	};

	const resendInvitation = (row) => {
		const info = row.resend_invitation;
		if (!info?.can_resend || resendingId) return;
		setResendingId(info.beneficiary_id);
		router.post(
			route("admin.coupons.beneficiaries.resend-invitation", {
				coupon: info.parent_coupon_id,
				beneficiary: info.beneficiary_id,
			}),
			{},
			{
				preserveScroll: true,
				onFinish: () => setResendingId(null),
			},
		);
	};

	return (
		<AdminLayout title="Beneficiarios">
			<div className="space-y-6">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div className="max-w-2xl">
						<Heading>Beneficiarios</Heading>
						<p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
							Consulta personas con créditos asignados o pendientes de registro.
						</p>
					</div>
					<div className="flex flex-wrap items-center justify-end gap-2">
						<Button
							outline
							onClick={() =>
								window.location.assign(
									route("admin.coupons.beneficiaries.export", filters),
								)
							}
						>
							Exportar CSV
						</Button>
						<Button href={route("admin.coupons.index")} outline>
							Ver créditos
						</Button>
						<Button href={route("admin.coupons.assign", { focus: "new" })}>
							<PlusIcon />
							Crear crédito
						</Button>
					</div>
				</div>

				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
					<CouponMetricCard
						label="Total beneficiarios"
						value={summary?.total_beneficiaries ?? 0}
					/>
					<CouponMetricCard
						label="Registrados"
						value={summary?.registered_count ?? 0}
						tone="lime"
					/>
					<CouponMetricCard
						label="Pend. registro"
						value={summary?.pending_count ?? 0}
						tone="amber"
					/>
					<CouponMetricCard
						label="Saldo disponible"
						value={formatMxnFromCents(summary?.total_available_balance_cents)}
						tone="sky"
					/>
					<CouponMetricCard
						label="Saldo utilizado"
						value={formatMxnFromCents(summary?.total_used_balance_cents)}
						tone="zinc"
					/>
					<CouponMetricCard
						label="Saldo restaurado"
						value={formatMxnFromCents(summary?.total_reversed_balance_cents)}
						tone="red"
					/>
				</div>

				<CouponSectionCard
					title="Filtros"
					actions={
						<Button type="button" plain onClick={() => setShowFilters((v) => !v)}>
							{showFilters ? "Ocultar" : "Mostrar"}
						</Button>
					}
					bodyClassName={showFilters ? "!py-4" : "!py-0 !px-0"}
				>
					{showFilters && (
						<form onSubmit={applyFilters} className="space-y-4">
							<div className="flex flex-wrap items-end gap-3">
								<Field className="min-w-[14rem] flex-1">
									<Label>Buscar</Label>
									<Input
										value={data.search}
										onChange={(e) => setData("search", e.target.value)}
										placeholder="Nombre o correo"
									/>
								</Field>
								<Field className="min-w-[10rem]">
									<Label>Estado</Label>
									<Select
										value={data.status}
										onChange={(e) => setData("status", e.target.value)}
									>
										<option value="all">Todos</option>
										<option value="registered">Registrados</option>
										<option value="pending">Pendientes de registro</option>
									</Select>
								</Field>
								<Field className="min-w-[12rem]">
									<Label>Saldo</Label>
									<Select
										value={data.balance}
										onChange={(e) => setData("balance", e.target.value)}
									>
										<option value="all">Todos</option>
										<option value="has_available">Con saldo disponible</option>
										<option value="no_available">Sin saldo disponible</option>
										<option value="has_used">Con saldo utilizado</option>
									</Select>
								</Field>
								<CheckboxField>
									<Checkbox
										checked={data.has_pending}
										onChange={(v) => setData("has_pending", v)}
									/>
									<Label>Con pendientes de registro</Label>
								</CheckboxField>
							</div>
							<div className="flex flex-wrap items-end gap-3">
								<Field>
									<Label>Asignación desde</Label>
									<Input
										type="date"
										value={data.assigned_from}
										onChange={(e) => setData("assigned_from", e.target.value)}
									/>
								</Field>
								<Field>
									<Label>Asignación hasta</Label>
									<Input
										type="date"
										value={data.assigned_to}
										onChange={(e) => setData("assigned_to", e.target.value)}
									/>
								</Field>
								<Field>
									<Label>Último uso desde</Label>
									<Input
										type="date"
										value={data.used_from}
										onChange={(e) => setData("used_from", e.target.value)}
									/>
								</Field>
								<Field>
									<Label>Último uso hasta</Label>
									<Input
										type="date"
										value={data.used_to}
										onChange={(e) => setData("used_to", e.target.value)}
									/>
								</Field>
								<Button type="submit" disabled={processing}>
									Filtrar
								</Button>
							</div>
						</form>
					)}
				</CouponSectionCard>

				{beneficiaries.data.length === 0 ? (
					<CouponEmptyState
						icon={UsersIcon}
						title="Sin beneficiarios"
						description="No hay personas con créditos asignados o pendientes con los filtros actuales."
					/>
				) : (
					<PaginatedTable paginatedData={beneficiaries}>
						<div className="overflow-x-auto">
							<Table>
								<TableHead>
									<TableRow>
										<TableHeader>Beneficiario</TableHeader>
										<TableHeader className="whitespace-nowrap">Créditos</TableHeader>
										<TableHeader className="whitespace-nowrap">Pendientes</TableHeader>
										<TableHeader className="whitespace-nowrap">Disponible</TableHeader>
										<TableHeader className="whitespace-nowrap">Utilizado</TableHeader>
										<TableHeader className="whitespace-nowrap">Restaurado</TableHeader>
										<TableHeader className="whitespace-nowrap">Fechas</TableHeader>
										<TableHeader />
									</TableRow>
								</TableHead>
								<TableBody>
									{beneficiaries.data.map((row) => {
										const badge = statusBadge(row.status);
										return (
											<TableRow key={row.email_key}>
												<TableCell className="align-top">
													<div className="flex flex-col gap-1.5">
														<CouponStatusBadge
															label={badge.label}
															color={badge.color}
														/>
														<p className="font-medium text-zinc-900 dark:text-zinc-100">
															{row.full_name || "—"}
														</p>
														<p className="break-all text-sm text-zinc-600 dark:text-zinc-400">
															{row.email}
														</p>
													</div>
												</TableCell>
												<TableCell className="align-top text-sm text-zinc-800 dark:text-zinc-200">
													{row.assigned_coupons_count}
												</TableCell>
												<TableCell className="align-top text-sm text-zinc-800 dark:text-zinc-200">
													{row.pending_beneficiaries_count > 0
														? row.pending_beneficiaries_count
														: "—"}
												</TableCell>
												<TableCell className="align-top text-sm font-medium text-zinc-900 dark:text-zinc-100">
													{formatMxnFromCents(row.available_balance_cents)}
												</TableCell>
												<TableCell className="align-top text-sm text-zinc-800 dark:text-zinc-200">
													{formatMxnFromCents(row.used_balance_cents)}
												</TableCell>
												<TableCell className="align-top text-sm text-zinc-800 dark:text-zinc-200">
													{row.reversed_balance_cents > 0
														? formatMxnFromCents(row.reversed_balance_cents)
														: "—"}
												</TableCell>
												<TableCell className="align-top text-xs text-zinc-600 dark:text-zinc-400">
													<div>
														<span className="font-medium text-zinc-700 dark:text-zinc-300">
															Asignación:
														</span>{" "}
														{formatShortDateTime(row.last_assigned_at)}
													</div>
													<div className="mt-1">
														<span className="font-medium text-zinc-700 dark:text-zinc-300">
															Último uso:
														</span>{" "}
														{formatShortDateTime(row.last_used_at)}
													</div>
													{row.last_invitation_sent_at && (
														<div className="mt-1">
															<span className="font-medium text-zinc-700 dark:text-zinc-300">
																Invitación:
															</span>{" "}
															{formatShortDateTime(row.last_invitation_sent_at)}
															{row.invitation_count > 1
																? ` (${row.invitation_count})`
																: ""}
														</div>
													)}
												</TableCell>
												<TableCell className="text-right align-top">
													<CouponActionMenu
														items={[
															{
																key: "coupons",
																label: "Ver créditos relacionados",
																href: row.coupons_index_url,
															},
															row.customer_admin_url
																? {
																		key: "customer",
																		label: "Ver cliente",
																		href: row.customer_admin_url,
																	}
																: null,
															row.resend_invitation?.can_resend
																? {
																		key: "resend",
																		label:
																			resendingId ===
																			row.resend_invitation.beneficiary_id
																				? "Reenviando…"
																				: "Reenviar invitación",
																		disabled:
																			resendingId ===
																			row.resend_invitation.beneficiary_id,
																		onClick: () => resendInvitation(row),
																	}
																: null,
															{
																key: "logs",
																label: "Ver historial",
																href: route("admin.coupons.logs"),
															},
														]}
													/>
												</TableCell>
											</TableRow>
										);
									})}
								</TableBody>
							</Table>
						</div>
					</PaginatedTable>
				)}
			</div>
		</AdminLayout>
	);
}
