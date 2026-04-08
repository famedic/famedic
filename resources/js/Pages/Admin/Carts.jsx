import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import {
	ShoppingCartIcon,
	ClockIcon,
} from "@heroicons/react/16/solid";

function statusBadge(displayStatus) {
	if (displayStatus === "completed") {
		return { color: "blue", label: "Comprado" };
	}
	if (displayStatus === "abandoned") {
		return { color: "red", label: "Abandonado" };
	}
	return { color: "green", label: "Activo" };
}

export default function Carts({
	carts,
	filters,
	metrics,
	canViewCartDetails = true,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		type: filters.type || "",
		display_status: filters.display_status || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.carts.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.type || "") !== (filters.type || "") ||
			(data.display_status || "") !== (filters.display_status || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];
		if (filters.search) {
			badges.push(
				<Badge key="s" color="sky">
					{filters.search}
				</Badge>,
			);
		}
		if (filters.type === "pharmacy") {
			badges.push(
				<Badge key="t" color="slate">
					Farmacia
				</Badge>,
			);
		} else if (filters.type === "lab") {
			badges.push(
				<Badge key="t" color="slate">
					Laboratorio
				</Badge>,
			);
		}
		if (filters.display_status) {
			const { label } = statusBadge(filters.display_status);
			badges.push(
				<Badge key="d" color="famedic-lime">
					{label}
				</Badge>,
			);
		}
		if (filters.start_date || filters.end_date) {
			badges.push(
				<Badge key="r" color="zinc">
					{filters.start_date || "…"} — {filters.end_date || "…"}
				</Badge>,
			);
		}
		return badges;
	}, [filters]);

	const filtersCount = useMemo(
		() =>
			["search", "type", "display_status", "start_date", "end_date"].filter(
				(key) => filters[key],
			).length,
		[filters],
	);

	return (
		<AdminLayout title="Carritos">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center gap-4 justify-between">
					<Heading>Monitoreo · Carritos</Heading>
					<div className="flex items-center gap-3">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por usuario..."
						/>
						<Button
							outline
							type="button"
							onClick={() => setShowFilters((v) => !v)}
						>
							Filtros
							<FilterCountBadge count={filtersCount} />
						</Button>
						<Button
							className="max-md:w-full"
							disabled={processing || !showUpdateButton}
							onClick={updateResults}
						>
							Actualizar resultados
						</Button>
					</div>
				</div>

				{metrics && (
					<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-xs text-zinc-500">Activos</Text>
							<Subheading className="mt-1">{metrics.active}</Subheading>
						</div>
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-xs text-zinc-500">Abandonados</Text>
							<Subheading className="mt-1 text-red-600 dark:text-red-400">
								{metrics.abandoned}
							</Subheading>
						</div>
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-xs text-zinc-500">Comprados</Text>
							<Subheading className="mt-1 text-blue-600 dark:text-sky-300">
								{metrics.completed}
							</Subheading>
						</div>
						<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Text className="text-xs text-zinc-500">
								Conversión (comprado / comprado+abandono)
							</Text>
							<Subheading className="mt-1">
								{metrics.conversion_percent != null
									? `${metrics.conversion_percent}%`
									: "—"}
							</Subheading>
						</div>
					</div>
				)}

				{filterBadges.length > 0 && (
					<div className="flex flex-wrap gap-2">
						{filterBadges.map((badge, index) => (
							<span key={index}>{badge}</span>
						))}
					</div>
				)}

				{showFilters && (
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-3">
						<div className="grid gap-4 md:grid-cols-3">
							<div className="space-y-1">
								<Text className="text-sm font-medium">Tipo</Text>
								<select
									value={data.type}
									onChange={(e) => setData("type", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									<option value="pharmacy">Farmacia</option>
									<option value="lab">Laboratorio</option>
								</select>
							</div>
							<div className="space-y-1">
								<Text className="text-sm font-medium">Estatus</Text>
								<select
									value={data.display_status}
									onChange={(e) =>
										setData("display_status", e.target.value)
									}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									<option value="active">Activo</option>
									<option value="abandoned">Abandonado</option>
									<option value="completed">Comprado</option>
								</select>
							</div>
							<div className="space-y-1 md:col-span-1">
								<Text className="text-sm font-medium">Rango (actividad)</Text>
								<div className="flex flex-wrap gap-2">
									<input
										type="date"
										value={data.start_date}
										onChange={(e) =>
											setData("start_date", e.target.value)
										}
										className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
									/>
									<input
										type="date"
										value={data.end_date}
										onChange={(e) =>
											setData("end_date", e.target.value)
										}
										className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
									/>
								</div>
							</div>
						</div>
					</div>
				)}

				<PaginatedTable paginatedData={carts}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Usuario</TableHeader>
								<TableHeader>Tipo</TableHeader>
								<TableHeader>Ítems</TableHeader>
								<TableHeader>Total</TableHeader>
								<TableHeader>Estatus</TableHeader>
								<TableHeader>Última actividad</TableHeader>
								<TableHeader></TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{carts.data.map((cart) => {
								const b = statusBadge(cart.display_status);
								return (
									<TableRow key={cart.id}>
										<TableCell>
											{cart.user ? (
												<div className="space-y-0.5">
													<Text>
														<Strong>
															{cart.user.full_name ||
																cart.user.email}
														</Strong>
													</Text>
													{cart.user.email && (
														<Text className="text-xs text-zinc-500">
															{cart.user.email}
														</Text>
													)}
												</div>
											) : (
												<Text className="text-sm">—</Text>
											)}
										</TableCell>
										<TableCell>
											<div className="flex items-center gap-1 text-sm">
												<ShoppingCartIcon className="size-4 text-zinc-400" />
												{cart.type_label}
											</div>
										</TableCell>
										<TableCell>
											<Strong>{cart.items_count}</Strong>
										</TableCell>
										<TableCell>{cart.total_formatted}</TableCell>
										<TableCell>
											<Badge color={b.color}>{b.label}</Badge>
										</TableCell>
										<TableCell>
											<div className="flex items-center gap-1 text-xs text-zinc-500">
												<ClockIcon className="size-4" />
												{cart.updated_at_human}
											</div>
										</TableCell>
										<TableCell>
											{canViewCartDetails ? (
												<Button
													href={route("admin.carts.show", {
														cart: cart.id,
													})}
													outline
													size="sm"
												>
													Ver detalle
												</Button>
											) : (
												<Text className="text-xs text-zinc-500">
													Sin permiso
												</Text>
											)}
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
		</AdminLayout>
	);
}
