import {
	Dialog,
	DialogTitle,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Button } from "@/Components/Catalyst/button";
import { useForm, usePage } from "@inertiajs/react";
import { ErrorMessage } from "@/Components/Catalyst/fieldset";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
	ListboxDescription,
} from "@/Components/Catalyst/listbox";
import SettingsCard from "@/Components/SettingsCard";
import { Subheading } from "@/Components/Catalyst/heading";
import { Code, Strong, Text, TextLink } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { useEffect } from "react";
import { ArrowPathIcon, ArrowUpRightIcon } from "@heroicons/react/16/solid";

export default function RequestInvoiceModal({
	isOpen,
	storeRoute,
	close,
	purchase,
}) {
	const { taxProfiles } = usePage().props;

	const { data, setData, post, reset, processing, errors } = useForm({
		tax_profile: null,
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(storeRoute, {
				preserveScroll: true,
				onSuccess: () => close(),
			});
		}
	};

	useEffect(() => {
		if (isOpen) {
			reset();
		}
	}, [isOpen]);

	return (
		<Dialog open={isOpen} onClose={close}>
			<form onSubmit={submit}>
				<DialogTitle>
					{taxProfiles.length == 0
						? "Primero tendras que agregar un perfil fiscal"
						: purchase.invoice_request
							? "Factura solicitada"
							: "Solicitar factura"}
				</DialogTitle>
				<DialogBody className="space-y-6">
					{taxProfiles.length == 0 ? (
						<>
							<Text>
								Es necesario que agregues un perfil fiscal para
								que recibamos tu información al solicitar la
								factura.
							</Text>
							<Text>
								Una vez que lo hayas agregado, puedes regresar a
								solicitar tu factura
							</Text>
							<TextLink
								href={route("tax-profiles.index")}
								className="flex items-center"
							>
								Agregar perfil fiscal
								<ArrowUpRightIcon className="size-5" />
							</TextLink>
						</>
					) : purchase.invoice_request ? (
						<Text>
							Si lo deseas, puedes actulizar el perfil fiscal.{" "}
							<Strong>Recibirás tu factura de 3 a 5 días</Strong>{" "}
							hábiles a partir de la fecha de solicitud.
						</Text>
					) : (
						<Text>
							Elige el perfil fiscal que deseas utilizar para tu
							factura. Una vez que se haya solicitado,{" "}
							<Strong>recibirás tu factura de 3 a 5 días</Strong>{" "}
							hábiles.
						</Text>
					)}

					{taxProfiles.length > 0 && (
						<>
							{purchase.invoice_request && (
								<>
									<Text>
										Esta es la información que usaremos para
										generar tu factura:
									</Text>
									<SettingsCard as="div">
										<Subheading>
											{purchase.invoice_request.name}
										</Subheading>
										<Code>
											{purchase.invoice_request.rfc}
										</Code>
										<Text className="mb-3">
											CP{" "}
											{purchase.invoice_request.zipcode}
										</Text>
										<Badge
											color="slate"
											className="mb-1 max-w-60"
										>
											<span className="line-clamp-1">
												{
													purchase.invoice_request
														.formatted_tax_regime
												}
											</span>
										</Badge>
										<br />
										<Badge
											color="slate"
											className="max-w-60"
										>
											{
												purchase.invoice_request
													.formatted_cfdi_use
											}
										</Badge>
									</SettingsCard>
								</>
							)}
							<Field>
								<Label>
									{purchase.invoice_request
										? "Actualizar perfil fiscal"
										: "Perfil fiscal"}
								</Label>
								<Listbox
									invalid={!!errors.tax_profile}
									placeholder="Selecciona un perfil fiscal"
									value={data.tax_profile}
									onChange={(value) => {
										setData("tax_profile", value);
									}}
								>
									{taxProfiles.map((profile) => (
										<ListboxOption
											key={profile.id}
											value={profile.id}
										>
											<ListboxLabel className="w-40">
												{profile.rfc}
												<br />
												{profile.name}
											</ListboxLabel>
											<ListboxDescription className="w-40">
												{profile.formatted_tax_regime}
												<br />
												{profile.formatted_cfdi_use}
											</ListboxDescription>
										</ListboxOption>
									))}
								</Listbox>
								{errors.tax_profile && (
									<ErrorMessage>
										{errors.tax_profile}
									</ErrorMessage>
								)}
							</Field>
						</>
					)}
				</DialogBody>
				<DialogActions>
					<Button autoFocus plain onClick={close} type="button">
						Cerrar
					</Button>
					{taxProfiles.length > 0 && (
						<Button type="submit" disabled={processing}>
							Solicitar factura
							{processing && (
								<ArrowPathIcon className="animate-spin" />
							)}
						</Button>
					)}
				</DialogActions>
			</form>
		</Dialog>
	);
}
