import { useMemo } from "react";
import { router, useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import DateFilter from "@/Components/Filters/DateFilter";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import { ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import UpdateButton from "@/Components/Admin/UpdateButton";
import {
	SignalIcon,
	ArchiveBoxIcon,
	TagIcon,
	ChartBarIcon,
} from "@heroicons/react/16/solid";

export default function TagsToolbar({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		status: filters.status || "",
		origin: filters.origin || "",
		application: filters.application || "",
		usage: filters.usage || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const dirty = useMemo(
		() =>
			(data.status || "") !== (filters.status || "") ||
			(data.origin || "") !== (filters.origin || "") ||
			(data.application || "") !== (filters.application || "") ||
			(data.usage || "") !== (filters.usage || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const activeCount = useMemo(
		() =>
			[
				filters.status,
				filters.origin,
				filters.application,
				filters.usage,
				filters.start_date,
				filters.end_date,
			].filter(Boolean).length,
		[filters],
	);

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.tags"), {
			preserveState: true,
			replace: true,
		});
	};

	const clear = () => {
		setData({
			status: "",
			origin: "",
			application: "",
			usage: "",
			start_date: "",
			end_date: "",
		});
		router.get(
			route("admin.activecampaign.tags"),
			{},
			{ preserveState: true, replace: true },
		);
	};

	const statuses = filterOptions?.statuses || [];
	const origins = filterOptions?.origins || [];
	const applications = filterOptions?.applications || [];
	const usages = filterOptions?.usages || [];

	return (
		<form onSubmit={apply} className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="text-xs text-zinc-500">
					Filtra por estado, origen, automático/manual, uso y periodo.
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

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
				<ListboxFilter
					label="Estado"
					value={data.status}
					onChange={(value) => setData("status", value)}
				>
					{statuses.map((opt) => (
						<ListboxOption key={opt.value || "all-st"} value={opt.value}>
							<SignalIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

				<ListboxFilter
					label="Origen"
					value={data.origin}
					onChange={(value) => setData("origin", value)}
				>
					{origins.map((opt) => (
						<ListboxOption key={opt.value || "all-ori"} value={opt.value}>
							<ArchiveBoxIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

				<ListboxFilter
					label="Aplicación"
					value={data.application}
					onChange={(value) => setData("application", value)}
				>
					{applications.map((opt) => (
						<ListboxOption key={opt.value || "all-app"} value={opt.value}>
							<TagIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

				<ListboxFilter
					label="Uso"
					value={data.usage}
					onChange={(value) => setData("usage", value)}
				>
					{usages.map((opt) => (
						<ListboxOption key={opt.value || "all-use"} value={opt.value}>
							<ChartBarIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

				<DateFilter
					label="Desde"
					value={data.start_date}
					onChange={(value) => setData("start_date", value)}
				/>
				<DateFilter
					label="Hasta"
					value={data.end_date}
					onChange={(value) => setData("end_date", value)}
				/>
			</div>

			{dirty ? (
				<div className="flex justify-center">
					<UpdateButton type="submit" processing={processing} />
				</div>
			) : null}
		</form>
	);
}
