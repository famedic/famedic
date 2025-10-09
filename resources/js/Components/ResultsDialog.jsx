import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import {
	Field,
	Label,
	ErrorMessage,
	Description,
} from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Anchor } from "@/Components/Catalyst/text";
import {
	BeakerIcon,
	ArrowPathIcon,
	ArrowsUpDownIcon,
	DocumentTextIcon,
} from "@heroicons/react/16/solid";

export default function ResultsDialog({
	storeRoute,
	resultsRoute,
	hasResults,
	className = "",
}) {
	const [isOpen, setIsOpen] = useState(false);
	const [showChangeResultsButton, setShowChangeResultsButton] =
		useState(!!resultsRoute);

	const { setData, post, processing, errors, setError, clearErrors } =
		useForm({
			results: null,
		});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(storeRoute, {
				preserveScroll: true,
				onSuccess: () => setIsOpen(false),
			});
		}
	};

	useEffect(() => {
		if (isOpen) {
			setShowChangeResultsButton(!!resultsRoute);
		}
	}, [isOpen]);

	return (
		<>
			<Button
				outline
				onClick={() => setIsOpen(true)}
				className={className}
			>
				<BeakerIcon />
				{hasResults ? "Resultados" : "Agregar resultados"}
			</Button>

			<Dialog open={isOpen} onClose={() => setIsOpen(false)}>
				<form onSubmit={submit}>
					<DialogTitle>
						{hasResults
							? "Gestionar resultados"
							: "Agregar resultados"}
					</DialogTitle>
					<DialogDescription>
						{hasResults
							? "Visualiza o actualiza el archivo PDF de los resultados."
							: "Sube el archivo PDF de los resultados."}
					</DialogDescription>
					<DialogBody className="space-y-6">
						<Field>
							<Label>Resultados</Label>

							{resultsRoute && showChangeResultsButton ? (
								<div
									data-slot="control"
									className="flex flex-wrap gap-2"
								>
									<Anchor href={resultsRoute} target="_blank">
										<Button outline type="button">
											<DocumentTextIcon />
											Ver resultados
										</Button>
									</Anchor>
									<Button
										outline
										type="button"
										onClick={() =>
											setShowChangeResultsButton(false)
										}
									>
										<ArrowsUpDownIcon />
										Actualizar resultados
									</Button>
								</div>
							) : (
								<>
									<Input
										invalid={!!errors.results}
										dusk="results"
										type="file"
										accept="application/pdf"
										onChange={(e) => {
											const file = e.target.files[0];
											if (file) {
												// Check file size (10MB = 10 * 1024 * 1024 bytes)
												if (
													file.size >
													10 * 1024 * 1024
												) {
													setError(
														"results",
														"El archivo no debe superar los 10MB.",
													);
													return;
												}
												clearErrors("results");
												setData("results", file);
											}
										}}
									/>
									<Description className="mt-1">
										Formato: PDF • Tamaño máximo: 10MB
									</Description>
									{errors.results && (
										<ErrorMessage>
											{errors.results}
										</ErrorMessage>
									)}
								</>
							)}
						</Field>
					</DialogBody>
					<DialogActions>
						<Button
							autoFocus
							plain
							onClick={() => setIsOpen(false)}
							type="button"
						>
							Cerrar
						</Button>
						<Button type="submit" disabled={processing}>
							Guardar
							{processing && (
								<ArrowPathIcon className="animate-spin" />
							)}
						</Button>
					</DialogActions>
				</form>
			</Dialog>
		</>
	);
}
