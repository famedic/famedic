import { Subheading, Heading } from "@/Components/Catalyst/heading";
import AdminLayout from "@/Layouts/AdminLayout";
import PurchasesChart from "@/Components/PurchasesChart";

export default function Admin({
	laboratory,
	onlinePharmacy,
	medicalAttention,
	dateRange,
}) {
	return (
		<AdminLayout title="Administración">
			<Heading>Resumen de {dateRange}</Heading>

			{/* <Navbar className="my-2">
				<NavbarItem>Hoy</NavbarItem>
				<NavbarItem>Últimos 15 días</NavbarItem>
				<NavbarItem>Últimos 30 días</NavbarItem>
			</Navbar> */}

			<div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
				<div className="rounded-lg bg-zinc-50 px-8 py-6 shadow-sm ring-1 ring-zinc-950/5 lg:col-span-2 dark:bg-slate-950 dark:ring-white/10">
					<Subheading>Laboratorio</Subheading>
					<PurchasesChart chart={laboratory} />
				</div>{" "}
				<div className="rounded-lg bg-zinc-50 px-8 py-6 shadow-sm ring-1 ring-zinc-950/5 dark:bg-slate-950 dark:ring-white/10">
					<Subheading>Farmacia en línea</Subheading>
					<PurchasesChart chart={onlinePharmacy} />
				</div>{" "}
				<div className="rounded-lg bg-zinc-50 px-8 py-6 shadow-sm ring-1 ring-zinc-950/5 dark:bg-slate-950 dark:ring-white/10">
					<Subheading>Atención médica</Subheading>
					<PurchasesChart chart={medicalAttention} />
				</div>{" "}
			</div>
		</AdminLayout>
	);
}
