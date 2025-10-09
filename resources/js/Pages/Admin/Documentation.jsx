import AdminLayout from "@/Layouts/AdminLayout";
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@headlessui/react";
import { Divider } from "@/Components/Catalyst/divider";
import { Heading } from "@/Components/Catalyst/heading";
import DocumentationForm from "@/Pages/Admin/Documentation/DocumentationForm";
import { Fragment } from "react";
import { BadgeButton } from "@/Components/Catalyst/badge";
import { DocumentTextIcon, PencilIcon } from "@heroicons/react/16/solid";

const documents = [
	{
		field: "privacy_policy",
		label: "Política de privacidad",
		previewRouteName: "privacy-policy",
	},
	{
		field: "terms_of_service",
		label: "Términos y condiciones de servicio",
		previewRouteName: "terms-of-service",
	},
];

export default function Documentation({ documentation }) {
	return (
		<AdminLayout title="Documentación legal">
			<Heading className="mb-6">Documentación legal</Heading>

			<TabGroup>
				<TabList className="flex gap-2">
					{documents.map((document) => (
						<Tab key={document.field} as={Fragment}>
							{({ selected }) => (
								<BadgeButton
									color={selected ? "famedic" : "slate"}
								>
									{selected ? (
										<PencilIcon className="size-5" />
									) : (
										<DocumentTextIcon className="size-5" />
									)}
									{document.label}
								</BadgeButton>
							)}
						</Tab>
					))}
				</TabList>

				<Divider className="my-4" />

				<TabPanels>
					{documents.map((document) => (
						<TabPanel key={document.field}>
							<DocumentationForm
								document={document}
								initialMarkdown={documentation[document.field]}
							/>
						</TabPanel>
					))}
				</TabPanels>
			</TabGroup>
		</AdminLayout>
	);
}
