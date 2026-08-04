import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";

function optionCardClass(active) {
	return [
		"w-full rounded-xl border p-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime",
		active
			? "border-famedic-lime/60 bg-famedic-lime/10 ring-1 ring-famedic-lime/40 dark:border-famedic-lime/30 dark:bg-famedic-lime/5"
			: "border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600",
	].join(" ");
}

export default function CouponEligibilityControls({
	data,
	setData,
	errors = {},
	className = "",
	embedded = false,
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

	const content = (
		<div className={["space-y-6", className].join(" ")}>
			<div>
				<p className="font-poppins text-sm font-medium text-zinc-950 dark:text-white">
					Vigencia del saldo
				</p>
				<div className="mt-3 grid gap-3 sm:grid-cols-2">
					<button
						type="button"
						className={optionCardClass(data.validity_mode === "open")}
						onClick={() => setValidityMode("open")}
					>
						<span className="font-semibold text-zinc-900 dark:text-white">
							Sin vigencia
						</span>
						<span className="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">
							Disponible inmediatamente y sin fecha de vencimiento.
						</span>
					</button>
					<button
						type="button"
						className={optionCardClass(data.validity_mode === "configured")}
						onClick={() => setValidityMode("configured")}
					>
						<span className="font-semibold text-zinc-900 dark:text-white">
							Configurar vigencia
						</span>
						<span className="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">
							Define inicio, vencimiento o ambos.
						</span>
					</button>
				</div>
				{(errors.validity_mode || errors.valid_from) && (
					<p className="mt-2 text-sm text-red-600 dark:text-red-400">
						{errors.validity_mode || errors.valid_from}
					</p>
				)}
			</div>

			{data.validity_mode === "configured" && (
				<div className="grid gap-4 sm:grid-cols-2">
					<Field>
						<Label>Disponible desde</Label>
						<Input
							type="datetime-local"
							value={data.valid_from}
							onChange={(e) => setData("valid_from", e.target.value)}
						/>
						{errors.valid_from && (
							<p className="mt-1 text-sm text-red-600 dark:text-red-400">
								{errors.valid_from}
							</p>
						)}
					</Field>
					<Field>
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
				</div>
			)}

			<div>
				<p className="font-poppins text-sm font-medium text-zinc-950 dark:text-white">
					Compra mínima para usar saldo
				</p>
				<div className="mt-3 grid gap-3 sm:grid-cols-2">
					<button
						type="button"
						className={optionCardClass(data.minimum_purchase_mode === "none")}
						onClick={() => setMinimumPurchaseMode("none")}
					>
						<span className="font-semibold text-zinc-900 dark:text-white">
							Sin compra mínima
						</span>
						<span className="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">
							El saldo podrá usarse sin monto mínimo.
						</span>
					</button>
					<button
						type="button"
						className={optionCardClass(data.minimum_purchase_mode === "required")}
						onClick={() => setMinimumPurchaseMode("required")}
					>
						<span className="font-semibold text-zinc-900 dark:text-white">
							Requiere compra mínima
						</span>
						<span className="mt-1 block text-xs text-zinc-600 dark:text-zinc-400">
							El cliente solo podrá usar el saldo si su compra alcanza este monto.
						</span>
					</button>
				</div>
				{(errors.minimum_purchase_mode || errors.min_purchase_cents) && (
					<p className="mt-2 text-sm text-red-600 dark:text-red-400">
						{errors.minimum_purchase_mode || errors.min_purchase_cents}
					</p>
				)}
			</div>

			{data.minimum_purchase_mode === "required" && (
				<Field className="max-w-xs">
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

	if (embedded) return content;

	return (
		<CouponSectionCard
			title="Reglas de uso"
			description="Define vigencia y compra mínima de forma explícita."
		>
			{content}
		</CouponSectionCard>
	);
}
