import AdminLayout from "@/Layouts/AdminLayout";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Avatar } from "@/Components/Catalyst/avatar";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	MagnifyingGlassIcon,
	PlusIcon,
	CheckCircleIcon,
	XCircleIcon,
	ArchiveBoxIcon,
	ShieldCheckIcon,
} from "@heroicons/react/16/solid";
import { XMarkIcon, CheckIcon } from "@heroicons/react/20/solid";
import { useForm } from "@inertiajs/react";
import { useMemo, useState } from "react";
import EmptyListCard from "@/Components/EmptyListCard";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import ResultsAndExport from "@/Components/ResultsAndExport";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import StatusBadge from "@/Components/StatusBadge";

export default function Administrators({ administrators, filters, roles }) {
	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		laboratory_concierge: filters.laboratory_concierge || "",
		role: filters.role || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.administrators.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.laboratory_concierge || "") !==
				(filters.laboratory_concierge || "") ||
			(data.role || "") !== (filters.role || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (filters.laboratory_concierge === "active") {
			badges.push(
				<StatusBadge isActive={true} activeText="concierge activo" />,
			);
		} else if (filters.laboratory_concierge === "inactive") {
			badges.push(
				<StatusBadge
					isActive={false}
					inactiveText="concierge inactivo"
					inactiveColor="red"
				/>,
			);
		}

		if (filters.role) {
			if (filters.role === "no_roles") {
				badges.push(
					<Badge color="zinc">
						<XCircleIcon className="size-4" />
						sin roles
					</Badge>,
				);
			} else {
				const selectedRole = roles?.find(
					(role) => role.id == filters.role,
				);
				if (selectedRole) {
					badges.push(
						<Badge color="sky">
							<ShieldCheckIcon className="size-4" />
							{selectedRole.name}
						</Badge>,
					);
				}
			}
		}

		return badges;
	}, [filters]);

	return (
		<AdminLayout title="Administradores">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-8">
					<Heading>Administradores</Heading>

					<Button
						dusk="createAdministrator"
						href={route("admin.administrators.create")}
					>
						<PlusIcon />
						Agregar administrador
					</Button>
				</div>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<div className="flex-1 md:max-w-md">
							<InputGroup>
								<MagnifyingGlassIcon />
								<Input
									placeholder="Buscar.."
									value={data.search}
									onChange={(e) =>
										setData("search", e.target.value)
									}
								/>
							</InputGroup>
						</div>
						<div className="flex items-center justify-end gap-2">
							<Button
								outline
								className="w-full"
								onClick={() => setShowFilters(!showFilters)}
							>
								Filtros
								<FilterCountBadge count={filterBadges.length} />
							</Button>
						</div>
					</div>

					{showFilters && (
						<Filters
							data={data}
							setData={setData}
							errors={errors}
							roles={roles}
						/>
					)}

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton
								type="submit"
								processing={processing}
							/>
						</div>
					)}
				</form>

				<AdministratorsList
					administrators={administrators}
					filterBadges={filterBadges}
					filters={filters}
				/>
			</div>
		</AdminLayout>
	);
}

function Filters({ data, setData, errors, roles }) {
	const roleOptions = [
		{
			value: "",
			label: "Todos los roles",
			Icon: ArchiveBoxIcon,
		},
		{
			value: "no_roles",
			label: "Sin roles",
			Icon: XCircleIcon,
		},
		...roles.map((role) => ({
			value: role.id.toString(),
			label: role.name,
			Icon: ShieldCheckIcon,
		})),
	];

	return (
		<div className="grid gap-4 md:grid-cols-3">
			<ListboxFilter
				label="Rol"
				value={data.role}
				onChange={(value) => setData("role", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos los roles</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="no_roles" className="group">
					<XCircleIcon />
					<ListboxLabel>Sin roles</ListboxLabel>
				</ListboxOption>
				{roles.map((role) => (
					<ListboxOption
						key={role.id}
						value={role.id.toString()}
						className="group"
					>
						<ShieldCheckIcon />
						<ListboxLabel>{role.name}</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>
			<ListboxFilter
				label="Cuenta de concierge"
				value={data.laboratory_concierge}
				onChange={(value) => setData("laboratory_concierge", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="active" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Activo</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="inactive" className="group">
					<XCircleIcon />
					<ListboxLabel>Inactivo</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
		</div>
	);
}

function AdministratorsList({ administrators, filterBadges, filters }) {
	if (administrators.data.length === 0) return <EmptyListCard />;

	return (
		<>
			<ResultsAndExport
				paginatedData={administrators}
				filterBadges={filterBadges}
				canExport={true}
				filters={filters}
				exportUrl={route("admin.administrators.export")}
				exportTitle="Descargar administradores"
			/>
			<PaginatedTable paginatedData={administrators}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Administrador</TableHeader>
							<TableHeader>Roles</TableHeader>
							<TableHeader className="text-right">
								Concierge de laboratorio
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{administrators.data.map((administrator) => (
							<TableRow
								key={administrator.id}
								href={route(
									"admin.administrators.edit",
									administrator.id,
								)}
								title={`Administrador #${administrator.id}`}
								dusk={`editAdministrator-${administrator.id}`}
							>
								<TableCell>
									<div className="flex items-center gap-2">
										<Avatar
											src={
												administrator.user
													.profile_photo_url
											}
											className="size-12"
										/>
										<div>
											<Text>
												<Strong>
													{
														administrator.user
															.full_name
													}
												</Strong>
											</Text>
											<Text>
												{administrator.user.email}
											</Text>
										</div>
									</div>
								</TableCell>

								<TableCell>
									<div className="flex gap-2">
										{administrator.roles.length === 0 ? (
											<Badge color="zinc">
												Sin roles
												<XMarkIcon className="h-4 w-4" />
											</Badge>
										) : (
											administrator.roles.map((role) => (
												<Badge
													key={role.id}
													color="sky"
												>
													{role.name}
												</Badge>
											))
										)}
									</div>
								</TableCell>
								<TableCell className="text-right">
									<Badge
										color={
											administrator.laboratory_concierge
												? "famedic-lime"
												: "zinc"
										}
									>
										{administrator.laboratory_concierge
											? "Activo"
											: "Inactivo"}
										{administrator.laboratory_concierge ? (
											<CheckIcon className="h-4 w-4" />
										) : (
											<XMarkIcon className="h-4 w-4" />
										)}
									</Badge>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</PaginatedTable>
		</>
	);
}
