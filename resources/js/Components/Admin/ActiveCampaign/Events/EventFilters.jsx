import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import {
	ArchiveBoxIcon,
	BoltIcon,
	ExclamationTriangleIcon,
	SignalIcon,
	UserIcon,
} from "@heroicons/react/16/solid";

export default function EventFilters({
	data,
	setData,
	filterOptions,
	contactOptions = [],
}) {
	const types = filterOptions?.types || [];
	const origins = filterOptions?.origins || [];
	const statuses = filterOptions?.statuses || [];
	const severities = filterOptions?.severities || [];

	return (
		<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-7">
			<ListboxFilter
				label="Tipo"
				value={data.type}
				onChange={(value) => setData("type", value)}
			>
				{types.map((opt) => (
					<ListboxOption
						key={opt.value || "all-type"}
						value={opt.value}
						disabled={
							opt.scope === "contact" &&
							!(data.contact_id && String(data.contact_id) !== "")
						}
					>
						<BoltIcon />
						<ListboxLabel>
							{opt.label}
							{opt.scope === "contact" ? " (requiere paciente)" : ""}
						</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>

			<ListboxFilter
				label="Origen"
				value={data.origin}
				onChange={(value) => setData("origin", value)}
			>
				{origins.map((opt) => (
					<ListboxOption key={opt.value || "all-origin"} value={opt.value}>
						<ArchiveBoxIcon />
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
					<ListboxOption key={opt.value || "all-status"} value={opt.value}>
						<SignalIcon />
						<ListboxLabel>{opt.label}</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>

			<ListboxFilter
				label="Severidad"
				value={data.severity}
				onChange={(value) => setData("severity", value)}
			>
				{severities.map((opt) => (
					<ListboxOption key={opt.value || "all-sev"} value={opt.value}>
						<ExclamationTriangleIcon />
						<ListboxLabel>{opt.label}</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>

			<ListboxFilter
				label="Paciente"
				value={data.contact_id ? String(data.contact_id) : ""}
				onChange={(value) => setData("contact_id", value)}
			>
				<ListboxOption value="">
					<UserIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				{contactOptions.map((opt) => (
					<ListboxOption key={opt.id} value={String(opt.id)}>
						<UserIcon />
						<ListboxLabel>
							{opt.label}
							{opt.email ? ` · ${opt.email}` : ""}
						</ListboxLabel>
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
	);
}
