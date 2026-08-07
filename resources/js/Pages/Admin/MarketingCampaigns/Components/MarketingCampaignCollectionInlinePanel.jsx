import { useState } from "react";
import * as Headless from "@headlessui/react";
import axios from "axios";
import { XMarkIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import MarketingCampaignCollectionForm from "./MarketingCampaignCollectionForm";

const EMPTY_FORM = {
	name: "",
	public_title: "",
	public_description: "",
	laboratory_brand: "",
	is_active: true,
	laboratory_test_ids: [],
};

export default function MarketingCampaignCollectionInlinePanel({
	open,
	onClose,
	campaign,
	brands = {},
	productSearchUrl,
	maxCollectionItems = 50,
	onCreated,
}) {
	const [data, setDataState] = useState(EMPTY_FORM);
	const [selectedItems, setSelectedItems] = useState([]);
	const [errors, setErrors] = useState({});
	const [processing, setProcessing] = useState(false);

	const setData = (key, value) => {
		if (typeof key === "object") {
			setDataState((prev) => ({ ...prev, ...key }));
			return;
		}
		setDataState((prev) => ({ ...prev, [key]: value }));
	};

	const resetForm = () => {
		setDataState(EMPTY_FORM);
		setSelectedItems([]);
		setErrors({});
	};

	const submit = async (event, { returnToCampaign = false } = {}) => {
		event.preventDefault();
		if (processing || !campaign?.id) return;

		setProcessing(true);
		setErrors({});

		try {
			const response = await axios.post(
				route("admin.marketing-campaigns.collections.store", {
					marketing_campaign: campaign.id,
				}),
				{
					...data,
					public_title:
						data.public_title?.trim() || data.name?.trim() || "",
					public_description: data.public_description?.trim()
						? data.public_description
						: null,
					laboratory_test_ids: data.laboratory_test_ids || [],
					return_to_campaign: returnToCampaign,
				},
				{
					headers: {
						Accept: "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
					withCredentials: true,
				},
			);

			onCreated?.(response.data.collection);
			resetForm();
			onClose?.();
		} catch (error) {
			if (error.response?.status === 422) {
				const nextErrors = {};
				Object.entries(error.response.data.errors || {}).forEach(
					([key, messages]) => {
						nextErrors[key] = Array.isArray(messages)
							? messages[0]
							: messages;
					},
				);
				setErrors(nextErrors);
			}
		} finally {
			setProcessing(false);
		}
	};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40" />
			<div className="fixed inset-0 overflow-y-auto p-4 sm:p-6">
				<div className="mx-auto flex min-h-full max-w-3xl items-start justify-center">
					<Headless.DialogPanel className="w-full rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
						<div className="mb-6 flex items-start justify-between gap-3">
							<div>
								<Headless.DialogTitle className="text-lg font-semibold">
									Crear colección de estudios
								</Headless.DialogTitle>
								<Text className="mt-1 text-sm text-zinc-500">
									Se guardará en la campaña {campaign?.name} y
									quedará seleccionada automáticamente.
								</Text>
							</div>
							<Button type="button" plain onClick={onClose}>
								<XMarkIcon className="size-5" />
							</Button>
						</div>

						<MarketingCampaignCollectionForm
							key={open ? "inline-open" : "inline-closed"}
							campaign={campaign}
							data={data}
							setData={setData}
							errors={errors}
							brands={brands}
							productSearchUrl={productSearchUrl}
							maxCollectionItems={maxCollectionItems}
							initialItems={selectedItems}
							processing={processing}
							onSubmit={submit}
							onCancel={onClose}
							submitLabel="Crear y seleccionar"
							compact
							hideHeader
						/>
					</Headless.DialogPanel>
				</div>
			</div>
		</Headless.Dialog>
	);
}
