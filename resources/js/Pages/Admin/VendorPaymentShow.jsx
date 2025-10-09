import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Anchor, Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Dropdown,
	DropdownButton,
	DropdownItem,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import {
	ArrowTopRightOnSquareIcon,
	BanknotesIcon,
	PencilIcon,
} from "@heroicons/react/16/solid";
import { TrashIcon, EllipsisHorizontalIcon } from "@heroicons/react/24/outline";
import LaboratoryPurchaseTableRow from "@/Components/LaboratoryPurchaseTableRow";
import OnlinePharmacyPurchaseTableRow from "@/Components/OnlinePharmacyPurchaseTableRow";
import { useForm } from "@inertiajs/react";
import { useState } from "react";

export default function VendorPaymentShow({ vendorPayment, purchases }) {
	const isLaboratory = route().current().includes("laboratory-purchases");
	const vendorName = isLaboratory ? "GDA" : "Vitau";
	const [openDeleteConfirmation, setOpenDeleteConfirmation] = useState(false);
	const { delete: destroy, processing } = useForm({});

	const deleteVendorPayment = () => {
		if (!processing) {
			const routeName = isLaboratory
				? "admin.laboratory-purchases.vendor-payments.destroy"
				: "admin.online-pharmacy-purchases.vendor-payments.destroy";

			destroy(route(routeName, { vendor_payment: vendorPayment }));
		}
	};

	return (
		<AdminLayout title={`Pago a ${vendorName}`}>
			<div className="flex items-center justify-between gap-2">
				<Heading>Pago a {vendorName}</Heading>

				<Dropdown>
					<DropdownButton outline>
						Acciones
						<EllipsisHorizontalIcon />
					</DropdownButton>
					<DropdownMenu>
						<DropdownItem
							href={route(
								isLaboratory
									? "admin.laboratory-purchases.vendor-payments.edit"
									: "admin.online-pharmacy-purchases.vendor-payments.edit",
								{ vendor_payment: vendorPayment },
							)}
						>
							<PencilIcon />
							Editar pago a proveedor
						</DropdownItem>
						<DropdownItem
							onClick={() => setOpenDeleteConfirmation(true)}
						>
							<TrashIcon />
							Eliminar
						</DropdownItem>
					</DropdownMenu>
				</Dropdown>
			</div>
			<Code>{vendorPayment.formatted_paid_at}</Code>

			<div className="flex flex-wrap items-center gap-2">
				<Badge>
					<BanknotesIcon className="size-5" />
					Monto esperado de {vendorPayment.formatted_total}
				</Badge>
				<Anchor
					href={route("vendor-payment", vendorPayment)}
					target="_blank"
				>
					<Button outline>
						Ver comprobante de pago
						<ArrowTopRightOnSquareIcon />
					</Button>
				</Anchor>
			</div>
			<div className="mt-4"></div>
			<div className="mt-6 space-y-4">
				<Subheading>Órdenes contempladas en el pago</Subheading>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Detalles</TableHeader>
							<TableHeader>
								{isLaboratory ? "Paciente" : "Cliente"}
							</TableHeader>
							{isLaboratory && <TableHeader>Marca</TableHeader>}
							<TableHeader className="text-right">
								Detalles adicionales
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{purchases.map((purchase) =>
							isLaboratory ? (
								<LaboratoryPurchaseTableRow
									key={purchase.id}
									laboratoryPurchase={purchase}
									showBrand={true}
								/>
							) : (
								<OnlinePharmacyPurchaseTableRow
									key={purchase.id}
									onlinePharmacyPurchase={purchase}
								/>
							),
						)}
					</TableBody>
				</Table>
			</div>

			<DeleteConfirmationModal
				isOpen={!!openDeleteConfirmation}
				close={() => setOpenDeleteConfirmation(false)}
				title="Eliminar pago a proveedor"
				description="¿Estás seguro de que deseas eliminar este pago a proveedor? Esta acción eliminará el comprobante de pago del almacenamiento."
				processing={processing}
				destroy={deleteVendorPayment}
			/>
		</AdminLayout>
	);
}
