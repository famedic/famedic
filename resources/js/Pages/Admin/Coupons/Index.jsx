import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { Badge } from "@/Components/Catalyst/badge";
import { PlusIcon } from "@heroicons/react/16/solid";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
function formatShortDateTime(iso) {
	if (!iso) return "—";
	return new Date(iso).toLocaleString("es-MX", {
		dateStyle: "short",
		timeStyle: "short",
	});
}

function couponUsageSummary(c) {
	if (c.approval_status === "pending_authorization") {
		return { label: "Pendiente autorización", color: "purple" };
	}
	const direct = c.coupon_users ?? c.couponUsers ?? [];
	const childCount = c.child_coupons_count ?? 0;
	if (direct.length === 0 && childCount === 0) {
		return { label: "Sin asignar", color: "zinc" };
	}
	if (childCount > 0) {
		return { label: `Campaña (${childCount})`, color: "cyan" };
	}
	const pending = direct.filter((a) => !a.used_at);
	const used = direct.filter((a) => a.used_at);
	if (pending.length > 0 && used.length === 0) {
		return { label: "Pendiente de usar", color: "amber" };
	}
	if (used.length > 0 && pending.length === 0) {
		return { label: "Usado", color: "blue" };
	}
	return { label: "Mixto", color: "orange" };
}

function creatorDisplayName(user) {
	if (!user) return "Sistema";
	if (user.full_name) return user.full_name;
	const parts = [user.name, user.paternal_lastname, user.maternal_lastname].filter(
		Boolean,
	);
	return parts.join(" ").trim() || user.email || "Sistema";
}

export default function CouponsIndex({
	coupons,
	filters,
	authorizerContext = {},
	approvalsOverview = { pending_assignment_requests: 0 },
}) {
	const pendingCouponIds = new Set(authorizerContext.pending_my_action_coupon_ids ?? []);
	const pendingMultisigTotal = approvalsOverview.pending_assignment_requests ?? 0;

	const { data, setData, get, processing } = useForm({
		search: filters?.search ?? "",
		usage: filters?.usage ?? "all",
		user_email: filters?.user_email ?? "",
		date_from: filters?.date_from ?? "",
		date_to: filters?.date_to ?? "",
	});

	const exportUrl = useMemo(() => {
		const q = new URLSearchParams();
		Object.entries(data).forEach(([k, v]) => {
			if (v !== "" && v !== null && v !== undefined) {
				q.set(k, String(v));
			}
		});
		const qs = q.toString();
		return qs
			? `${route("admin.coupons.export")}?${qs}`
			: route("admin.coupons.export");
	}, [data]);

	const applyFilters = (e) => {
		e.preventDefault();
		get(route("admin.coupons.index"), { preserveState: true, replace: true });
	};

	const [revokeTarget, setRevokeTarget] = useState(null);
	const { delete: destroy, processing: revoking } = useForm({});

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

	return (
		<AdminLayout title="Créditos a favor">
			<div className="space-y-8">
			<div className="flex flex-wrap items-end justify-between gap-8">
				<Heading>Créditos a favor</Heading>
				<div className="flex flex-wrap items-center justify-end gap-2">
					<Button href={route("admin.coupons.settings")} outline>
						Reglas y seguridad
					</Button>
					<Button href={exportUrl} outline>
						Exportar CSV
					</Button>
					<Button href={route("admin.coupons.assign")}>
						<PlusIcon />
						Crear y asignar cupones
					</Button>
				</div>
			</div>
			<Text className="text-zinc-600 dark:text-zinc-400">
				Desde &quot;Crear y asignar cupones&quot; defines el cupón maestro, asignas por
				correo o por archivo, y ves las reglas vigentes. Cada asignación crea un cupón hijo
				con saldo propio. Si activas autorización por correo en Reglas, el cupón queda
				pendiente hasta que el autorizador ingrese el código recibido.
			</Text>

			{pendingMultisigTotal > 0 && (
				<div
					className="mt-6 rounded-xl border border-sky-200/90 bg-sky-50 px-4 py-3 text-sm text-sky-950 shadow-sm dark:border-sky-500/35 dark:bg-sky-950/35 dark:text-sky-50"
					role="status"
				>
					<p className="font-medium">Aprobaciones multi-firma (asignaciones)</p>
					<p className="mt-1 text-sky-900/90 dark:text-sky-100/85">
						Hay{" "}
						<strong>{pendingMultisigTotal}</strong> solicitud(es) pendientes en el sistema
						que requieren firmas de autorizadores. En la columna{" "}
						<strong>Multi-firma</strong> ves el avance (firmas registradas / requeridas) por
						cupón; el detalle y la lista de firmantes están en la ficha del cupón.
					</p>
					<div className="mt-2">
						<Button href={route("admin.coupons.logs")} plain className="text-sm">
							Registro de actividad
						</Button>
					</div>
				</div>
			)}

			{authorizerContext.is_authorizer &&
				(authorizerContext.pending_assignment_approvals_count > 0 ||
					authorizerContext.pending_settings_approvals_count > 0) && (
					<div
						className="mt-6 flex flex-col gap-3 rounded-xl border border-amber-300/80 bg-amber-50 px-4 py-4 text-sm text-amber-950 shadow-sm dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-50"
						role="status"
					>
						<div className="flex flex-wrap items-center gap-2">
							<Badge color="amber">Autorizador</Badge>
							<span className="font-medium">Tienes solicitudes pendientes de tu aprobación</span>
						</div>
						<ul className="list-inside list-disc space-y-1 text-amber-900/90 dark:text-amber-100/90">
							{authorizerContext.pending_assignment_approvals_count > 0 && (
								<li>
									<strong>{authorizerContext.pending_assignment_approvals_count}</strong>{" "}
									solicitud(es) de asignación o pre-aprobación de cupones. En la tabla,
									busca la etiqueta &quot;Tu aprobación&quot; y abre el cupón para firmar.
								</li>
							)}
							{authorizerContext.pending_settings_approvals_count > 0 && (
								<li>
									<strong>{authorizerContext.pending_settings_approvals_count}</strong>{" "}
									solicitud(es) de cambio en Reglas de cupones (revísalas en el registro de
									actividad).
								</li>
							)}
						</ul>
						<div className="flex flex-wrap gap-2">
							<Button href={route("admin.coupons.logs")} outline>
								Ver registro y detalle
							</Button>
						</div>
					</div>
				)}

			<form
				onSubmit={applyFilters}
				className="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-900/40"
			>
				<Field className="min-w-[10rem]">
					<Label>Buscar</Label>
					<Input
						placeholder="Código, descripción, correo…"
						value={data.search}
						onChange={(e) => setData("search", e.target.value)}
					/>
				</Field>
				<Field className="min-w-[12rem]">
					<Label>Uso</Label>
					<Select
						className="mt-1"
						value={data.usage}
						onChange={(e) => setData("usage", e.target.value)}
					>
						<option value="all">Todos</option>
						<option value="pending">Pendiente autorización</option>
						<option value="unassigned">Sin asignar</option>
						<option value="unused">Con saldo sin usar</option>
						<option value="used">Ya usado</option>
					</Select>
				</Field>
				<Field className="min-w-[11rem]">
					<Label>Correo beneficiario</Label>
					<Input
						type="email"
						placeholder="usuario@…"
						value={data.user_email}
						onChange={(e) => setData("user_email", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Desde</Label>
					<Input
						type="date"
						value={data.date_from}
						onChange={(e) => setData("date_from", e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Hasta</Label>
					<Input
						type="date"
						value={data.date_to}
						onChange={(e) => setData("date_to", e.target.value)}
					/>
				</Field>
				<Button type="submit" disabled={processing}>
					Filtrar
				</Button>
			</form>

			<div className="mt-6">
				<PaginatedTable paginatedData={coupons}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>ID</TableHeader>
								<TableHeader>Código</TableHeader>
								<TableHeader>Creado</TableHeader>
								<TableHeader>Monto / persona</TableHeader>
								<TableHeader>Benef.</TableHeader>
								<TableHeader>Uso</TableHeader>
								<TableHeader>Asignado a</TableHeader>
								<TableHeader>Fechas</TableHeader>
								<TableHeader>Multi-firma</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{coupons.data.map((c) => {
								const usage = couponUsageSummary(c);
								const assignments = c.coupon_users ?? c.couponUsers ?? [];
								const childCount = c.child_coupons_count ?? 0;
								const maxB = c.max_beneficiaries;
								return (
									<TableRow key={c.id}>
										<TableCell className="whitespace-nowrap">{c.id}</TableCell>
										<TableCell>
											<Button
												href={route("admin.coupons.show", c.id)}
												plain
												className="font-medium text-zinc-900 hover:text-famedic-darker dark:text-zinc-100 dark:hover:text-famedic-lime"
											>
												{c.code || "—"}
											</Button>
											{c.description && (
												<div className="mt-0.5 line-clamp-2 text-xs text-zinc-500">
													{c.description}
												</div>
											)}
										</TableCell>
										<TableCell className="whitespace-nowrap text-zinc-600">
											<div>{formatShortDateTime(c.created_at)}</div>
											<div className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
												{creatorDisplayName(c.created_by_user ?? c.createdByUser)}
											</div>
										</TableCell>
										<TableCell className="whitespace-nowrap">
											{(c.amount_cents / 100).toLocaleString("es-MX", {
												style: "currency",
												currency: "MXN",
											})}
										</TableCell>
										<TableCell className="whitespace-nowrap text-sm">
											{childCount > 0 ? (
												<span>
													{childCount}
													{maxB != null ? ` / ${maxB}` : ""}
												</span>
											) : (
												<span>
													{assignments.length}
													{maxB != null ? ` / ${maxB}` : ""}
												</span>
											)}
										</TableCell>
										<TableCell>
											<Badge color={usage.color}>{usage.label}</Badge>
										</TableCell>
										<TableCell className="max-w-[14rem]">
											{childCount > 0 ? (
												<Button
													href={route("admin.coupons.show", c.id)}
													plain
													className="text-left"
												>
													Ver {childCount} beneficiario(s)
												</Button>
											) : assignments.length === 0 ? (
												<span className="text-zinc-500">—</span>
											) : (
												<ul className="flex flex-col gap-2 text-sm">
													{assignments.map((a) => (
														<li key={a.id}>
															<div className="font-medium text-zinc-900 dark:text-zinc-100">
																{a.user?.full_name?.trim() ||
																	"Usuario"}
															</div>
															<div className="break-all text-xs text-zinc-600">
																{a.user?.email}
															</div>
															{a.used_at ? (
																<Badge color="blue" className="mt-1">
																	Usado
																</Badge>
															) : (
																<Badge color="amber" className="mt-1">
																	Sin usar
																</Badge>
															)}
														</li>
													))}
												</ul>
											)}
										</TableCell>
										<TableCell className="max-w-[12rem] text-xs text-zinc-600">
											{childCount > 0 ? (
												"—"
											) : assignments.length === 0 ? (
												"—"
											) : (
												<ul className="flex flex-col gap-1">
													{assignments.map((a) => (
														<li key={`d-${a.id}`}>
															<div>
																Asignado:{" "}
																{formatShortDateTime(a.assigned_at)}
															</div>
															{a.used_at && (
																<div>
																	Usado:{" "}
																	{formatShortDateTime(a.used_at)}
																</div>
															)}
														</li>
													))}
												</ul>
											)}
										</TableCell>
										<TableCell className="max-w-[11rem] align-top">
											{c.assignment_approval_summary ? (
												<div className="flex flex-col gap-1">
													<Badge color="amber">
														{c.assignment_approval_summary.current}/
														{c.assignment_approval_summary.required} firmas
													</Badge>
													{c.assignment_approval_summary.remaining > 0 ? (
														<span className="text-xs text-zinc-600 dark:text-zinc-400">
															Faltan {c.assignment_approval_summary.remaining}
														</span>
													) : (
														<span className="text-xs text-zinc-600 dark:text-zinc-400">
															Sin faltantes (cierre de solicitud)
														</span>
													)}
													{c.assignment_approval_summary.pre_approval_only && (
														<span className="text-xs text-zinc-500 dark:text-zinc-400">
															Pre-aprobación
														</span>
													)}
													<Button
														href={route("admin.coupons.show", c.id)}
														plain
														className="self-start text-xs"
													>
														Detalle
													</Button>
												</div>
											) : (
												<span className="text-xs text-zinc-500 dark:text-zinc-400">
													Sin solicitud pendiente
												</span>
											)}
										</TableCell>
										<TableCell>
											<div className="flex flex-col gap-1">
												{c.is_active ? (
													<Badge color="emerald">Activo</Badge>
												) : (
													<Badge color="zinc">Inactivo</Badge>
												)}
												{c.approval_status === "pending_authorization" && (
													<Badge color="purple">Sin autorizar</Badge>
												)}
												{authorizerContext.is_authorizer && pendingCouponIds.has(c.id) && (
													<Badge color="amber">Tu aprobación</Badge>
												)}
											</div>
										</TableCell>
										<TableCell className="text-right">
											<div className="flex flex-col items-end gap-2">
												<Button
													href={route("admin.coupons.show", c.id)}
													plain
												>
													Ver
												</Button>
												<Button
													href={route("admin.coupons.edit", c.id)}
													plain
												>
													Editar
												</Button>
												{assignments.map((a) =>
													!a.used_at ? (
														<Button
															key={`r-${a.id}`}
															plain
															className="text-red-600 hover:text-red-700 dark:text-red-400"
															onClick={() =>
																setRevokeTarget({
																	couponId: c.id,
																	assignmentId: a.id,
																	email: a.user?.email,
																})
															}
														>
															Quitar asignación
														</Button>
													) : null,
												)}
											</div>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
			</div>

			<DeleteConfirmationModal
				isOpen={!!revokeTarget}
				close={() => setRevokeTarget(null)}
				title="Quitar asignación"
				description={
					revokeTarget
						? `Se eliminará el vínculo del cupón con ${revokeTarget.email ?? "el usuario"}. El saldo dejará de mostrarse en su cuenta si aún no lo usó.`
						: ""
				}
				processing={revoking}
				destroy={confirmRevoke}
			/>
		</AdminLayout>
	);
}
