import { useMemo } from "react";
import { router, useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import UpdateButton from "@/Components/Admin/UpdateButton";
import NotificationFilters from "./NotificationFilters";

export default function NotificationToolbar({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		type: filters.type || "",
		priority: filters.priority || "",
		status: filters.status || "",
		patient: filters.patient || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const dirty = useMemo(
		() =>
			(data.type || "") !== (filters.type || "") ||
			(data.priority || "") !== (filters.priority || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.patient || "") !== (filters.patient || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const activeCount = useMemo(
		() =>
			[
				filters.type,
				filters.priority,
				filters.status,
				filters.patient,
				filters.start_date,
				filters.end_date,
			].filter(Boolean).length,
		[filters],
	);

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.notifications"), {
			preserveState: true,
			replace: true,
		});
	};

	const clear = () => {
		setData({
			type: "",
			priority: "",
			status: "",
			patient: "",
			start_date: "",
			end_date: "",
		});
		router.get(
			route("admin.activecampaign.notifications"),
			{},
			{ preserveState: true, replace: true },
		);
	};

	return (
		<form onSubmit={apply} className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="text-xs text-zinc-500">
					Filtra por tipo, prioridad, estado, paciente y fecha.
				</p>
				<div className="flex flex-wrap items-center gap-2">
					<FilterCountBadge count={activeCount} />
					<Button
						type="button"
						outline
						onClick={clear}
						disabled={processing || activeCount === 0}
					>
						<ArrowPathIcon className="size-4" />
						Limpiar
					</Button>
				</div>
			</div>

			<NotificationFilters
				data={data}
				setData={setData}
				filterOptions={filterOptions}
			/>

			{dirty ? (
				<div className="flex justify-center">
					<UpdateButton type="submit" processing={processing} />
				</div>
			) : null}
		</form>
	);
}
