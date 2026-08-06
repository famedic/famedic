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
	ArrowsRightLeftIcon,
	ChartBarIcon,
	TagIcon,
} from "@heroicons/react/16/solid";

export default function FieldsToolbar({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		type: filters.type || "",
		status: filters.status || "",
		sync: filters.sync || "",
		usage: filters.usage || "",
		origin: filters.origin || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const dirty = useMemo(
		() =>
			(data.type || "") !== (filters.type || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.sync || "") !== (filters.sync || "") ||
			(data.usage || "") !== (filters.usage || "") ||
			(data.origin || "") !== (filters.origin || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const activeCount = useMemo(
		() =>
			[
				filters.type,
				filters.status,
				filters.sync,
				filters.usage,
				filters.origin,
				filters.start_date,
				filters.end_date,
			].filter(Boolean).length,
		[filters],
	);

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.fields"), {
			preserveState: true,
			replace: true,
		});
	};

	const clear = () => {
		setData({
			type: "",
			status: "",
			sync: "",
			usage: "",
			origin: "",
			start_date: "",
			end_date: "",
		});
		router.get(
			route("admin.activecampaign.fields"),
			{},
			{ preserveState: true, replace: true },
		);
	};

	const types = filterOptions?.types || [];
	const statuses = filterOptions?.statuses || [];
	const syncs = filterOptions?.syncs || [];
	const usages = filterOptions?.usages || [];
	const origins = filterOptions?.origins || [];

	return (
		<form onSubmit={apply} className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="text-xs text-zinc-500">
					Filtra por tipo, estado, sincronización, uso, origen y periodo.
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

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-7">
				<ListboxFilter
					label="Tipo"
					value={data.type}
					onChange={(value) => setData("type", value)}
				>
					{types.map((opt) => (
						<ListboxOption key={opt.value || "all-type"} value={opt.value}>
							<TagIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

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
					label="Sincronización"
					value={data.sync}
					onChange={(value) => setData("sync", value)}
				>
					{syncs.map((opt) => (
						<ListboxOption key={opt.value || "all-sync"} value={opt.value}>
							<ArrowsRightLeftIcon />
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
