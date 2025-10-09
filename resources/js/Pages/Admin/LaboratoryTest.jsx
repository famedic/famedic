import {
	PencilIcon,
	CheckIcon,
	CheckCircleIcon,
	XCircleIcon,
	EllipsisHorizontalIcon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";
import {
	Dropdown,
	DropdownButton,
	DropdownMenu,
	DropdownItem,
} from "@/Components/Catalyst/dropdown";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";

export default function LaboratoryTest({ laboratoryTest, brands, categories }) {
	return (
		<AdminLayout title="Prueba de Laboratorio">
			<Header laboratoryTest={laboratoryTest} />

			<TestDetails laboratoryTest={laboratoryTest} />

			<PriceInformation laboratoryTest={laboratoryTest} />

			{laboratoryTest.feature_list &&
				laboratoryTest.feature_list.length > 0 && (
					<Features laboratoryTest={laboratoryTest} />
				)}

			{(laboratoryTest.indications || laboratoryTest.description) && (
				<Instructions laboratoryTest={laboratoryTest} />
			)}
		</AdminLayout>
	);
}

function Header({ laboratoryTest }) {
	return (
		<>
			<div className="flex flex-col flex-wrap gap-6 md:flex-row">
				<div className="flex-1">
					<div className="flex w-full flex-wrap justify-between gap-4">
						<div className="flex flex-wrap items-center gap-4">
							<Heading>{laboratoryTest.name}</Heading>
						</div>
						<Dropdown>
							<DropdownButton outline>
								Acciones
								<EllipsisHorizontalIcon />
							</DropdownButton>
							<DropdownMenu>
								<DropdownItem
									href={route(
										"admin.laboratory-tests.edit",
										laboratoryTest.id,
									)}
								>
									<PencilIcon />
									Editar
								</DropdownItem>
							</DropdownMenu>
						</Dropdown>
					</div>
				</div>
			</div>
		</>
	);
}

function TestDetails({ laboratoryTest }) {
	return (
		<div>
			<Subheading>Detalles de la prueba</Subheading>

			<DescriptionList>
				<DescriptionTerm>Nombre</DescriptionTerm>
				<DescriptionDetails>{laboratoryTest.name}</DescriptionDetails>

				{laboratoryTest.other_name && (
					<>
						<DescriptionTerm>Nombre alternativo</DescriptionTerm>
						<DescriptionDetails>
							{laboratoryTest.other_name}
						</DescriptionDetails>
					</>
				)}

				<DescriptionTerm>Categoría</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryTest.laboratory_test_category.name}
				</DescriptionDetails>

				<DescriptionTerm>Marca</DescriptionTerm>
				<DescriptionDetails>
					<LaboratoryBrandCard
						src={
							"/images/gda/GDA-" +
							laboratoryTest.brand.toUpperCase() +
							".png"
						}
						className="w-36 p-4"
					/>
				</DescriptionDetails>

				<DescriptionTerm>ID GDA</DescriptionTerm>
				<DescriptionDetails>{laboratoryTest.gda_id}</DescriptionDetails>

				<DescriptionTerm>Requiere cita</DescriptionTerm>
				<DescriptionDetails>
					<Badge
						color={
							laboratoryTest.requires_appointment
								? "famedic-lime"
								: "slate"
						}
					>
						{laboratoryTest.requires_appointment ? (
							<CheckCircleIcon className="size-4" />
						) : (
							<XCircleIcon className="size-4" />
						)}
						{laboratoryTest.requires_appointment ? "Sí" : "No"}
					</Badge>
				</DescriptionDetails>

				{laboratoryTest.elements && (
					<>
						<DescriptionTerm>Elementos</DescriptionTerm>
						<DescriptionDetails>
							{laboratoryTest.elements}
						</DescriptionDetails>
					</>
				)}

				{laboratoryTest.common_use && (
					<>
						<DescriptionTerm>Uso común</DescriptionTerm>
						<DescriptionDetails>
							{laboratoryTest.common_use}
						</DescriptionDetails>
					</>
				)}
			</DescriptionList>
		</div>
	);
}

function PriceInformation({ laboratoryTest }) {
	return (
		<div>
			<Subheading>Información de precios</Subheading>

			<DescriptionList>
				<DescriptionTerm>Precio público</DescriptionTerm>
				<DescriptionDetails>
					<Text className="line-through">
						{laboratoryTest.formatted_public_price}
					</Text>
				</DescriptionDetails>

				<DescriptionTerm>Precio Famedic</DescriptionTerm>
				<DescriptionDetails>
					<Text>
						<Strong>
							{laboratoryTest.formatted_famedic_price}
						</Strong>
					</Text>
				</DescriptionDetails>

				<DescriptionTerm>Descuento</DescriptionTerm>
				<DescriptionDetails>
					<Badge color="green">
						{Math.round(
							((laboratoryTest.public_price_cents -
								laboratoryTest.famedic_price_cents) /
								laboratoryTest.public_price_cents) *
								100,
						)}
						% de descuento
					</Badge>
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function Features({ laboratoryTest }) {
	return (
		<div>
			<Subheading>Características incluidas</Subheading>

			<div className="grid gap-2 sm:grid-cols-2">
				{laboratoryTest.feature_list.map((feature, index) => (
					<div key={index} className="flex items-center gap-2">
						<CheckIcon className="size-4 fill-green-600" />
						<Text>{feature}</Text>
					</div>
				))}
			</div>
		</div>
	);
}

function Instructions({ laboratoryTest }) {
	return (
		<div>
			<Subheading>Instrucciones</Subheading>

			<DescriptionList>
				{laboratoryTest.description && (
					<>
						<DescriptionTerm>Descripción</DescriptionTerm>
						<DescriptionDetails>
							<span className="block max-w-2xl whitespace-pre-wrap">
								{laboratoryTest.description}
							</span>
						</DescriptionDetails>
					</>
				)}

				{laboratoryTest.indications && (
					<>
						<DescriptionTerm>Indicaciones</DescriptionTerm>
						<DescriptionDetails>
							<span className="block max-w-2xl whitespace-pre-wrap">
								{laboratoryTest.indications}
							</span>
						</DescriptionDetails>
					</>
				)}
			</DescriptionList>
		</div>
	);
}
