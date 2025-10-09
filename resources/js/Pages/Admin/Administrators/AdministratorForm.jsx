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
import { Switch, SwitchField } from "@/Components/Catalyst/switch";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	Checkbox,
	CheckboxField,
	CheckboxGroup,
} from "@/Components/Catalyst/checkbox";
import { useForm } from "@inertiajs/react";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function AdministratorForm({ administrator, roles }) {
	const editMode = route().current("admin.administrators.edit");

	const { data, setData, post, put, processing, errors } = useForm({
		name: administrator?.user?.name ?? "",
		paternal_lastname: administrator?.user?.paternal_lastname ?? "",
		maternal_lastname: administrator?.user?.maternal_lastname ?? "",
		email: administrator?.user?.email ?? "",
		roles: administrator?.roles?.map((permission) => permission.name) ?? [],
		has_laboratory_concierge_account: administrator?.laboratory_concierge
			? true
			: false,
	});

	const handleRoleChange = (roleName) => {
		const updatedRoles = data.roles.includes(roleName)
			? data.roles.filter((role) => role !== roleName)
			: [...data.roles, roleName];
		setData("roles", updatedRoles);
	};

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			if (editMode) {
				put(
					route("admin.administrators.update", {
						administrator: administrator,
					}),
					{
						preserveScroll: true,
					},
				);
			} else {
				post(route("admin.administrators.store"));
			}
		}
	};

	return (
		<form onSubmit={submit} className="grid gap-8 sm:grid-cols-2">
			<div className="space-y-1">
				<Subheading>Información sobre el administrador</Subheading>
				<Text>
					Configura los datos clave del administrador y define los
					roles que determinarán sus permisos y responsabilidades. Si
					activas la opción de concierge de laboratorio, el usuario
					podrá gestionar y supervisar las citas de laboratorio.
				</Text>
			</div>

			<Fieldset>
				<FieldGroup>
					<Field>
						<Label>Nombre</Label>
						<Input
							autoFocus
							dusk="name"
							type="text"
							required
							value={data.name}
							onChange={(e) => setData("name", e.target.value)}
						/>
						{errors.name && (
							<ErrorMessage>{errors.name}</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Apellido paterno</Label>
						<Input
							dusk="paternal_lastname"
							type="text"
							required
							value={data.paternal_lastname}
							onChange={(e) =>
								setData("paternal_lastname", e.target.value)
							}
						/>
						{errors.paternal_lastname && (
							<ErrorMessage>
								{errors.paternal_lastname}
							</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Apellido materno</Label>
						<Input
							dusk="maternal_lastname"
							type="text"
							required
							value={data.maternal_lastname}
							onChange={(e) =>
								setData("maternal_lastname", e.target.value)
							}
						/>
						{errors.maternal_lastname && (
							<ErrorMessage>
								{errors.maternal_lastname}
							</ErrorMessage>
						)}
					</Field>
					<Field>
						<Label>Correo electrónico</Label>
						<Input
							dusk="email"
							type="text"
							required
							value={data.email}
							onChange={(e) => setData("email", e.target.value)}
						/>
						{errors.email && (
							<ErrorMessage>{errors.email}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Roles</Label>
						<Description>
							Los roles determinan los permisos que tendrá el
							administrador.
						</Description>
						<CheckboxGroup>
							{roles.map((role) => (
								<CheckboxField key={role.id}>
									<Checkbox
										name={`role-${role.id}`}
										dusk={`role-${role.id}`}
										checked={data.roles.includes(role.name)}
										onChange={() =>
											handleRoleChange(role.name)
										}
									/>
									<Label>{role.name}</Label>
								</CheckboxField>
							))}
						</CheckboxGroup>
						{errors.roles && (
							<ErrorMessage>{errors.roles}</ErrorMessage>
						)}
					</Field>

					<SwitchField>
						<Label>¿El usuario es concierge de laboratorio?</Label>
						<Description>
							Si el usuario es concierge de laboratorio, tendrá
							acceso y control de las citas de laboratorio.
						</Description>
						<Switch
							dusk="hasLaboratoryConciergeAccount"
							checked={data.has_laboratory_concierge_account}
							onChange={(value) =>
								setData(
									"has_laboratory_concierge_account",
									value,
								)
							}
						/>
						{errors.has_laboratory_concierge_account && (
							<ErrorMessage>
								{errors.has_laboratory_concierge_account}
							</ErrorMessage>
						)}
					</SwitchField>
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
