import { useMemo } from "react";
import { router, useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import { ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import UpdateButton from "@/Components/Admin/UpdateButton";
import {
	SignalIcon,
	ArchiveBoxIcon,
	TagIcon,
	GlobeAltIcon,
} from "@heroicons/react/16/solid";

export default function ConfigToolbar({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		category: filters.category || "",
		environment: filters.environment || "",
		status: filters.status || "",
		origin: filters.origin || "",
	});

	const dirty = useMemo(
		() =>
			(data.category || "") !== (filters.category || "") ||
			(data.environment || "") !== (filters.environment || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.origin || "") !== (filters.origin || ""),
		[data, filters],
	);

	const activeCount = useMemo(
		() =>
			[
				filters.category,
				filters.environment,
				filters.status,
				filters.origin,
			].filter(Boolean).length,
		[filters],
	);

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.settings"), {
			preserveState: true,
			replace: true,
		});
	};

	const clear = () => {
		setData({
			category: "",
			environment: "",
			status: "",
			origin: "",
		});
		router.get(
			route("admin.activecampaign.settings"),
			{},
			{ preserveState: true, replace: true },
		);
	};

	const categories = filterOptions?.categories || [];
	const environments = filterOptions?.environments || [];
	const statuses = filterOptions?.statuses || [];
	const origins = filterOptions?.origins || [];

	return (
		<form onSubmit={apply} className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="text-xs text-zinc-500">
					Filtra por categoría, ambiente, estado y origen.
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

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
				<ListboxFilter
					label="Categoría"
					value={data.category}
					onChange={(value) => setData("category", value)}
				>
					{categories.map((opt) => (
						<ListboxOption key={opt.value || "all-cat"} value={opt.value}>
							<TagIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

				<ListboxFilter
					label="Ambiente"
					value={data.environment}
					onChange={(value) => setData("environment", value)}
				>
					{environments.map((opt) => (
						<ListboxOption key={opt.value || "all-env"} value={opt.value}>
							<GlobeAltIcon />
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
			</div>

			{dirty ? (
				<div className="flex justify-center">
					<UpdateButton type="submit" processing={processing} />
				</div>
			) : null}
		</form>
	);
}
