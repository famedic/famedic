import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import { PlusIcon } from "@heroicons/react/16/solid";
import { PencilIcon, TrashIcon } from "@heroicons/react/24/outline";
import AddressForm from "@/Pages/Addresses/AddressForm";
import AddressDeleteConfirmation from "@/Pages/Addresses/AddressDeleteConfirmation";
import { useState } from "react";
import SettingsCard from "@/Components/SettingsCard";

export default function Addresses({ addresses }) {
	const addressFormIsOpen =
		route().current("addresses.create") ||
		route().current("addresses.edit");

	const [addressToDelete, setAddressToDelete] = useState(null);

	return (
		<SettingsLayout title="Mis direcciones">
			<div className="flex flex-wrap items-center justify-between gap-4">
				<GradientHeading noDivider>Mis direcciones</GradientHeading>

				<Button
					dusk="createAddress"
					preserveState
					preserveScroll
					href={route("addresses.create")}
				>
					<PlusIcon />
					Agregar dirección
				</Button>
			</div>

			<Divider className="my-10 mt-6" />

			<AddressesList
				addresses={addresses}
				setAddressToDelete={setAddressToDelete}
			/>

			<AddressForm isOpen={addressFormIsOpen} />

			<AddressDeleteConfirmation
				isOpen={!!addressToDelete}
				close={() => setAddressToDelete(null)}
				address={addressToDelete}
			/>
		</SettingsLayout>
	);
}

function AddressesList({ addresses, setAddressToDelete }) {
	return (
		<ul className="flex flex-wrap gap-8">
			{addresses.map((address) => (
				<SettingsCard
					key={address.id}
					actions={
						<>
							<Button
								dusk={`deleteAddress-${address.id}`}
								onClick={() => setAddressToDelete(address)}
								outline
							>
								<TrashIcon className="stroke-red-400" />
								Eliminar
							</Button>
							<Button
								outline
								dusk={`editAddress-${address.id}`}
								preserveState
								preserveScroll
								href={route("addresses.edit", { address })}
							>
								<PencilIcon />
								Editar
							</Button>
						</>
					}
				>
					<Subheading>
						{address.street} {address.number}
					</Subheading>
					<Text>
						{address.neighborhood}, {address.zipcode}
					</Text>
					<Text>{`${address.state}, ${address.city} `}</Text>

					{address.additional_references && (
						<Text className="mt-2">
							<span className="text-xs">
								{address.additional_references}
							</span>
						</Text>
					)}
				</SettingsCard>
			))}

			{addresses.length === 0 && (
				<SettingsCard>
					<Subheading className="mb-2">Sin direcciones</Subheading>
					<Text>Aún no has agregado ninguna dirección.</Text>
				</SettingsCard>
			)}
		</ul>
	);
}
