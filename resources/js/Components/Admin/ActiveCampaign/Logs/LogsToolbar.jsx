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
	ExclamationTriangleIcon,
	Squares2X2Icon,
	ArchiveBoxIcon,
	UserIcon,
} from "@heroicons/react/16/solid";

export default function LogsToolbar({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		level: filters.level || "",
		module: filters.module || "",
		origin: filters.origin || "",
		patient: filters.patient || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const dirty = useMemo(
		() =>
			(data.level || "") !== (filters.level || "") ||
			(data.module || "") !== (filters.module || "") ||
			(data.origin || "") !== (filters.origin || "") ||
			(data.patient || "") !== (filters.patient || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const activeCount = useMemo(
		() =>
			[
				filters.level,
				filters.module,
				filters.origin,
				filters.patient,
				filters.start_date,
				filters.end_date,
			].filter(Boolean).length,
		[filters],
	);

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.logs"), {
			preserveState: true,
			replace: true,
		});
	};

	const clear = () => {
		setData({
			level: "",
			module: "",
			origin: "",
			patient: "",
			start_date: "",
			end_date: "",
		});
		router.get(
			route("admin.activecampaign.logs"),
			{},
			{ preserveState: true, replace: true },
		);
	};

	const levels = filterOptions?.levels || [];
	const modules = filterOptions?.modules || [];
	const origins = filterOptions?.origins || [];

	return (
		<form onSubmit={apply} className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="text-xs text-zinc-500">
					Filtra por nivel, módulo, origen, paciente y periodo.
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
					label="Nivel"
					value={data.level}
					onChange={(value) => setData("level", value)}
				>
					{levels.map((opt) => (
						<ListboxOption key={opt.value || "all-lvl"} value={opt.value}>
							<ExclamationTriangleIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

				<ListboxFilter
					label="Módulo"
					value={data.module}
					onChange={(value) => setData("module", value)}
				>
					{modules.map((opt) => (
						<ListboxOption key={opt.value || "all-mod"} value={opt.value}>
							<Squares2X2Icon />
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

				<label className="block space-y-1.5">
					<span className="text-xs font-medium text-zinc-600 dark:text-zinc-400">
						Paciente
					</span>
					<div className="relative">
						<UserIcon className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
						<input
							type="text"
							value={data.patient || ""}
							onChange={(e) => setData("patient", e.target.value)}
							placeholder="Email…"
							className="w-full rounded-lg border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 shadow-sm outline-none focus:border-famedic-light dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
						/>
					</div>
				</label>

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
