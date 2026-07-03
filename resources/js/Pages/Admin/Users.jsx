import { useEffect, useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Avatar } from "@/Components/Catalyst/avatar";
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
import DateFilter from "@/Components/Filters/DateFilter";
import UsersChart from "@/Components/Admin/UsersChart";
import {
	CheckCircleIcon,
	XCircleIcon,
	CalendarDateRangeIcon,
	EnvelopeIcon,
	PhoneIcon,
	ShoppingCartIcon,
} from "@heroicons/react/16/solid";
import { PresentationChartLineIcon } from "@heroicons/react/24/outline";
import { Link } from "@inertiajs/react";

function getInitials(user) {
	const name = user.name || "";
	const last = user.paternal_lastname || "";
	return (name.charAt(0) + last.charAt(0)).toUpperCase() || "?";
}

function formatDate(dateStr) {
	if (!dateStr) return "—";
	const d = new Date(dateStr);
	if (isNaN(d.getTime())) return dateStr;
	const dd = String(d.getDate()).padStart(2, "0");
	const mm = String(d.getMonth() + 1).padStart(2, "0");
	const yyyy = d.getFullYear();
	return `${dd}-${mm}-${yyyy}`;
}

export default function Users({ users, filters, chart }) {
	const view = filters.view || "list";

	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		verified: filters.verified || "",
		view,
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	useEffect(() => {
		setData({
			search: filters.search || "",
			verified: filters.verified || "",
			view: filters.view || "list",
			start_date: filters.start_date || "",
			end_date: filters.end_date || "",
		});
	}, [
		filters.search,
		filters.verified,
		filters.view,
		filters.start_date,
		filters.end_date,
	]);

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.users.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(() => {
		const listChanged =
			(data.search || "") !== (filters.search || "") ||
			(data.verified || "") !== (filters.verified || "");
		const chartDatesChanged =
			view === "chart" &&
			((data.start_date || "") !== (filters.start_date || "") ||
				(data.end_date || "") !== (filters.end_date || ""));
		return listChanged || chartDatesChanged;
	}, [data, filters, view]);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(<Badge color="sky">{filters.search}</Badge>);
		}

		if (filters.verified === "verified") {
			badges.push(
				<Badge color="famedic-lime">
					<CheckCircleIcon className="size-4" />
					Verificados
				</Badge>,
			);
		} else if (filters.verified === "unverified") {
			badges.push(
				<Badge color="red">
					<XCircleIcon className="size-4" />
					No verificados
				</Badge>,
			);
		}

		if (view === "chart" && filters.start_date) {
			badges.push(
				<Badge color="slate">
					<CalendarDateRangeIcon className="size-4" />
					desde {filters.start_date}
				</Badge>,
			);
		}

		if (view === "chart" && filters.end_date) {
			badges.push(
				<Badge color="slate">
					<CalendarDateRangeIcon className="size-4" />
					hasta {filters.end_date}
				</Badge>,
			);
		}

		return badges;
	}, [filters, view]);

	const filtersCount = useMemo(() => {
		let n = ["search", "verified"].filter((key) => filters[key]).length;
		if (view === "chart") {
			if (filters.start_date) {
				n += 1;
			}
			if (filters.end_date) {
				n += 1;
			}
		}
		return n;
	}, [filters, view]);

	const listTabHref = route("admin.users.index", {
		search: data.search || undefined,
		verified: data.verified || undefined,
		view: "list",
	});

	const chartTabHref = route("admin.users.index", {
		search: data.search || undefined,
		verified: data.verified || undefined,
		view: "chart",
		start_date: data.start_date || undefined,
		end_date: data.end_date || undefined,
	});

	return (
		<AdminLayout title="Usuarios">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center gap-4 justify-between">
					<Heading>Usuarios</Heading>

					<div className="flex flex-wrap items-center gap-3">
						<div className="flex rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-600">
							<Button
								href={listTabHref}
								outline={view !== "list"}
								className="rounded-md !border-0"
							>
								Lista
							</Button>
							<Button
								href={chartTabHref}
								outline={view !== "chart"}
								className="rounded-md !border-0"
							>
								<PresentationChartLineIcon className="size-5" />
								Gráficas
							</Button>
						</div>
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por nombre, correo o teléfono..."
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
							{view === "chart"
								? "Actualizar gráficas"
								: "Actualizar resultados"}
						</Button>
					</div>
				</div>

				{filterBadges.length > 0 && (
					<div className="flex flex-wrap gap-2">
						{filterBadges.map((badge, index) => (
							<span key={index}>{badge}</span>
						))}
					</div>
				)}

				{showFilters && (
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-3">
						<div className="flex flex-wrap gap-4 items-end">
							<div className="space-y-1">
								<Text className="text-sm font-medium">
									Estado de verificación
								</Text>
								<div className="flex gap-2">
									<Button
										type="button"
										outline={data.verified !== "verified"}
										onClick={() => setData("verified", "verified")}
									>
										Verificados
									</Button>
									<Button
										type="button"
										outline={data.verified !== "unverified"}
										onClick={() => setData("verified", "unverified")}
									>
										No verificados
									</Button>
									<Button
										type="button"
										outline={data.verified !== ""}
										onClick={() => setData("verified", "")}
									>
										Todos
									</Button>
								</div>
							</div>
						</div>
					</div>
				)}

				{view === "chart" && (
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
							Rango en zona horaria Monterrey. Por defecto se muestran los últimos
							30 días. En la gráfica de tipos de cuenta, “sin contraseña”
							aproxima el registro o acceso OAuth cuando el usuario aún no tiene
							contraseña local.
						</Text>
						<div className="flex flex-wrap gap-4 items-end">
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
					</div>
				)}

				{view === "chart" && chart && <UsersChart chart={chart} />}

			{view === "list" && (
				<PaginatedTable paginatedData={users}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Usuario</TableHeader>
								<TableHeader className="text-center">Verificación</TableHeader>
								<TableHeader>Registrado</TableHeader>
								<TableHeader className="text-center">Carrito</TableHeader>
								<TableHeader className="text-right">Acciones</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{users.data.map((user) => (
								<TableRow key={user.id}>
									<TableCell>
										<div className="flex items-center gap-3">
											<Avatar
												initials={getInitials(user)}
												src={user.profile_photo_url}
												alt={user.full_name || user.email}
												className="size-10"
											/>
											<div className="min-w-0">
												<Link
													href={route("admin.users.show", { user: user.id })}
													className="text-sm font-semibold text-zinc-900 hover:text-famedic-dark dark:text-white dark:hover:text-famedic-light"
												>
													{user.full_name || "Sin nombre"}
												</Link>
												{user.phone && (
													<p className="text-xs text-zinc-500 truncate">
														{user.full_phone || user.phone}
													</p>
												)}
												<p className="text-xs text-zinc-500 truncate">
													{user.email}
												</p>
											</div>
										</div>
									</TableCell>
									<TableCell>
										<div className="flex items-center justify-center gap-2">
											<span title={user.email_verified_at ? "Correo verificado" : "Correo no verificado"}>
												<EnvelopeIcon
													className={`size-5 ${user.email_verified_at ? "text-green-500" : "text-zinc-300 dark:text-zinc-600"}`}
												/>
											</span>
											<span title={user.phone_verified_at ? "Teléfono verificado" : "Teléfono no verificado"}>
												<PhoneIcon
													className={`size-5 ${user.phone_verified_at ? "text-green-500" : "text-zinc-300 dark:text-zinc-600"}`}
												/>
											</span>
										</div>
									</TableCell>
									<TableCell>
										<Text className="text-sm text-zinc-500 whitespace-nowrap">
											{formatDate(user.created_at)}
										</Text>
									</TableCell>
									<TableCell>
										<div className="flex items-center justify-center gap-1">
											<ShoppingCartIcon className="size-4 text-zinc-400" />
											<Text className="text-sm">
												{user.active_carts_count || 0}
											</Text>
										</div>
									</TableCell>
									<TableCell>
										<div className="flex items-center justify-end gap-2">
											<Button
												href={route("admin.users.show", { user: user.id })}
												outline
												size="sm"
											>
												Ver detalles
											</Button>
											<Button
												href={route("admin.users.show", { user: user.id })}
												size="sm"
											>
												Gestionar
											</Button>
										</div>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</PaginatedTable>
			)}
			</div>
		</AdminLayout>
	);
}
