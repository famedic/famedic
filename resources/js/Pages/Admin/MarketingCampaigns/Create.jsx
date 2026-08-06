import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignForm from "./Components/MarketingCampaignForm";
import { fromDatetimeLocalValue } from "./Components/MarketingCampaignDateRangeFields";

export default function MarketingCampaignsCreate({ statusOptions = {} }) {
	const { data, setData, post, processing, errors, transform } = useForm({
		name: "",
		description: "",
		status: "draft",
		starts_at: "",
		ends_at: "",
	});

	transform((form) => ({
		...form,
		starts_at: fromDatetimeLocalValue(form.starts_at),
		ends_at: fromDatetimeLocalValue(form.ends_at),
		description: form.description?.trim() ? form.description : null,
	}));

	const submit = (e) => {
		e.preventDefault();
		if (!processing) {
			post(route("admin.marketing-campaigns.store"));
		}
	};

	return (
		<AdminLayout title="Nueva campaña">
			<div className="mx-auto max-w-3xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Nueva campaña</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Define el nombre, estado y vigencia de la campaña.
						</Text>
					</div>
					<Button
						href={route("admin.marketing-campaigns.index")}
						outline
					>
						Volver
					</Button>
				</div>

				<MarketingCampaignForm
					data={data}
					setData={setData}
					errors={errors}
					statusOptions={statusOptions}
					processing={processing}
					onSubmit={submit}
					submitLabel="Crear campaña"
				/>
			</div>
		</AdminLayout>
	);
}
