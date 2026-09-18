import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";

export default function PreferredStorePromptDialog({
	open,
	onClose,
	onChooseStore,
	onContinueWithoutStore,
}) {
	return (
		<Dialog open={open} onClose={onClose} size="md">
			<DialogTitle>¿Quieres elegir una sucursal de preferencia?</DialogTitle>
			<DialogDescription>
				Puedes indicarnos qué sucursal prefieres. Es opcional y puedes
				continuar sin seleccionarla.
			</DialogDescription>
			<DialogBody />
			<DialogActions>
				<Button type="button" outline onClick={onContinueWithoutStore}>
					Continuar sin elegir
				</Button>
				<Button type="button" color="famedic" onClick={onChooseStore}>
					Elegir una sucursal
				</Button>
			</DialogActions>
		</Dialog>
	);
}
