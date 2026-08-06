import { useMemo } from "react";
import { router, useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import UpdateButton from "@/Components/Admin/UpdateButton";
import EventFilters from "./EventFilters";

export default function EventCenterToolbar({
	filters,
	filterOptions,
	contactOptions = [],
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		type: filters.type || "",
		origin: filters.origin || "",
		status: filters.status || "",
		severity: filters.severity || "",
		patient: filters.patient || "",
		contact_id: filters.contact_id ? String(filters.contact_id) : "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const dirty = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.type || "") !== (filters.type || "") ||
			(data.origin || "") !== (filters.origin || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.severity || "") !== (filters.severity || "") ||
			(data.patient || "") !== (filters.patient || "") ||
			(data.contact_id || "") !==
				(filters.contact_id ? String(filters.contact_id) : "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const activeCount = useMemo(
		() =>
			[
				filters.search,
				filters.type,
				filters.origin,
				filters.status,
				filters.severity,
				filters.patient,
				filters.contact_id,
				filters.start_date,
				filters.end_date,
			].filter(Boolean).length,
		[filters],
	);

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.events"), {
			preserveState: true,
			replace: true,
		});
	};

	const clear = () => {
		setData({
			search: "",
			type: "",
			origin: "",
			status: "",
			severity: "",
			patient: "",
			contact_id: "",
			start_date: "",
			end_date: "",
		});
		router.get(
			route("admin.activecampaign.events"),
			{},
			{ preserveState: true, replace: true },
		);
	};

	return (
		<form onSubmit={apply} className="space-y-4">
			<div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
				<SearchInput
					value={data.search}
					onChange={(value) => setData("search", value)}
					placeholder="Buscar paciente, tipo, descripción…"
				/>
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

			<EventFilters
				data={data}
				setData={setData}
				filterOptions={filterOptions}
				contactOptions={contactOptions}
			/>

			{dirty ? (
				<div className="flex justify-center">
					<UpdateButton type="submit" processing={processing} />
				</div>
			) : null}
		</form>
	);
}
