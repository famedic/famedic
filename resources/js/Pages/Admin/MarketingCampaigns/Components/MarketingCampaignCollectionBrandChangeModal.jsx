import {
	Alert,
	AlertActions,
	AlertDescription,
	AlertTitle,
} from "@/Components/Catalyst/alert";
import { Button } from "@/Components/Catalyst/button";

export default function MarketingCampaignCollectionBrandChangeModal({
	isOpen,
	close,
	brandLabel = "",
	productCount = 0,
	onConfirm,
}) {
	return (
		<Alert open={isOpen} onClose={close}>
			<AlertTitle>Cambiar marca de laboratorio</AlertTitle>
			<AlertDescription>
				La colección tiene {productCount} estudio
				{productCount === 1 ? "" : "s"} de otra marca. Si cambias a{" "}
				<strong>{brandLabel}</strong>, esos estudios se quitarán porque no
				son compatibles.
			</AlertDescription>
			<AlertActions>
				<Button plain type="button" onClick={close}>
					Cancelar cambio
				</Button>
				<Button color="red" type="button" onClick={onConfirm}>
					Cambiar marca y limpiar estudios
				</Button>
			</AlertActions>
		</Alert>
	);
}
