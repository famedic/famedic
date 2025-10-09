import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import { CalendarIcon, PhoneIcon, PlusIcon } from "@heroicons/react/16/solid";
import { PencilIcon, TrashIcon } from "@heroicons/react/24/outline";
import ContactForm from "@/Pages/Contacts/ContactForm";
import ContactDeleteConfirmation from "@/Pages/Contacts/ContactDeleteConfirmation";
import { useState } from "react";
import SettingsCard from "@/Components/SettingsCard";

export default function Contacts({ contacts }) {
	const contactFormIsOpen =
		route().current("contacts.create") || route().current("contacts.edit");

	const [contactToDelete, setContactToDelete] = useState(null);

	return (
		<SettingsLayout title="Mis pacientes frecuentes">
			<div className="flex flex-wrap items-center justify-between gap-4">
				<GradientHeading noDivider>
					Mis pacientes frecuentes
				</GradientHeading>

				<Button
					dusk="createContact"
					preserveState
					preserveScroll
					href={route("contacts.create")}
				>
					<PlusIcon />
					Agregar paciente
				</Button>
			</div>

			<Divider className="my-10 mt-6" />

			<ContactsList
				contacts={contacts}
				setContactToDelete={setContactToDelete}
			/>

			<ContactForm isOpen={contactFormIsOpen} />

			<ContactDeleteConfirmation
				isOpen={!!contactToDelete}
				close={() => setContactToDelete(null)}
				contact={contactToDelete}
			/>
		</SettingsLayout>
	);
}

function ContactsList({ contacts, setContactToDelete }) {
	return (
		<ul className="flex flex-wrap gap-8">
			{contacts.map((contact) => (
				<SettingsCard
					key={contact.id}
					actions={
						<>
							<Button
								dusk={`deleteContact-${contact.id}`}
								onClick={() => setContactToDelete(contact)}
								outline
							>
								<TrashIcon className="stroke-red-400" />
								Eliminar
							</Button>
							<Button
								outline
								dusk={`editContact-${contact.id}`}
								preserveState
								preserveScroll
								href={route("contacts.edit", { contact })}
							>
								<PencilIcon />
								Editar
							</Button>
						</>
					}
				>
					<Subheading>{contact.full_name}</Subheading>
					<Text className="flex items-center gap-2">
						<PhoneIcon className="size-4" />
						{contact.phone}
					</Text>
					<Text className="flex items-center gap-2">
						<CalendarIcon className="size-4" />
						{contact.formatted_birth_date}
					</Text>
					<Badge color="slate" className="mt-1">
						{contact.formatted_gender}
					</Badge>
				</SettingsCard>
			))}

			{contacts.length === 0 && (
				<SettingsCard>
					<Subheading className="mb-2">Sin pacientes</Subheading>
					<Text>Aún no has agregado ningun paciente frecuente.</Text>
				</SettingsCard>
			)}
		</ul>
	);
}
