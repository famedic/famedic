export default function LaboratoryTestsGrid({
	laboratoryTests,
	laboratoryTestCategories,
	laboratoryCartItems,
	search,
	category,
	updateSearch,
	laboratoryBrand,
}) {
	if (!laboratoryTests.data.length) {
		return <EmptyListCard />;
	}
	const {
		data,
		post,
		processing: storeProcessing,
	} = useForm({
		laboratory_test: "",
	});
	const [submittingId, setSubmittingId] = useState(null);

	const addLaboratoryTest = (laboratoryTest) => {
		if (!storeProcessing) {
			setSubmittingId(laboratoryTest.id);
			data.laboratory_test = laboratoryTest.id;
			post(route("laboratory-cart-items.store"), {
				preserveScroll: true,
				onFinish: () => setSubmittingId(null),
			});
		}
	};

	const {
		laboratoryCartItemToDelete,
		setLaboratoryCartItemToDelete,
		destroyLaboratoryCartItem,
		processing,
	} = useDeleteLaboratoryCartItem();

	return (
		<>
			<div className="space-y-4">
				{(!!laboratoryTests.total || category) && (
					<div className="space-y-2">
						{!!laboratoryTests.total && (
							<Text>
								Mostrando {laboratoryTests.from} a{" "}
								{laboratoryTests.to} de{" "}
								<Strong>
									{laboratoryTests.total.toLocaleString()}{" "}
									resultados
								</Strong>
								{search && ` de busqueda "${search}"`}
							</Text>
						)}
						{category && (
							<BadgeButton
								color="sky"
								onClick={() => updateSearch(search, "")}
							>
								<TagIcon className="size-4" />
								<span className="flex items-center">
									{category}
									<XMarkIcon className="size-5 fill-red-700 dark:fill-red-300" />
								</span>
							</BadgeButton>
						)}
					</div>
				)}
				<div className="grid gap-6 md:grid-cols-2 lg:gap-8 xl:grid-cols-3">
					{laboratoryTests.data.map((laboratoryTest) => {
						return (
							<ProductCard
								key={laboratoryTest.id}
								heading={laboratoryTest.name}
								tags={[
									laboratoryTest.requires_appointment && {
										icon: InformationCircleIcon,
										label: " Requiere cita",
										color: "sky",
									},
									laboratoryTestCategories.find(
										(category) =>
											category.id ===
											laboratoryTest.laboratory_test_category_id,
									)?.name && {
										icon: TagIcon,
										label: laboratoryTestCategories.find(
											(category) =>
												category.id ===
												laboratoryTest.laboratory_test_category_id,
										).name,
									},
								].filter(Boolean)}
								description={
									laboratoryTest.description ||
									laboratoryTest.indications
								}
								inCartHref={
									laboratoryCartItems.filter(
										(laboratoryCartItem) =>
											laboratoryCartItem.laboratory_test_id ===
											laboratoryTest.id,
									).length > 0
										? route("laboratory.shopping-cart", {
												laboratory_brand:
													laboratoryBrand.value,
											})
										: false
								}
								price={laboratoryTest.formatted_famedic_price}
								discountedPrice={
									laboratoryTest.formatted_public_price
								}
								discountPercentage={Math.round(
									((laboratoryTest.public_price_cents -
										laboratoryTest.famedic_price_cents) /
										laboratoryTest.public_price_cents) *
										100,
								)}
								onRemoveClick={() =>
									setLaboratoryCartItemToDelete(
										laboratoryCartItems.find(
											(laboratoryCartItem) =>
												laboratoryCartItem.laboratory_test_id ==
												laboratoryTest.id,
										),
									)
								}
								onAddClick={() =>
									addLaboratoryTest(laboratoryTest)
								}
								features={laboratoryTest.feature_list || []}
								processing={submittingId === laboratoryTest.id}
								otherName={laboratoryTest.other_name}
								elements={laboratoryTest.elements}
								commonUse={laboratoryTest.common_use}
							/>
						);
					})}
				</div>
				<DeleteConfirmationModal
					isOpen={!!laboratoryCartItemToDelete}
					close={() => setLaboratoryCartItemToDelete(null)}
					title="Eliminar del carrito"
					description={`¿Estás seguro de que deseas eliminar ${laboratoryCartItemToDelete?.laboratory_test.name} del carrito?`}
					processing={processing}
					destroy={destroyLaboratoryCartItem}
				/>
			</div>

			{laboratoryTests.total > 1 && (
				<Pagination paginatedModels={laboratoryTests} />
			)}
		</>
	);
}

import ProductCard from "@/Components/ProductCard";
import { BadgeButton } from "@/Components/Catalyst/badge";
import { Strong, Text } from "@/Components/Catalyst/text";
import { TagIcon, XMarkIcon } from "@heroicons/react/16/solid";
import { InformationCircleIcon } from "@heroicons/react/20/solid";
import { useForm } from "@inertiajs/react";
import { useState } from "react";
import { useDeleteLaboratoryCartItem } from "@/Hooks/useDeleteLaboratoryCartItem";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import EmptyListCard from "@/Components/EmptyListCard";
import Pagination from "@/Components/Pagination";
