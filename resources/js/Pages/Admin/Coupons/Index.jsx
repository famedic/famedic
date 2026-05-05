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
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
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

export default function CouponsIndex({ coupons, filters }) {

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
		<AdminLayout title="Cupones saldo">
			<div className="flex flex-wrap items-center justify-between gap-4">
				<Heading>Cupones saldo</Heading>
				<div className="flex flex-wrap gap-2">
					<Button href={route("admin.coupons.settings")} outline>
						Reglas y seguridad
					</Button>
					<Button href={exportUrl} outline>
						Exportar CSV
					</Button>
					<Button href={route("admin.coupons.assign")} outline>
						Asignar beneficiario
					</Button>
					<Button href={route("admin.coupons.import")} outline>
						Importar Excel
					</Button>
					<Button href={route("admin.coupons.create")} color="emerald">
						Nuevo cupón maestro
					</Button>
				</div>
			</div>
			<Text className="mt-2 text-zinc-600">
				Los cupones maestros definen monto por persona y cupo de beneficiarios. Cada
				asignación crea un cupón hijo con saldo propio. Si activas autorización por
				correo en Reglas, el cupón queda pendiente hasta que el autorizador ingrese el
				código recibido.
			</Text>

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
					<select
						className="mt-1 block w-full rounded-lg border border-zinc-950/10 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-zinc-900"
						value={data.usage}
						onChange={(e) => setData("usage", e.target.value)}
					>
						<option value="all">Todos</option>
						<option value="pending">Pendiente autorización</option>
						<option value="unassigned">Sin asignar</option>
						<option value="unused">Con saldo sin usar</option>
						<option value="used">Ya usado</option>
					</select>
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
				<Button type="submit" disabled={processing} color="emerald">
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
											<div>{c.code || "—"}</div>
											{c.description && (
												<div className="mt-0.5 line-clamp-2 text-xs text-zinc-500">
													{c.description}
												</div>
											)}
										</TableCell>
										<TableCell className="whitespace-nowrap text-zinc-600">
											{formatShortDateTime(c.created_at)}
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
