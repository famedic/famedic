export default function LaboratoryShoppingCart({
	laboratoryBrand,
	laboratoryCarts,
	formattedTotal,
	formattedSubtotal,
	formattedDiscount,
}) {
	const {
		laboratoryCartItemToDelete,
		setLaboratoryCartItemToDelete,
		destroyLaboratoryCartItem,
		processing,
	} = useDeleteLaboratoryCartItem();

	return (
		<>
			<ShoppingCartLayout
				title="Carrito de laboratorio"
				header={
					<div className="flex flex-col gap-6 sm:flex-row">
						<LaboratoryBrandCard
							src={`/images/gda/${laboratoryBrand.imageSrc}`}
							className="w-60 p-4"
						/>

						<GradientHeading noDivider>
							Carrito de laboratorio
						</GradientHeading>
					</div>
				}
				items={laboratoryCarts[laboratoryBrand.value].map(
					(laboratoryCartItem) => ({
						heading: laboratoryCartItem.laboratory_test.name,
						description:
							laboratoryCartItem.laboratory_test.description,
						indications:
							laboratoryCartItem.laboratory_test.indications,
						features:
							laboratoryCartItem.laboratory_test.feature_list,
						price: laboratoryCartItem.laboratory_test
							.formatted_famedic_price,
						discountedPrice:
							laboratoryCartItem.laboratory_test
								.formatted_public_price,
						discountPercentage: Math.round(
							((laboratoryCartItem.laboratory_test
								.public_price_cents -
								laboratoryCartItem.laboratory_test
									.famedic_price_cents) /
								laboratoryCartItem.laboratory_test
									.public_price_cents) *
								100,
						),
						showDefaultImage: false,
						...(laboratoryCartItem.laboratory_test
							.requires_appointment
							? { infoMessage: "Requiere cita" }
							: {}),
						onDestroy: () =>
							setLaboratoryCartItemToDelete(laboratoryCartItem),
					}),
				)}
				emptyItemsContent={
					<>
						<Subheading>No hay estudios en tu carrito</Subheading>
						<Text className="w-full">
							Te invitamos a{" "}
							<TextLink
								href={route("laboratory-tests", {
									laboratory_brand: laboratoryBrand.value,
								})}
							>
								explorar los estudios de {laboratoryBrand.name}
							</TextLink>{" "}
						</Text>
					</>
				}
				summaryDetails={[
					{ value: formattedSubtotal, label: "Subtotal" },
					{ value: "-" + formattedDiscount, label: "Descuento" },
					{ value: formattedTotal, label: "Total" },
				]}
				summaryInfoMessage={
					laboratoryCarts[laboratoryBrand.value]?.filter(
						(laboratoryCartItem) =>
							laboratoryCartItem.laboratory_test
								.requires_appointment,
					).length > 0
						? {
								title: "Necesitarás una cita",
								message:
									"Algunos estudios requieren cita para asegurar que la sucursal cuente con el equipo necesario y que se cumplan todos los requisitos. Esto garantiza un servicio preciso y de calidad.",
							}
						: {}
				}
				checkoutUrl={route("laboratory.checkout", {
					laboratory_brand: laboratoryBrand.value,
				})}
			>
				<Divider className="sm:mb-18 mb-12 mt-12 lg:mb-24" />

				<FeaturesGrid
					features={[
						{
							name: "Precios exclusivos",
							icon: CurrencyDollarIcon,
							description:
								"Obtén todos tus estudios a precios verdaderamente preferenciales.",
						},
						{
							name: "Garantía y seguridad",
							icon: LockClosedIcon,
							description:
								"Tus compras están seguras. Si no recibes el servicio o producto, tendrás una devolución total.",
						},
						{
							name: "Facturación sencilla",
							icon: DocumentCheckIcon,
							description:
								"Con tus perfiles fiscales, es muy fácil solicitar tus facturas.",
						},
						{
							name: "Historial de compras y resultados",
							icon: QueueListIcon,
							description:
								"Consulta todas tus compras, facturas y resultados de laboratorios.",
						},
					]}
				/>
			</ShoppingCartLayout>

			<DeleteConfirmationModal
				isOpen={!!laboratoryCartItemToDelete}
				close={() => setLaboratoryCartItemToDelete(null)}
				title="Quitar del carrito"
				description={`¿Estás seguro de que deseas quitarlo ${laboratoryCartItemToDelete?.laboratory_test.name} del carrito?`}
				processing={processing}
				destroy={destroyLaboratoryCartItem}
			/>
		</>
	);
}

import ShoppingCartLayout from "@/Layouts/ShoppingCartLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import FeaturesGrid from "@/Components/FeaturesGrid";
import { useDeleteLaboratoryCartItem } from "@/Hooks/useDeleteLaboratoryCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import {
	CurrencyDollarIcon,
	DocumentCheckIcon,
	LockClosedIcon,
	QueueListIcon,
} from "@heroicons/react/24/solid";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
