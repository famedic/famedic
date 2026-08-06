import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import {
	BoltIcon,
	ExclamationTriangleIcon,
	SignalIcon,
	UserIcon,
} from "@heroicons/react/16/solid";

export default function NotificationFilters({ data, setData, filterOptions }) {
	const types = filterOptions?.types || [];
	const priorities = filterOptions?.priorities || [];
	const statuses = filterOptions?.statuses || [];

	return (
		<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
			<ListboxFilter
				label="Tipo"
				value={data.type}
				onChange={(value) => setData("type", value)}
			>
				{types.map((opt) => (
					<ListboxOption key={opt.value || "all-type"} value={opt.value}>
						<BoltIcon />
						<ListboxLabel>{opt.label}</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>

			<ListboxFilter
				label="Prioridad"
				value={data.priority}
				onChange={(value) => setData("priority", value)}
			>
				{priorities.map((opt) => (
					<ListboxOption key={opt.value || "all-pri"} value={opt.value}>
						<ExclamationTriangleIcon />
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
						placeholder="Email o nombre…"
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
	);
}
