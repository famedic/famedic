import { useState, Fragment } from "react";
import {
	ArrowDownTrayIcon,
	EnvelopeIcon,
	ArrowPathIcon,
} from "@heroicons/react/24/outline";
import { Button } from "@/Components/Catalyst/button";
import {
	Field,
	FieldGroup,
	Label,
	ErrorMessage,
} from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@headlessui/react";
import { useForm } from "@inertiajs/react";
import { Anchor } from "@/Components/Catalyst/text";

export default function PurchasePdfDialog({
	laboratoryPurchase,
	isOpen: externalIsOpen,
	onClose: externalOnClose,
	selectedTab,
	setSelectedTab,
}) {
	const [internalIsOpen, setInternalIsOpen] = useState(false);

	// Use external props if provided, otherwise use internal state
	const isOpen =
		externalIsOpen !== undefined ? externalIsOpen : internalIsOpen;
	const setIsOpen =
		externalOnClose !== undefined ? externalOnClose : setInternalIsOpen;
	const {
		data,
		setData,
		post,
		errors,
		processing,
		reset,
		recentlySuccessful,
	} = useForm({
		email: "",
	});

	const handleEmailSend = () => {
		post(route("laboratory-purchases.email-pdf", laboratoryPurchase.id), {
			preserveScroll: true,
			onSuccess: () => {
				setIsOpen(false);
			},
		});
	};

	const resetDialog = () => {
		reset();
	};

	const handleClose = (open) => {
		setIsOpen(open);
		if (!open) {
			resetDialog();
		}
	};

	return (
		<>
			<Dialog open={isOpen} onClose={handleClose}>
				<DialogTitle>Obtener PDF</DialogTitle>
				<DialogDescription>
					Descárgalo para ti o compártelo por correo
				</DialogDescription>
				<DialogBody>
					{/* Tabbed Interface */}
					<TabGroup
						selectedIndex={selectedTab}
						onChange={setSelectedTab}
					>
						<TabList className="grid grid-cols-1 gap-2 rounded-lg bg-slate-100 p-1 sm:grid-cols-2 dark:bg-slate-800">
							<Tab as={Fragment}>
								{({ selected }) => (
									<Button
										{...(selected
											? { color: "white" }
											: { plain: true })}
										className="w-full"
									>
										Descarga
									</Button>
								)}
							</Tab>
							<Tab as={Fragment}>
								{({ selected }) => (
									<Button
										{...(selected
											? { color: "white" }
											: { plain: true })}
										className="w-full"
									>
										Correo electrónico
									</Button>
								)}
							</Tab>
						</TabList>

						<TabPanels className="mt-4">
							{/* Download Tab */}
							<TabPanel></TabPanel>

							{/* Email Tab */}
							<TabPanel>
								<form
									onSubmit={(e) => {
										e.preventDefault();
										handleEmailSend();
									}}
								>
									<FieldGroup>
										<Field>
											<Label htmlFor="email">
												Correo electrónico
											</Label>
											<Input
												required
												id="email"
												type="email"
												value={data.email}
												onChange={(e) =>
													setData(
														"email",
														e.target.value,
													)
												}
												placeholder="ejemplo@correo.com"
												invalid={!!errors.email}
												disabled={processing}
											/>
											{errors.email && (
												<ErrorMessage>
													{errors.email}
												</ErrorMessage>
											)}
										</Field>
									</FieldGroup>
								</form>
							</TabPanel>
						</TabPanels>
					</TabGroup>
				</DialogBody>
				<DialogActions>
					<Button plain onClick={() => handleClose(false)} autoFocus>
						Cerrar
					</Button>
					{selectedTab === 0 ? (
						<Anchor
							href={route(
								"laboratory-purchases.download-pdf",
								laboratoryPurchase.id,
							)}
							target="_blank"
							rel="noopener noreferrer"
						>
							<Button color="famedic" type="button">
								Descargar ahora
								<ArrowDownTrayIcon />
							</Button>
						</Anchor>
					) : (
						<Button
							color="famedic"
							disabled={processing}
							onClick={handleEmailSend}
						>
							Compartir por correo
							{processing ? (
								<ArrowPathIcon className="animate-spin" />
							) : (
								<EnvelopeIcon />
							)}
						</Button>
					)}
				</DialogActions>
			</Dialog>
		</>
	);
}
