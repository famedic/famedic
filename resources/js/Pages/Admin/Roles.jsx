import AdminLayout from "@/Layouts/AdminLayout";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { CheckIcon, PlusIcon, XMarkIcon } from "@heroicons/react/16/solid";
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
						<TableHeader>Nombre</TableHeader>
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
							<TableCell>
								<Text>
									<Strong>{role.name}</Strong>
								</Text>
							</TableCell>
							<TableCell>
								<ul className="space-y-1">
									{Object.keys(permissionsNames)
										.filter(
											(key) =>
												key.split(".").length === 2,
										)
										.map((parentKey) => {
											const hasParentPermission =
												role.permissions.some(
													(p) => p.name === parentKey,
												);
											return (
												<li key={parentKey}>
													<div className="flex items-center space-x-2">
														<Badge color="slate">
															{hasParentPermission ? (
																<CheckIcon className="h-4 w-4 text-famedic-light" />
															) : (
																<XMarkIcon className="h-4 w-4 text-red-500" />
															)}
															{
																permissionsNames[
																	parentKey
																]
															}
														</Badge>
													</div>
													{Object.keys(
														permissionsNames,
													).filter(
														(key) =>
															key.startsWith(
																parentKey + ".",
															) &&
															key.split(".")
																.length > 2,
													).length > 0 && (
														<ul className="ml-4 mt-1 space-y-1">
															{Object.keys(
																permissionsNames,
															)
																.filter(
																	(key) =>
																		key.startsWith(
																			parentKey +
																				".",
																		) &&
																		key.split(
																			".",
																		)
																			.length >
																			2,
																)
																.map(
																	(
																		childKey,
																	) => {
																		const hasChildPermission =
																			role.permissions.some(
																				(
																					p,
																				) =>
																					p.name ===
																					childKey,
																			);
																		return (
																			<li
																				key={
																					childKey
																				}
																			>
																				<Badge color="slate">
																					{hasChildPermission ? (
																						<CheckIcon className="h-4 w-4 text-famedic-light" />
																					) : (
																						<XMarkIcon className="h-4 w-4 text-red-500" />
																					)}
																					{
																						permissionsNames[
																							childKey
																						]
																					}
																				</Badge>
																			</li>
																		);
																	},
																)}
														</ul>
													)}
												</li>
											);
										})}
								</ul>
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
