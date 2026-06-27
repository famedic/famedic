import { useMemo } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import UpdateButton from "@/Components/Admin/UpdateButton";
import DateFilter from "@/Components/Filters/DateFilter";
import LaboratoryPurchasesChartsDashboard from "@/Components/Admin/LaboratoryPurchasesChartsDashboard";
import { buildLaboratoryPurchaseQueryParams } from "@/Pages/Admin/laboratoryPurchaseQueryParams";

export default function LaboratoryPurchasesChart({ charts, filters }) {
	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		deleted: filters.deleted || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		invoice_requested: filters.invoice_requested || "",
		results_uploaded: filters.results_uploaded || "",
		invoice_uploaded: filters.invoice_uploaded || "",
		payment_method: filters.payment_method || "",
		payment_status: filters.payment_status || "",
		brand: filters.brand || "",
		dev_assistance: filters.dev_assistance || "",
	});

	const showUpdateButton = useMemo(
		() =>
			Object.keys(buildLaboratoryPurchaseQueryParams(data)).some(
				(key) =>
					String(data[key] ?? "") !==
					String(filters[key] ?? ""),
			),
		[data, filters],
	);

	const update = (e) => {
		e.preventDefault();
		if (!processing) {
			get(route("admin.laboratory-purchases.chart"), {
				preserveState: true,
			});
		}
	};

	const listHref = route(
		"admin.laboratory-purchases.index",
		buildLaboratoryPurchaseQueryParams(data),
	);

	return (
		<AdminLayout title="Gráficas — Pedidos de laboratorio">
			<div className="space-y-8">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div className="space-y-1">
						<Heading>Gráficas de pedidos de laboratorio</Heading>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Análisis del periodo según los filtros activos. Por defecto,
							últimos 30 días. Los mismos filtros del listado aplican aquí.
						</Text>
					</div>
					<Button outline href={listHref}>
						Ver listado de pedidos
					</Button>
				</div>

				<form onSubmit={update} className="space-y-6">
					<div className="flex flex-wrap gap-4 items-end">
						<DateFilter
							label="Desde"
							value={data.start_date}
							onChange={(value) => setData("start_date", value)}
							error={errors.start_date}
						/>
						<DateFilter
							label="Hasta"
							value={data.end_date}
							onChange={(value) => setData("end_date", value)}
							error={errors.end_date}
						/>
					</div>

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton type="submit" processing={processing} />
						</div>
					)}
				</form>

				<LaboratoryPurchasesChartsDashboard charts={charts} />
			</div>
		</AdminLayout>
	);
}
