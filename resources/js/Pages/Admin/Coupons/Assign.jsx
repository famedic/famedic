import { useEffect, useMemo, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { useForm } from "@inertiajs/react";
import {
	Listbox,
	ListboxDescription,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";

export default function CouponsAssign({ assignableCoupons }) {
	const [search, setSearch] = useState("");

	const filtered = useMemo(() => {
		const list = assignableCoupons ?? [];
		const s = search.trim().toLowerCase();
		if (!s) {
			return list;
		}
		return list.filter((c) => {
			const hay = `${c.id} ${c.code ?? ""} ${c.description ?? ""}`.toLowerCase();
			return hay.includes(s);
		});
	}, [assignableCoupons, search]);

	const firstId = filtered[0]?.id ?? assignableCoupons?.[0]?.id ?? "";

	const { data, setData, post, processing, errors } = useForm({
		email: "",
		coupon_id: firstId,
		send_notification: true,
	});

	useEffect(() => {
		if (!filtered.length) {
			return;
		}
		if (!filtered.some((c) => c.id === data.coupon_id)) {
			setData("coupon_id", filtered[0].id);
		}
	}, [filtered, data.coupon_id, setData]);

	const submit = (e) => {
		e.preventDefault();
		post(route("admin.coupons.assign.store"));
	};

	return (
		<AdminLayout title="Asignar beneficiario">
			<Heading>Asignar beneficiario a cupón maestro</Heading>
			<Text className="mt-2 text-zinc-600">
				Elige un cupón autorizado con cupo disponible e indica el correo del usuario
				registrado en Famedic. Se creará un cupón hijo con el mismo monto por persona.
			</Text>
			<form onSubmit={submit} className="mt-6 max-w-lg space-y-6">
				<Field>
					<Label>Buscar cupón</Label>
					<Input
						placeholder="ID, código o descripción…"
						value={search}
						onChange={(e) => setSearch(e.target.value)}
					/>
				</Field>
				<Field>
					<Label>Cupón maestro</Label>
					{filtered.length === 0 ? (
						<p className="text-sm text-amber-700 dark:text-amber-300">
							No hay cupones disponibles para asignar (autoriza uno nuevo o revisa el
							cupo de beneficiarios).
						</p>
					) : (
						<Listbox
							value={data.coupon_id}
							onChange={(v) => setData("coupon_id", v)}
							placeholder="Selecciona…"
						>
							{filtered.map((c) => (
								<ListboxOption key={c.id} value={c.id}>
									<ListboxLabel>
										#{c.id}
										{c.code ? ` · ${c.code}` : ""} —{" "}
										{(c.amount_cents / 100).toLocaleString("es-MX", {
											style: "currency",
											currency: "MXN",
										})}
									</ListboxLabel>
									<ListboxDescription>
										{c.child_coupons_count ?? 0}
										{c.max_beneficiaries != null
											? ` / ${c.max_beneficiaries}`
											: ""}{" "}
										beneficiarios
										{c.description
											? ` · ${c.description}`
											: " · Sin descripción"}
									</ListboxDescription>
								</ListboxOption>
							))}
						</Listbox>
					)}
					{errors.coupon_id && (
						<p className="text-sm text-red-600">{errors.coupon_id}</p>
					)}
				</Field>
				<Field>
					<Label>Correo del usuario</Label>
					<Input
						type="email"
						value={data.email}
						onChange={(e) => setData("email", e.target.value)}
					/>
					{errors.email && (
						<p className="text-sm text-red-600">{errors.email}</p>
					)}
				</Field>
				<CheckboxField>
					<Checkbox
						checked={data.send_notification}
						onChange={(v) => setData("send_notification", v)}
					/>
					<Label>Enviar notificación (correo y aviso en plataforma)</Label>
				</CheckboxField>
				<div className="flex gap-2">
					<Button
						type="submit"
						disabled={processing || filtered.length === 0}
						color="emerald"
					>
						Asignar
					</Button>
					<Button href={route("admin.coupons.index")} plain>
						Volver
					</Button>
				</div>
			</form>
		</AdminLayout>
	);
}
