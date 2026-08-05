import { useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import {
	Field,
	Label,
	ErrorMessage,
	FieldGroup,
	Fieldset,
} from "@/Components/Catalyst/fieldset";
import { Subheading } from "@/Components/Catalyst/heading";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Badge } from "@/Components/Catalyst/badge";
import { usePage, useForm } from "@inertiajs/react";
import {
	ArrowPathIcon,
	MagnifyingGlassIcon,
} from "@heroicons/react/16/solid";

export default function RoleForm() {
	const { role, permissions: permissionsProp, permissionsNames } =
		usePage().props;
	const permissions = permissionsProp ?? [];
	const editMode = route().current("admin.roles.edit");
	const [query, setQuery] = useState("");

	const { data, setData, post, put, processing, errors } = useForm({
		name: role?.name ?? "",
		permissions: role?.permissions
			? role.permissions.map((permission) => permission.name)
			: [],
	});

	const permissionLabel = (permission) =>
		permissionsNames?.[permission.name] ||
		permission.formatted_name ||
		permission.name;

	const allPermissionNames = useMemo(() => {
		const names = [];

		permissions.forEach((permission) => {
			names.push(permission.name);
			(permission.all_permissions || []).forEach((child) => {
				names.push(child.name);
			});
		});

		return names;
	}, [permissions]);

	const filteredPermissions = useMemo(() => {
		const normalized = query.trim().toLowerCase();
		const labelFor = (permission) =>
			permissionsNames?.[permission.name] ||
			permission.formatted_name ||
			permission.name;

		if (!normalized) {
			return permissions;
		}

		return permissions.filter((permission) => {
			const parentMatch = labelFor(permission)
				.toLowerCase()
				.includes(normalized);
			const childMatch = (permission.all_permissions || []).some((child) =>
				labelFor(child).toLowerCase().includes(normalized),
			);

			return parentMatch || childMatch;
		});
	}, [permissions, permissionsNames, query]);

	const selectedCount = data.permissions.length;
	const totalCount = allPermissionNames.length;

	const handlePermissionChange = (permissionName) => {
		const updatedPermissions = data.permissions.includes(permissionName)
			? data.permissions.filter((perm) => perm !== permissionName)
			: [...data.permissions, permissionName];
		setData("permissions", updatedPermissions);
	};

	const handleParentChange = (permission) => {
		const childNames = (permission.all_permissions || []).map(
			(child) => child.name,
		);
		const isChecked = data.permissions.includes(permission.name);

		if (isChecked) {
			setData(
				"permissions",
				data.permissions.filter(
					(name) =>
						name !== permission.name && !childNames.includes(name),
				),
			);
			return;
		}

		setData("permissions", [...data.permissions, permission.name]);
	};

	const setGroupPermissions = (permission, enabled) => {
		const groupNames = [
			permission.name,
			...(permission.all_permissions || []).map((child) => child.name),
		];

		if (enabled) {
			setData("permissions", [
				...new Set([...data.permissions, ...groupNames]),
			]);
			return;
		}

		setData(
			"permissions",
			data.permissions.filter((name) => !groupNames.includes(name)),
		);
	};

	const selectAllVisible = () => {
		const names = [];

		filteredPermissions.forEach((permission) => {
			names.push(permission.name);
			(permission.all_permissions || []).forEach((child) => {
				names.push(child.name);
			});
		});

		setData("permissions", [...new Set([...data.permissions, ...names])]);
	};

	const clearAllVisible = () => {
		const names = new Set();

		filteredPermissions.forEach((permission) => {
			names.add(permission.name);
			(permission.all_permissions || []).forEach((child) => {
				names.add(child.name);
			});
		});

		setData(
			"permissions",
			data.permissions.filter((name) => !names.has(name)),
		);
	};

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (editMode) {
				put(route("admin.roles.update", { role: role }), {
					preserveScroll: true,
				});
			} else {
				post(route("admin.roles.store"));
			}
		}
	};

	return (
		<form onSubmit={submit} className="space-y-8 pb-24">
			<div className="grid gap-6 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)] lg:items-start">
				<div className="space-y-1">
					<Subheading>Información sobre el rol</Subheading>
					<Text>
						Establece el nombre del rol y los permisos que tendrán
						los usuarios que tengan asignado este rol.
					</Text>
				</div>

				<Fieldset>
					<FieldGroup>
						<Field className="max-w-md">
							<Label>Nombre</Label>
							<Input
								autoFocus
								dusk="name"
								required
								type="text"
								value={data.name}
								onChange={(e) =>
									setData("name", e.target.value)
								}
							/>
							{errors.name && (
								<ErrorMessage>{errors.name}</ErrorMessage>
							)}
						</Field>
					</FieldGroup>
				</Fieldset>
			</div>

			<section className="space-y-4">
				<div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
					<div className="space-y-1">
						<Subheading>Permisos</Subheading>
						<Text>
							Busca y selecciona los accesos de este rol. Los
							subpermisos dependen de su permiso padre.
						</Text>
					</div>
					<Badge color="slate">
						{selectedCount} de {totalCount} seleccionados
					</Badge>
				</div>

				<div className="flex flex-col gap-3 sm:flex-row sm:items-center">
					<div className="min-w-0 flex-1 sm:max-w-md">
						<InputGroup>
							<MagnifyingGlassIcon data-slot="icon" />
							<Input
								type="search"
								placeholder="Buscar permiso…"
								value={query}
								onChange={(e) => setQuery(e.target.value)}
								dusk="permission-search"
							/>
						</InputGroup>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button
							type="button"
							plain
							onClick={selectAllVisible}
							dusk="select-all-permissions"
						>
							Seleccionar visibles
						</Button>
						<Button
							type="button"
							plain
							onClick={clearAllVisible}
							dusk="clear-visible-permissions"
						>
							Limpiar visibles
						</Button>
					</div>
				</div>

				{filteredPermissions.length === 0 ? (
					<div className="rounded-lg border border-dashed border-zinc-300 px-4 py-10 text-center dark:border-zinc-700">
						<Text>
							No hay permisos que coincidan con “{query}”.
						</Text>
					</div>
				) : (
					<div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
						{filteredPermissions.map((permission) => {
							const children = permission.all_permissions || [];
							const parentChecked = data.permissions.includes(
								permission.name,
							);
							const selectedInGroup =
								(parentChecked ? 1 : 0) +
								children.filter((child) =>
									data.permissions.includes(child.name),
								).length;
							const totalInGroup = 1 + children.length;
							const groupFullySelected =
								selectedInGroup === totalInGroup;

							return (
								<div
									key={permission.id}
									className="rounded-lg border border-zinc-200 bg-zinc-50/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
								>
									<div className="flex items-start justify-between gap-3">
										<CheckboxField className="min-w-0 flex-1">
											<Checkbox
												name={`permission-${permission.id}`}
												dusk={`permission-${permission.id}`}
												checked={parentChecked}
												onChange={() =>
													handleParentChange(
														permission,
													)
												}
											/>
											<Label className="min-w-0 break-words font-medium">
												{permissionLabel(permission)}
											</Label>
										</CheckboxField>
										{children.length > 0 && (
											<button
												type="button"
												className="shrink-0 text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
												onClick={() =>
													setGroupPermissions(
														permission,
														!groupFullySelected,
													)
												}
											>
												{groupFullySelected
													? "Quitar grupo"
													: "Todo el grupo"}
											</button>
										)}
									</div>

									{children.length > 0 && (
										<div className="mt-3 space-y-2 border-t border-zinc-200/80 pt-3 dark:border-zinc-800">
											{children.map((child) => (
												<CheckboxField
													key={child.id}
													disabled={!parentChecked}
													className="min-w-0"
												>
													<Checkbox
														name={`permission-${child.id}`}
														dusk={`permission-${child.id}`}
														checked={data.permissions.includes(
															child.name,
														)}
														onChange={() =>
															handlePermissionChange(
																child.name,
															)
														}
													/>
													<Label className="min-w-0 break-words text-sm">
														{permissionLabel(child)}
													</Label>
												</CheckboxField>
											))}
										</div>
									)}
								</div>
							);
						})}
					</div>
				)}

				{errors.permissions && (
					<p className="text-sm/6 text-red-600 dark:text-red-500">
						{errors.permissions}
					</p>
				)}
			</section>

			<div className="sticky bottom-0 z-10 -mx-6 border-t border-zinc-200 bg-white/95 px-6 py-4 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:border-zinc-800 dark:bg-slate-950/95 lg:-mx-10 lg:px-10">
				<div className="mx-auto flex max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
					<Text className="text-sm text-zinc-500">
						{selectedCount} permiso
						{selectedCount === 1 ? "" : "s"} seleccionado
						{selectedCount === 1 ? "" : "s"}
					</Text>
					<Button
						dusk="save"
						type="submit"
						disabled={processing}
						className="w-full sm:w-auto"
					>
						Guardar
						{processing && (
							<ArrowPathIcon className="animate-spin" />
						)}
					</Button>
				</div>
			</div>
		</form>
	);
}
