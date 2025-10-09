export default function OnlinePharmacyShoppingCart({
	onlinePharmacyCart,
	formattedSubtotal,
}) {
	const {
		onlinePharmacyCartItemToDelete,
		setOnlinePharmacyCartItemToDelete,
		destroyOnlinePharmacyCartItem,
		processing,
	} = useDeleteOnlinePharmacyCartItem();

	return (
		<>
			<ShoppingCartLayout
				title="Carrito de farmacia"
				header={
					<GradientHeading noDivider>
						Carrito de farmacia
					</GradientHeading>
				}
				items={onlinePharmacyCart.map((onlinePharmacyCartItem) => ({
					heading: onlinePharmacyCartItem.vitau_product.base.name,
					description:
						onlinePharmacyCartItem.vitau_product.presentation,
					price: onlinePharmacyCartItem.formatted_price,
					imgSrc:
						onlinePharmacyCartItem.vitau_product.default_image ||
						null,
					quantity: onlinePharmacyCartItem.quantity,
					onDestroy: () =>
						setOnlinePharmacyCartItemToDelete(
							onlinePharmacyCartItem,
						),
				}))}
				emptyItemsContent={
					<>
						<Subheading>No hay productos en tu carrito</Subheading>
						<Text className="w-full">
							Te invitamos a{" "}
							<TextLink href={route("online-pharmacy")}>
								explorar nuestra farmacia en línea
							</TextLink>{" "}
						</Text>
					</>
				}
				summaryDetails={[
					{ value: formattedSubtotal, label: "Subtotal" },
					{
						value: "Se calculará al seleccionar tu dirección",
						label: "Envío",
					},
					{ value: formattedSubtotal, label: "Total" },
				]}
				checkoutUrl={route("online-pharmacy.checkout")}
			>
				<Divider className="sm:mb-18 mb-12 mt-12 lg:mb-24" />

				<FeaturesGrid
					features={[
						{
							name: "Tus medicamentos a un click",
							description:
								"Todos tus medicamentos a precios justos y con entrega a domicilio (sin costo en compras de $1,500 MXN o más).",
							icon: CursorArrowRippleIcon,
						},
						{
							name: "Cobertura nacional",
							description:
								"Entregamos tus medicamentos en cualquier parte del territorio nacional",
							icon: GlobeAmericasIcon,
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
				isOpen={!!onlinePharmacyCartItemToDelete}
				close={() => setOnlinePharmacyCartItemToDelete(null)}
				title="Eliminar del carrito"
				description={`¿Estás seguro de que deseas eliminar ${onlinePharmacyCartItemToDelete?.vitau_product?.base.name} del carrito?`}
				processing={processing}
				destroy={destroyOnlinePharmacyCartItem}
			/>
		</>
	);
}

import ShoppingCartLayout from "@/Layouts/ShoppingCartLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text, TextLink } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import FeaturesGrid from "@/Components/FeaturesGrid";
import { useDeleteOnlinePharmacyCartItem } from "@/Hooks/useDeleteOnlinePharmacyCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import {
	DocumentCheckIcon,
	QueueListIcon,
	CursorArrowRippleIcon,
	GlobeAmericasIcon,
} from "@heroicons/react/24/solid";
