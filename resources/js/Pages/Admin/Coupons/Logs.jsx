import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import CouponAuditLogItem from "@/Components/Admin/Coupon/CouponAuditLogItem";
import CouponEmptyState from "@/Components/Admin/Coupon/CouponEmptyState";
import { useForm } from "@inertiajs/react";
import { ClipboardDocumentListIcon } from "@heroicons/react/24/outline";

export default function CouponLogs({ logs, filters }) {
	const { data, setData, get, processing } = useForm({
		type: filters?.type ?? "",
		user_id: filters?.user_id ?? "",
		date_from: filters?.date_from ?? "",
		date_to: filters?.date_to ?? "",
	});

	const submit = (e) => {
		e.preventDefault();
		get(route("admin.coupons.logs"));
	};

	return (
		<AdminLayout title="Historial de créditos">
			<div className="space-y-6">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Historial de créditos</Heading>
						<p className="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
							Auditoría de asignaciones, invitaciones, vinculaciones, reversos y cambios de
							configuración del módulo.
						</p>
					</div>
					<Button href={route("admin.coupons.index")} outline>
						Volver al listado
					</Button>
				</div>

				<CouponSectionCard title="Filtros" bodyClassName="!py-4">
					<form onSubmit={submit} className="flex flex-wrap items-end gap-3">
						<Field className="min-w-[10rem]">
							<Label>Tipo</Label>
							<Select
								value={data.type}
								onChange={(e) => setData("type", e.target.value)}
							>
								<option value="">Todos</option>
								<option value="assignment">Asignación</option>
								<option value="application">Uso / reverso</option>
								<option value="configuration">Configuración</option>
							</Select>
						</Field>
						<Field className="min-w-[8rem]">
							<Label>ID usuario</Label>
							<Input
								type="number"
								min="1"
								value={data.user_id}
								onChange={(e) => setData("user_id", e.target.value)}
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
				</CouponSectionCard>

				{logs.data.length === 0 ? (
					<CouponEmptyState
						icon={ClipboardDocumentListIcon}
						title="Sin registros"
						description="No hay eventos de auditoría con los filtros actuales."
					/>
				) : (
					<PaginatedTable paginatedData={logs}>
						<div className="space-y-3">
							{logs.data.map((row) => (
								<CouponAuditLogItem key={row.id} row={row} />
							))}
						</div>
					</PaginatedTable>
				)}
			</div>
		</AdminLayout>
	);
}
