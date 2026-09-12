import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";

export default function CampaignFinalCta({
	catalogUrl,
	brandStoresUrl,
	primaryAction,
	actionButtonProps,
	brand,
	variant = "default",
}) {
	if (!catalogUrl && !brandStoresUrl) return null;

	if (variant === "conversion" || variant === "catalog") {
		return (
			<section className="px-5 py-2 text-center sm:px-8">
				<Heading level={3} className="font-poppins text-2xl font-semibold text-famedic-darker">
					{variant === "catalog" ? "¿Listo para cuidar tu salud?" : "Haz de tu salud una prioridad esta campaña"}
				</Heading>
				<Text className="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-600">
					{variant === "catalog"
						? `Explora todos los estudios${brand?.label ? ` de ${brand.label}` : ""} y elige el que mejor se adapte a tus necesidades.`
						: `Explora todos los estudios disponibles${brand?.label ? ` con ${brand.label}` : ""} o encuentra una sucursal cerca de ti.`}
				</Text>
				<div className="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
					{catalogUrl && (
						<Button {...actionButtonProps(catalogUrl, { color: "lime", className: "w-full sm:w-auto" })}>
							{primaryAction?.label || "Ver estudios"}
						</Button>
					)}
					{brandStoresUrl && (
						<Button {...actionButtonProps(brandStoresUrl, { outline: true, className: "w-full sm:w-auto" })}>
							Encontrar sucursal
						</Button>
					)}
				</div>
			</section>
		);
	}

	return (
		<section className="rounded-2xl bg-famedic-darker px-5 py-8 text-center text-white sm:px-8">
			<Heading level={3} className="font-poppins text-2xl font-semibold text-white">
				Listo para continuar
			</Heading>
			<Text className="mx-auto mt-3 max-w-2xl text-sm leading-6 text-white/78">
				Explora estudios disponibles{brand?.label ? ` con ${brand.label}` : ""} o consulta una sucursal para mas informacion.
			</Text>
			<div className="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
				{catalogUrl && (
					<Button {...actionButtonProps(catalogUrl, { color: "lime", className: "w-full sm:w-auto" })}>
						{primaryAction?.label || "Ver estudios"}
					</Button>
				)}
				{brandStoresUrl && (
					<Button {...actionButtonProps(brandStoresUrl, { outline: true, className: "w-full sm:w-auto text-white ring-white/30 hover:bg-white/10" })}>
						Consultar sucursales
					</Button>
				)}
			</div>
			<Text className="mt-5 text-sm text-white/70">
				Necesitas ayuda? Puedes contactarnos al 81 2860 1893
			</Text>
		</section>
	);
}
