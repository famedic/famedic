import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Text } from "@/Components/Catalyst/text";
import { useForm } from "@inertiajs/react";

export default function CouponsImport() {
	const { data, setData, post, processing, errors } = useForm({
		file: null,
		send_notifications: true,
	});

	const submit = (e) => {
		e.preventDefault();
		post(route("admin.coupons.import.store"), { forceFormData: true });
	};

	return (
		<AdminLayout title="Importar cupones">
			<Heading>Importar desde Excel</Heading>
			<Text className="mt-2">
				Columnas: <strong>email</strong>, <strong>amount</strong> (pesos
				MXN), <strong>code</strong> (opcional).
			</Text>
			<form onSubmit={submit} className="mt-6 max-w-md space-y-6">
				<Field>
					<Label>Archivo (.xlsx, .xls, .csv)</Label>
					<Input
						type="file"
						accept=".xlsx,.xls,.csv"
						onChange={(e) =>
							setData("file", e.target.files?.[0] || null)
						}
					/>
					{errors.file && (
						<p className="text-sm text-red-600">{errors.file}</p>
					)}
				</Field>
				<CheckboxField>
					<Checkbox
						checked={data.send_notifications}
						onChange={(v) => setData("send_notifications", v)}
					/>
					<Label>Enviar notificaciones</Label>
				</CheckboxField>
				<div className="flex gap-2">
					<Button type="submit" disabled={processing} color="emerald">
						Importar
					</Button>
					<Button href={route("admin.coupons.index")} plain>
						Volver
					</Button>
				</div>
			</form>
		</AdminLayout>
	);
}
