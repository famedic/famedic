import { Button } from "@/Components/Catalyst/button";
import {
	Field,
	Description,
	Label,
	ErrorMessage,
	FieldGroup,
	Fieldset,
} from "@/Components/Catalyst/fieldset";
import { Subheading } from "@/Components/Catalyst/heading";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	Checkbox,
	CheckboxField,
	CheckboxGroup,
} from "@/Components/Catalyst/checkbox";
import { usePage, useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function RoleForm() {
	const { role, permissions, permissionsNames } = usePage().props;
	const editMode = route().current("admin.roles.edit");

	const { data, setData, post, put, processing, errors } = useForm({
		name: role?.name ?? "",
		permissions: role?.permissions
			? role.permissions.map((permission) => permission.name)
			: [],
	});

	const handlePermissionChange = (permissionName) => {
		const updatedPermissions = data.permissions.includes(permissionName)
			? data.permissions.filter((perm) => perm !== permissionName)
			: [...data.permissions, permissionName];
		setData("permissions", updatedPermissions);
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
		<form onSubmit={submit} className="grid gap-8 sm:grid-cols-2">
			<div className="space-y-1">
				<Subheading>Información sobre el rol</Subheading>
				<Text>
					Establece el nombre del rol y los permisos que tendrán los
					usuarios que tengan asignado este rol.
				</Text>
			</div>

			<Fieldset>
				<FieldGroup>
					<Field>
						<Label>Nombre</Label>
						<Input
							autoFocus
							dusk="name"
							required
							type="text"
							value={data.name}
							onChange={(e) => setData("name", e.target.value)}
						/>
						{errors.name && (
							<ErrorMessage>{errors.name}</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Permisos</Label>
						<Description>
							Los permisos que se le asignarán a los usuarios que
							tengan este rol.
						</Description>
						<CheckboxGroup>
							{permissions.map((permission) => (
								<div key={permission.id}>
									<CheckboxField>
										<Checkbox
											name={`permission-${permission.id}`}
											dusk={`permission-${permission.id}`}
											checked={data.permissions.includes(
												permission.name,
											)}
											onChange={() =>
												handlePermissionChange(
													permission.name,
												)
											}
										/>
										<Label>
											{permissionsNames[permission.name]}
										</Label>
									</CheckboxField>
									{permission.all_permissions &&
										permission.all_permissions.length >
											0 && (
											<div className="ml-8 mt-1">
												{permission.all_permissions.map(
													(child) => (
														<CheckboxField
															key={child.id}
															disabled={
																!data.permissions.includes(
																	permission.name,
																)
															}
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
															<Label>
																{
																	permissionsNames[
																		child
																			.name
																	]
																}
															</Label>
														</CheckboxField>
													),
												)}
											</div>
										)}
								</div>
							))}
						</CheckboxGroup>
						{errors.permissions && (
							<ErrorMessage>{errors.permissions}</ErrorMessage>
						)}
					</Field>
				</FieldGroup>
			</Fieldset>

			<div className="flex justify-end sm:col-span-2">
				<Button
					dusk="save"
					type="submit"
					disabled={processing}
					className="w-full sm:w-auto"
				>
					Guardar
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</div>
		</form>
	);
}
