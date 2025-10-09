import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import ShoppingCartBanner from "@/Components/ShoppingCartBanner";
import { useForm } from "@inertiajs/react";
import FamedicLayout from "@/Layouts/FamedicLayout";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import {
	MagnifyingGlassIcon,
	CursorArrowRippleIcon,
	DocumentCheckIcon,
	GlobeAmericasIcon,
	QueueListIcon,
} from "@heroicons/react/24/solid";
import { Field } from "@/Components/Catalyst/fieldset";
import { Button } from "@/Components/Catalyst/button";
import FeaturesGrid from "@/Components/FeaturesGrid";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import { Link } from "@/Components/Catalyst/link";

export default function OnlinePharmacy({ onlinePharmacyCart }) {
	const { data, setData, get, processing } = useForm({
		search: "",
	});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			get(route("online-pharmacy-search"));
		}
	};

	const hasShoppingCartBanner = onlinePharmacyCart?.length > 0;

	return (
		<FamedicLayout title="Farmacia en línea" hasShoppingCartBanner={hasShoppingCartBanner}>
			<GradientHeading>Farmacia en línea</GradientHeading>

			<div className="flex items-center justify-center">
				<form
					onSubmit={submit}
					className="flex w-full max-w-2xl animate-bounce items-center gap-2 focus-within:animate-none"
				>
					<Field className="w-full">
						<InputGroup>
							<MagnifyingGlassIcon />
							<Input
								autoFocus
								dusk="search"
								type="text"
								value={data.search}
								onChange={(e) =>
									setData("search", e.target.value)
								}
								placeholder="Busca aquí tus medicamentos"
							/>
						</InputGroup>
					</Field>

					<Button disabled={processing} type="submit">
						Buscar
						{processing && (
							<ArrowPathIcon className="animate-spin" />
						)}
					</Button>
				</form>
			</div>

			<Categories />

			<FeaturesGrid
				features={[
					{
						name: "Tus medicamentos a un click",
						description:
							"Todos tus medicamentos a precios justos y con entrega a domicilio (sin costo en compras de $1,500 MXN o más).",
						icon: CursorArrowRippleIcon,
					},
					{
						name: "Cobertura nacional",
						description:
							"Entregamos tus medicamentos en cualquier parte del territorio nacional",
						icon: GlobeAmericasIcon,
					},
					{
						name: "Facturación sencilla",
						icon: DocumentCheckIcon,
						description:
							"Con tus perfiles fiscales, es muy fácil solicitar tus facturas.",
					},
					{
						name: "Historial de compras y resultados",
						icon: QueueListIcon,
						description:
							"Consulta todas tus compras, facturas y resultados de laboratorios.",
					},
				]}
			/>

			{onlinePharmacyCart?.length > 0 && (
				<ShoppingCartBanner
					message={`Tienes ${onlinePharmacyCart?.length} producto${onlinePharmacyCart?.length > 1 ? "s" : ""} en el carrito`}
					href={route("online-pharmacy.shopping-cart")}
				/>
			)}
		</FamedicLayout>
	);
}

function Categories() {
	return (
		<section>
			<div className="sm:flex sm:items-baseline sm:justify-between">
				<Subheading>Explorar por categoría</Subheading>
				{/* <Button plain className="hidden sm:block">
					<span className="text-famedic-light">Ver todas</span>
				</Button> */}
			</div>

			<div className="mt-6 grid grid-cols-1 gap-y-6 sm:grid-cols-2 sm:grid-rows-2 sm:gap-x-6 lg:gap-8">
				<div className="group aspect-h-1 aspect-w-2 overflow-hidden rounded-lg shadow-lg sm:aspect-h-1 sm:aspect-w-1 sm:row-span-2">
					<img
						alt="Two models wearing women's black cotton crewneck tee and off-white cotton crewneck tee."
						src="https://images.pexels.com/photos/265987/pexels-photo-265987.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
						className="object-cover object-center group-hover:opacity-75"
					/>
					<div
						aria-hidden="true"
						className="bg-gradient-to-b from-transparent to-black opacity-50"
					/>
					<div className="flex items-end p-6">
						<div>
							<h3 className="font-semibold text-white">
								<Link
									href={route("online-pharmacy-search", {
										category: 279,
									})}
								>
									<span className="absolute inset-0" />
									Bebés
								</Link>
							</h3>
							<p
								aria-hidden="true"
								className="mt-1 text-sm text-white"
							>
								Ver más
							</p>
						</div>
					</div>
				</div>
				<div className="group aspect-h-1 aspect-w-2 overflow-hidden rounded-lg shadow-lg sm:aspect-none sm:relative sm:h-full">
					<img
						alt="Wooden shelf with gray and olive drab green baseball caps, next to wooden clothes hanger with sweaters."
						src="https://images.pexels.com/photos/3764013/pexels-photo-3764013.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
						className="object-cover object-center group-hover:opacity-75 sm:absolute sm:inset-0 sm:h-full sm:w-full"
					/>
					<div
						aria-hidden="true"
						className="bg-gradient-to-b from-transparent to-black opacity-50 sm:absolute sm:inset-0"
					/>
					<div className="flex items-end p-6 sm:absolute sm:inset-0">
						<div>
							<h3 className="font-semibold text-white">
								<Link
									href={route("online-pharmacy-search", {
										category: 246,
									})}
								>
									<span className="absolute inset-0" />
									Belleza e higiene
								</Link>
							</h3>
							<p
								aria-hidden="true"
								className="mt-1 text-sm text-white"
							>
								Ver más
							</p>
						</div>
					</div>
				</div>
				<div className="group aspect-h-1 aspect-w-2 overflow-hidden rounded-lg shadow-lg sm:aspect-none sm:relative sm:h-full">
					<img
						alt="Walnut desk organizer set with white modular trays, next to porcelain mug on wooden desk."
						src="https://images.pexels.com/photos/3683051/pexels-photo-3683051.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2"
						className="object-cover object-center group-hover:opacity-75 sm:absolute sm:inset-0 sm:h-full sm:w-full"
					/>
					<div
						aria-hidden="true"
						className="bg-gradient-to-b from-transparent to-black opacity-50 sm:absolute sm:inset-0"
					/>
					<div className="flex items-end p-6 sm:absolute sm:inset-0">
						<div>
							<h3 className="font-semibold text-white">
								<Link
									href={route("online-pharmacy-search", {
										category: 289,
									})}
								>
									<span className="absolute inset-0" />
									Medicamentos
								</Link>
							</h3>
							<p
								aria-hidden="true"
								className="mt-1 text-sm text-white"
							>
								Ver más
							</p>
						</div>
					</div>
				</div>
			</div>

			<div className="mt-6 sm:hidden">
				<Button plain>
					<span className="text-famedic-light">Ver todas</span>
				</Button>
			</div>
		</section>
	);
}
