import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";

function radioClass(active) {
	return [
		"inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
		active
			? "bg-famedic-lime/15 text-famedic-dark ring-1 ring-famedic-lime/60 dark:bg-famedic-lime/10 dark:text-famedic-lime dark:ring-famedic-lime/50"
			: "text-zinc-600 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-400 dark:ring-zinc-600 dark:hover:bg-zinc-800",
	].join(" ");
}

export default function CouponEligibilityFields({
	data,
	setData,
	errors = {},
	className = "",
	fieldClassName = "col-span-12",
}) {
	const setValidityMode = (mode) => {
		setData("validity_mode", mode);
		if (mode === "open") {
			setData("valid_from", "");
			setData("expires_at", "");
		}
	};

	const setMinimumPurchaseMode = (mode) => {
		setData("minimum_purchase_mode", mode);
		if (mode === "none") {
			setData("min_purchase_mxn", "");
		}
	};

	return (
		<div className={className}>
			<Field className={fieldClassName}>
				<Label>Vigencia del saldo</Label>
				<div className="mt-2 flex flex-wrap gap-2">
					<button
						type="button"
						className={radioClass(data.validity_mode === "open")}
						onClick={() => setValidityMode("open")}
					>
						Sin vigencia definida
					</button>
					<button
						type="button"
						className={radioClass(data.validity_mode === "configured")}
						onClick={() => setValidityMode("configured")}
					>
						Configurar vigencia
					</button>
				</div>
				{data.validity_mode === "open" ? (
					<p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
						Este saldo estará disponible inmediatamente y no vencerá.
					</p>
				) : (
					<p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
						Indica al menos una fecha: inicio, vencimiento o ambas.
					</p>
				)}
				{(errors.validity_mode || errors.valid_from) && (
					<p className="mt-1 text-sm text-red-600 dark:text-red-400">
						{errors.validity_mode || errors.valid_from}
					</p>
				)}
			</Field>

			{data.validity_mode === "configured" && (
				<>
					<Field className={`${fieldClassName} md:col-span-6`}>
						<Label>Disponible desde</Label>
						<Input
							type="datetime-local"
							value={data.valid_from}
							onChange={(e) => setData("valid_from", e.target.value)}
						/>
						{errors.valid_from && data.validity_mode === "configured" && (
							<p className="mt-1 text-sm text-red-600 dark:text-red-400">
								{errors.valid_from}
							</p>
						)}
					</Field>
					<Field className={`${fieldClassName} md:col-span-6`}>
						<Label>Vence el</Label>
						<Input
							type="datetime-local"
							value={data.expires_at}
							onChange={(e) => setData("expires_at", e.target.value)}
						/>
						{errors.expires_at && (
							<p className="mt-1 text-sm text-red-600 dark:text-red-400">
								{errors.expires_at}
							</p>
						)}
					</Field>
				</>
			)}

			<Field className={fieldClassName}>
				<Label>Compra mínima para usar saldo</Label>
				<div className="mt-2 flex flex-wrap gap-2">
					<button
						type="button"
						className={radioClass(data.minimum_purchase_mode === "none")}
						onClick={() => setMinimumPurchaseMode("none")}
					>
						Sin compra mínima
					</button>
					<button
						type="button"
						className={radioClass(data.minimum_purchase_mode === "required")}
						onClick={() => setMinimumPurchaseMode("required")}
					>
						Requiere compra mínima
					</button>
				</div>
				{data.minimum_purchase_mode === "none" ? (
					<p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
						El saldo podrá usarse sin monto mínimo de compra.
					</p>
				) : (
					<p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
						Indica el monto mínimo de compra en MXN.
					</p>
				)}
				{(errors.minimum_purchase_mode || errors.min_purchase_cents) && (
					<p className="mt-1 text-sm text-red-600 dark:text-red-400">
						{errors.minimum_purchase_mode || errors.min_purchase_cents}
					</p>
				)}
			</Field>

			{data.minimum_purchase_mode === "required" && (
				<Field className={`${fieldClassName} md:col-span-6`}>
					<Label>Compra mínima requerida (MXN)</Label>
					<Input
						type="number"
						step="0.01"
						min="0.01"
						placeholder="Ej. 500.00"
						value={data.min_purchase_mxn}
						onChange={(e) => setData("min_purchase_mxn", e.target.value)}
					/>
					{errors.min_purchase_cents && (
						<p className="mt-1 text-sm text-red-600 dark:text-red-400">
							{errors.min_purchase_cents}
						</p>
					)}
				</Field>
			)}
		</div>
	);
}
