import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
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
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import StatusBadge from "@/Components/StatusBadge";
import CustomerInfo from "@/Components/CustomerInfo";
import {
	CheckCircleIcon,
	XCircleIcon,
	CreditCardIcon,
	GlobeAltIcon,
} from "@heroicons/react/16/solid";

export default function EfevooTokens({ tokens, filters }) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		environment: filters.environment || "",
		status: filters.status || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.efevoo-tokens.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.environment || "") !== (filters.environment || "") ||
			(data.status || "") !== (filters.status || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge color="sky">
					<CreditCardIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (filters.environment === "production") {
			badges.push(
				<Badge color="emerald">
					<GlobeAltIcon className="size-4" />
					Producción
				</Badge>,
			);
		} else if (filters.environment === "test") {
			badges.push(
				<Badge color="slate">
					<GlobeAltIcon className="size-4" />
					Pruebas
				</Badge>,
			);
		}

		if (filters.status === "active") {
			badges.push(
				<StatusBadge
					isActive={true}
					activeText="Activos"
					activeColor="famedic-lime"
				/>,
			);
		} else if (filters.status === "inactive") {
			badges.push(
				<StatusBadge
					isActive={false}
					inactiveText="Inactivos / vencidos"
					inactiveColor="red"
				/>,
			);
		}

		return badges;
	}, [filters]);

	const filtersCount = useMemo(
		() =>
			["search", "environment", "status"].filter(
				(key) => filters[key],
			).length,
		[filters],
	);

	return (
		<AdminLayout title="Tokens Efevoo">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center gap-4 justify-between">
					<Heading>Tokens de Efevoo</Heading>

					<div className="flex items-center gap-3">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por alias, tarjeta o cliente..."
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

				{filterBadges.length > 0 && (
					<div className="flex flex-wrap gap-2">
						{filterBadges.map((badge, index) => (
							<span key={index}>{badge}</span>
						))}
					</div>
				)}

				{showFilters && (
					<div className="grid gap-4 md:grid-cols-3 lg:grid-cols-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<ListboxFilter
							label="Entorno"
							placeholder="Todos"
							value={data.environment}
							onChange={(value) => setData("environment", value)}
						>
							<ListboxOption value="">
								<ListboxLabel>Todos</ListboxLabel>
							</ListboxOption>
							<ListboxOption value="production">
								<ListboxLabel>Producción</ListboxLabel>
							</ListboxOption>
							<ListboxOption value="test">
								<ListboxLabel>Pruebas</ListboxLabel>
							</ListboxOption>
						</ListboxFilter>

						<ListboxFilter
							label="Estatus"
							placeholder="Todos"
							value={data.status}
							onChange={(value) => setData("status", value)}
						>
							<ListboxOption value="">
								<ListboxLabel>Todos</ListboxLabel>
							</ListboxOption>
							<ListboxOption value="active">
								<ListboxLabel>Activos</ListboxLabel>
							</ListboxOption>
							<ListboxOption value="inactive">
								<ListboxLabel>Inactivos / vencidos</ListboxLabel>
							</ListboxOption>
						</ListboxFilter>
					</div>
				)}

				<PaginatedTable paginatedData={tokens}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Alias / tarjeta</TableHeader>
								<TableHeader>Cliente</TableHeader>
								<TableHeader>Entorno</TableHeader>
								<TableHeader>Estatus</TableHeader>
								<TableHeader>Expira</TableHeader>
								<TableHeader></TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{tokens.data.map((token) => (
								<TableRow key={token.id}>
									<TableCell>
										<div className="space-y-1">
											<div className="flex items-center gap-2">
												<CreditCardIcon className="size-4 text-zinc-400" />
												<span className="font-medium">
													{token.alias || "Sin alias"}
												</span>
											</div>
											<div className="text-xs text-zinc-500">
												{token.card_brand || "Tarjeta"} ••••{" "}
												{token.card_last_four}
											</div>
										</div>
									</TableCell>
									<TableCell>
										{token.customer && (
											<CustomerInfo customer={token.customer} />
										)}
									</TableCell>
									<TableCell>
										<Badge
											color={
												token.environment === "production"
													? "emerald"
													: "slate"
											}
										>
											<GlobeAltIcon className="size-4" />
											{token.formatted_environment}
										</Badge>
									</TableCell>
									<TableCell>
										<div className="space-y-1">
											<StatusBadge
												isActive={token.is_active && !token.is_expired}
												activeText="Activo"
												inactiveText={
													token.is_expired
														? "Vencido"
														: "Inactivo"
												}
												inactiveColor={
													token.is_expired ? "red" : "slate"
												}
											/>
										</div>
									</TableCell>
									<TableCell>
										<div className="flex items-center gap-1 text-xs text-zinc-500">
											{token.expires_at ? (
												<>
													{token.is_expired ? (
														<XCircleIcon className="size-4 text-red-500" />
													) : (
														<CheckCircleIcon className="size-4 text-emerald-500" />
													)}
													<span>
														{token.formatted_expiration ||
															token.expires_at}
													</span>
												</>
											) : (
												<span>Sin expiración</span>
											)}
										</div>
									</TableCell>
									<TableCell>
										<Button
											href={route("admin.efevoo-tokens.show", {
												efevoo_token: token.id,
											})}
											outline
											size="sm"
										>
											Ver detalles
										</Button>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
		</AdminLayout>
	);
}

