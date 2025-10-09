import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	MagnifyingGlassIcon,
	ArchiveBoxIcon,
	ArrowPathIcon,
	PresentationChartLineIcon,
	EyeSlashIcon,
	CalendarIcon,
	CheckCircleIcon,
	XCircleIcon,
	CalendarDateRangeIcon,
} from "@heroicons/react/16/solid";
import {
	LineChart,
	Line,
	XAxis,
	YAxis,
	Tooltip,
	ResponsiveContainer,
	CartesianGrid,
} from "recharts";
import { Divider } from "@/Components/Catalyst/divider";
import { ErrorMessage, Field, Label } from "@/Components/Catalyst/fieldset";
import { Button } from "@/Components/Catalyst/button";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import clsx from "clsx";
import SearchResultsMessage from "@/Components/SearchResultsMessage";

export default function Purchases({
	data,
	setData,
	updateResults,
	errors,
	processing,
	showUpdateButton,
	purchases,
	filters,
	chart,
}) {
	return (
		<>
			<Filters
				data={data}
				setData={setData}
				updateResults={updateResults}
				errors={errors}
				processing={processing}
				showUpdateButton={showUpdateButton}
			/>

			<Divider />

			<SearchResultsMessage purchases={purchases} filters={filters} />

			<Chart chart={chart} />
		</>
	);
}

function Filters({
	data,
	setData,
	updateResults,
	errors,
	processing,
	showUpdateButton,
}) {
	const filterOptions = [
		{
			value: "",
			label: "Todos",
			Icon: ArchiveBoxIcon,
		},
		{
			value: "false",
			label: "Activos",
			Icon: CheckCircleIcon,
		},
		{
			value: "true",
			label: "Cancelados",
			Icon: XCircleIcon,
		},
	];
	return (
		<form className="space-y-8" onSubmit={updateResults}>
			<div className="md:max-w-md">
				<InputGroup>
					<MagnifyingGlassIcon />
					<Input
						placeholder="Buscar pedidos"
						value={data.search}
						onChange={(e) => setData("search", e.target.value)}
					/>
				</InputGroup>
			</div>
			<div className="grid gap-8 md:grid-cols-3">
				<Field>
					<Label>Estatus</Label>
					<Listbox
						value={data.deleted}
						onChange={(value) => {
							setData("deleted", value);
						}}
					>
						{filterOptions.map((option) => (
							<ListboxOption
								key={option.value}
								value={option.value}
								className="group"
							>
								<option.Icon />
								<ListboxLabel>{option.label}</ListboxLabel>
							</ListboxOption>
						))}
					</Listbox>
				</Field>
				<Field>
					<Label>Desde</Label>
					<InputGroup>
						<CalendarIcon />
						<Input
							type="date"
							value={data.start_date}
							onChange={(e) =>
								setData("start_date", e.target.value)
							}
						/>
					</InputGroup>
					{errors.start_date && (
						<ErrorMessage className="mt-3">
							{errors.start_date}
						</ErrorMessage>
					)}
				</Field>

				<Field>
					<Label>Hasta</Label>
					<InputGroup>
						<CalendarIcon />
						<Input
							type="date"
							value={data.end_date}
							onChange={(e) =>
								setData("end_date", e.target.value)
							}
						/>
					</InputGroup>
					{errors.end_date && (
						<ErrorMessage className="mt-3">
							{errors.end_date}
						</ErrorMessage>
					)}
				</Field>
			</div>

			{showUpdateButton && (
				<div className="flex justify-center">
					<Button
						disabled={processing}
						type="submit"
						className="max-md:w-full"
					>
						<ArrowPathIcon className="animate-pulse" />
						Actualizar resultados
					</Button>
				</div>
			)}
		</form>
	);
}

function SearchResultsMessage({ purchases, filters }) {
	return (
		<div className="space-y-8">
			<SearchResultsMessage paginatedData={purchases} />

			{(filters.search ||
				filters.deleted ||
				filters.start_date ||
				filters.end_date) && (
				<div className="flex flex-wrap gap-2">
					{filters.search && (
						<Badge>
							<MagnifyingGlassIcon className="size-4" />{" "}
							{filters.search}
						</Badge>
					)}
					{filters.deleted &&
						(filters.deleted === "true" ? (
							<Badge color="red">
								<XCircleIcon className="size-4" />
								cancelados
							</Badge>
						) : (
							<Badge color="green">
								<CheckCircleIcon className="size-4" />
								activos
							</Badge>
						))}
					{filters.start_date && (
						<Badge>
							<CalendarDateRangeIcon className="size-4" />
							desde {filters.formatted_start_date}
						</Badge>
					)}
					{filters.end_date && (
						<Badge>
							<CalendarDateRangeIcon className="size-4" />
							hasta {filters.formatted_end_date}
						</Badge>
					)}
				</div>
			)}
		</div>
	);
}

function Chart({ chart }) {
	return (
		<Disclosure>
			{({ open }) => (
				<div
					className={clsx(
						"rounded-lg",
						open &&
							"bg-zinc-50 shadow-sm ring-1 ring-zinc-950/5 dark:bg-zinc-950/40 dark:ring-white/10",
					)}
				>
					<DisclosureButton
						className={clsx(
							"flex w-full",
							open ? "justify-center" : "justify-start",
						)}
					>
						<Button outline color="lime" as="div">
							{open ? (
								<EyeSlashIcon className="size-4" />
							) : (
								<PresentationChartLineIcon className="size-4" />
							)}
							{open ? "Ocultar" : "Ver"} gráfica
						</Button>
					</DisclosureButton>
					<DisclosurePanel className="p-4">
						<div className="flex flex-wrap justify-end gap-x-4 gap-y-2">
							<div className="flex items-center gap-1">
								<Text>{chart.averagePerDay}</Text>
								<Badge>promedio</Badge>
							</div>
							<div className="flex items-center gap-1">
								<Text>{chart.total}</Text>
								<Badge>total</Badge>
							</div>
						</div>

						<ResponsiveContainer height={300} className="mt-4">
							<LineChart
								data={chart.dataPoints}
								className="[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-700 [&_.recharts-dot[fill='#009ad8']]:fill-famedic-dark [&_.recharts-dot[fill='#009ad8']]:dark:fill-white [&_.recharts-dot[stroke='#fff']]:stroke-transparent [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-white"
							>
								<CartesianGrid vertical={false} />
								<XAxis
									className="text-xs"
									tickLine={false}
									axisLine={false}
									dataKey="date"
								/>
								<YAxis
									width={100}
									tickFormatter={(value) =>
										`$${(value / 100).toLocaleString(
											"en-US",
										)} MXN`
									}
									className="text-xs"
									tickLine={false}
									axisLine={false}
								/>

								<Tooltip
									cursor={{
										strokeWidth: 1.5,
										strokeDasharray: "10 3",
									}}
									content={<LineChartTooltip />}
								/>
								<Line
									dot={false}
									type="monotone"
									dataKey="value"
									stroke="#009ad8"
									strokeWidth={2}
								/>
							</LineChart>
						</ResponsiveContainer>
					</DisclosurePanel>
				</div>
			)}
		</Disclosure>
	);
}

function LineChartTooltip({ active, payload, label }) {
	if (active && payload && payload.length) {
		return (
			<div className="rounded-lg bg-white shadow-lg ring-1 ring-zinc-950/10 dark:bg-zinc-900 dark:ring-white/10">
				<div className="px-4 py-1">
					<Subheading>{label}</Subheading>
				</div>
				<Divider />
				<div className="px-4 py-1">
					<Text>{payload[0].payload.formattedValue}</Text>
				</div>
			</div>
		);
	}

	return null;
}
