import { useMemo } from "react";
import { router, useForm } from "@inertiajs/react";
import {
	ArchiveBoxIcon,
	ArrowDownTrayIcon,
	ArrowPathIcon,
	CheckCircleIcon,
	XCircleIcon,
	TagIcon,
	BeakerIcon,
	SignalIcon,
} from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import UpdateButton from "@/Components/Admin/UpdateButton";
import DateFilter from "@/Components/Filters/DateFilter";
import ListboxFilter from "@/Components/Filters/ListboxFilter";

export default function ContactsToolbar({ filters }) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		tag: filters.tag || "",
		status: filters.status || "",
		membership: filters.membership || "",
		laboratory: filters.laboratory || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const dirty = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.tag || "") !== (filters.tag || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.membership || "") !== (filters.membership || "") ||
			(data.laboratory || "") !== (filters.laboratory || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const activeCount = useMemo(
		() =>
			[
				filters.search,
				filters.tag,
				filters.status,
				filters.membership,
				filters.laboratory,
				filters.start_date,
				filters.end_date,
			].filter(Boolean).length,
		[filters],
	);

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.contacts"), {
			preserveState: true,
			replace: true,
		});
	};

	const clear = () => {
		setData({
			search: "",
			tag: "",
			status: "",
			membership: "",
			laboratory: "",
			start_date: "",
			end_date: "",
		});
		router.get(
			route("admin.activecampaign.contacts"),
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
					placeholder="Buscar paciente, correo o teléfono…"
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
						Limpiar filtros
					</Button>
					<Button
						type="button"
						outline
						disabled
						title="Exportación disponible en una siguiente fase"
					>
						<ArrowDownTrayIcon className="size-4" />
						Exportar
					</Button>
				</div>
			</div>

			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
				<ListboxFilter
					label="Tags"
					value={data.tag}
					onChange={(value) => setData("tag", value)}
				>
					<ListboxOption value="">
						<ArchiveBoxIcon />
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="__pending" disabled>
						<TagIcon />
						<ListboxLabel>Requiere instrumentación</ListboxLabel>
					</ListboxOption>
				</ListboxFilter>

				<ListboxFilter
					label="Estado"
					value={data.status}
					onChange={(value) => setData("status", value)}
				>
					<ListboxOption value="">
						<ArchiveBoxIcon />
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="__pending" disabled>
						<SignalIcon />
						<ListboxLabel>Requiere instrumentación</ListboxLabel>
					</ListboxOption>
				</ListboxFilter>

				<ListboxFilter
					label="Membresía"
					value={data.membership}
					onChange={(value) => setData("membership", value)}
				>
					<ListboxOption value="">
						<ArchiveBoxIcon />
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="active">
						<CheckCircleIcon />
						<ListboxLabel>Activa</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="inactive">
						<XCircleIcon />
						<ListboxLabel>Inactiva</ListboxLabel>
					</ListboxOption>
				</ListboxFilter>

				<ListboxFilter
					label="Laboratorio"
					value={data.laboratory}
					onChange={(value) => setData("laboratory", value)}
				>
					<ListboxOption value="">
						<ArchiveBoxIcon />
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="__pending" disabled>
						<BeakerIcon />
						<ListboxLabel>Próximamente</ListboxLabel>
					</ListboxOption>
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
