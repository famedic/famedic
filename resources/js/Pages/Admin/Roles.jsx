import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { PlusIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Pagination,
	PaginationNext,
	PaginationPrevious,
} from "@/Components/Catalyst/pagination";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Text, Strong } from "@/Components/Catalyst/text";
import { usePage } from "@inertiajs/react";

const PREVIEW_LIMIT = 4;

function isRootPermissionKey(key) {
	if (!key.includes(".")) {
		return true;
	}

	return key.split(".").length === 2;
}

function RolePermissionsSummary({ role, permissionsNames }) {
	const rootKeys = Object.keys(permissionsNames).filter(isRootPermissionKey);
	const grantedRoots = rootKeys.filter((key) =>
		role.permissions.some((permission) => permission.name === key),
	);
	const grantedCount = role.permissions.length;
	const totalCount = Object.keys(permissionsNames).length;
	const preview = grantedRoots.slice(0, PREVIEW_LIMIT);
	const remaining = grantedRoots.length - preview.length;

	return (
		<div className="space-y-2">
			<div className="flex flex-wrap items-center gap-2">
				<Badge color="slate">
					{grantedCount} de {totalCount} permisos
				</Badge>
				{grantedRoots.length > 0 && remaining > 0 && (
					<Text className="text-xs text-zinc-500">
						{grantedRoots.length} módulos activos
					</Text>
				)}
			</div>

			{preview.length > 0 ? (
				<div className="flex flex-wrap gap-1.5">
					{preview.map((key) => (
						<Badge key={key} color="zinc" className="max-w-56 truncate">
							{permissionsNames[key]}
						</Badge>
					))}
					{remaining > 0 && (
						<Badge color="slate">+{remaining} más</Badge>
					)}
				</div>
			) : (
				<Text className="text-sm text-zinc-500">Sin permisos asignados</Text>
			)}
		</div>
	);
}

export default function Roles() {
	return (
		<AdminLayout title="Roles y permisos">
			<div className="flex flex-wrap items-end justify-between gap-8">
				<Heading>Roles y permisos</Heading>

				<Button dusk="createRole" href={route("admin.roles.create")}>
					<PlusIcon />
					Agregar rol
				</Button>
			</div>
			<RolesList />
		</AdminLayout>
	);
}

function RolesList() {
	const { roles, permissionsNames } = usePage().props;

	return (
		<>
			<Table className="mt-8 [--gutter:theme(spacing.6)]">
				<TableHead>
					<TableRow>
						<TableHeader className="w-48 sm:w-64">Nombre</TableHeader>
						<TableHeader>Permisos</TableHeader>
					</TableRow>
				</TableHead>
				<TableBody>
					{roles.data.map((role) => (
						<TableRow
							key={role.id}
							href={route("admin.roles.edit", role.id)}
							title={`Rol #${role.id}`}
							dusk={`editRole-${role.id}`}
						>
							<TableCell className="align-top">
								<Text>
									<Strong>{role.name}</Strong>
								</Text>
							</TableCell>
							<TableCell>
								<RolePermissionsSummary
									role={role}
									permissionsNames={permissionsNames}
								/>
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
			<Pagination className="mt-4">
				{roles.prev_page_url && (
					<PaginationPrevious href={roles.prev_page_url}>
						Anterior
					</PaginationPrevious>
				)}
				{roles.next_page_url && (
					<PaginationNext href={roles.next_page_url}>
						Siguiente
					</PaginationNext>
				)}
			</Pagination>
		</>
	);
}
