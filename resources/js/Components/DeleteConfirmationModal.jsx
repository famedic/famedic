import {
	Alert,
	AlertTitle,
	AlertDescription,
	AlertActions,
} from "@/Components/Catalyst/alert";
import { Button } from "@/Components/Catalyst/button";
import { ArrowPathIcon, TrashIcon } from "@heroicons/react/16/solid";
import { useState, useEffect } from "react";

export default function DeleteConfirmationModal({
	isOpen,
	close,
	title,
	description,
	processing,
	destroy,
}) {
	const [cachedDescription, setCachedDescription] = useState(description);

	useEffect(() => {
		if (isOpen) {
			setCachedDescription(description);
		}
	}, [isOpen, description]);

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			destroy();
		}
	};

	return (
		<Alert open={isOpen} onClose={close}>
			<form onSubmit={submit}>
				<AlertTitle>{title ? title : "Eliminar"}</AlertTitle>
				<AlertDescription>
					{cachedDescription
						? cachedDescription
						: "¿Estás seguro de que deseas eliminar?"}
				</AlertDescription>
				<AlertActions>
					<Button
						autoFocus
						dusk="cancel"
						plain
						type="button"
						onClick={close}
					>
						Cancelar
					</Button>
					<Button
						dusk="delete"
						color="red"
						type="submit"
						disabled={processing}
					>
						<TrashIcon />
						Eliminar
						{processing && (
							<ArrowPathIcon className="animate-spin" />
						)}
					</Button>
				</AlertActions>
			</form>
		</Alert>
	);
}
