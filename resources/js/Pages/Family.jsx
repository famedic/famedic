import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import { PlusIcon, QrCodeIcon } from "@heroicons/react/16/solid";
import { PencilIcon, TrashIcon } from "@heroicons/react/24/outline";
import FamilyAccountForm from "@/Pages/Family/FamilyAccountForm";
import FamilyAccountDeleteConfirmation from "@/Pages/Family/FamilyAccountDeleteConfirmation";
import { useState } from "react";
import SettingsCard from "@/Components/SettingsCard";

export default function Family({ familyAccounts }) {
	const familyAccountFormIsOpen =
		route().current("family.create") || route().current("family.edit");

	const [familyAccountToDelete, setFamilyAccountToDelete] = useState(null);

	return (
		<SettingsLayout title="Mi familia">
			<div className="flex flex-wrap items-center justify-between gap-4">
				<GradientHeading noDivider>Mi familia</GradientHeading>

				<Button
					preserveState
					preserveScroll
					href={route("family.create")}
				>
					<PlusIcon />
					Agregar familiar
				</Button>
			</div>

			<Divider className="my-10 mt-6" />

			<FamilyList
				familyAccounts={familyAccounts}
				setFamilyAccountToDelete={setFamilyAccountToDelete}
			/>

			<FamilyAccountForm isOpen={familyAccountFormIsOpen} />

			<FamilyAccountDeleteConfirmation
				isOpen={!!familyAccountToDelete}
				close={() => setFamilyAccountToDelete(null)}
				familyAccount={familyAccountToDelete}
			/>
		</SettingsLayout>
	);
}

function FamilyList({ familyAccounts, setFamilyAccountToDelete }) {
	return (
		<ul className="flex flex-wrap gap-8">
			{familyAccounts.map((familyAccount) => (
				<SettingsCard
					key={familyAccount.id}
					actions={
						<>
							<Button
								dusk={`deleteFamilyAccount-${familyAccount.id}`}
								onClick={() =>
									setFamilyAccountToDelete(familyAccount)
								}
								outline
							>
								<TrashIcon className="stroke-red-400" />
								Eliminar
							</Button>
							<Button
								outline
								dusk={`editFamilyAccount-${familyAccount.id}`}
								preserveState
								preserveScroll
								href={route("family.edit", {
									family_account: familyAccount,
								})}
							>
								<PencilIcon />
								Editar
							</Button>
						</>
					}
				>
					<div className="grid grid-cols-2 gap-2">
						<Subheading>{familyAccount.full_name}</Subheading>
						<div className="flex h-min justify-end">
							<Badge color="sky">
								<QrCodeIcon className="size-4" />
								<span className="font-mono font-semibold">
									{
										familyAccount.customer
											.medical_attention_identifier
									}
								</span>
							</Badge>
						</div>
					</div>
					<div className="mt-1 flex flex-wrap gap-2">
						<Badge color="slate">
							{familyAccount.formatted_kinship}
						</Badge>
						{familyAccount.formatted_gender && (
							<Badge color="slate">
								{familyAccount.formatted_gender}
							</Badge>
						)}
						{familyAccount.formatted_birth_date && (
							<Badge color="slate">
								{familyAccount.formatted_birth_date}
							</Badge>
						)}
					</div>
				</SettingsCard>
			))}

			{familyAccounts.length === 0 && (
				<SettingsCard>
					<Subheading className="mb-2">Sin familiares</Subheading>
					<Text>Aún no has agregado ningun familiar.</Text>
				</SettingsCard>
			)}
		</ul>
	);
}
