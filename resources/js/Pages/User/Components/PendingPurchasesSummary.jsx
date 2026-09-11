import Card from "@/Components/Card";
import {
	BeakerIcon,
	ClipboardDocumentListIcon,
	ShoppingCartIcon,
	ArrowPathRoundedSquareIcon,
} from "@heroicons/react/24/outline";

const summaryItems = [
	{
		key: "total",
		label: "Compras pendientes",
		icon: ClipboardDocumentListIcon,
	},
	{
		key: "carts",
		label: "Carritos guardados",
		icon: ShoppingCartIcon,
	},
	{
		key: "checkouts",
		label: "Checkouts en progreso",
		icon: ArrowPathRoundedSquareIcon,
	},
	{
		key: "items",
		label: "Estudios",
		icon: BeakerIcon,
	},
];

export default function PendingPurchasesSummary({ summary = {} }) {
	return (
		<section
			className="grid grid-cols-2 gap-3 sm:grid-cols-4"
			aria-label="Resumen de compras pendientes"
		>
			{summaryItems.map(({ key, label, icon: Icon }) => (
				<Card key={key} className="p-4">
					<div className="flex items-start justify-between gap-3">
						<div className="min-w-0">
							<p className="font-poppins text-2xl font-semibold text-famedic-darker dark:text-white">
								{Number(summary?.[key] ?? 0)}
							</p>
							<p className="mt-1 text-sm leading-5 text-zinc-500 dark:text-slate-400">
								{label}
							</p>
						</div>
						<span className="rounded-lg bg-famedic-lime/40 p-2 text-famedic-darker dark:bg-famedic-lime/15 dark:text-famedic-lime">
							<Icon className="size-5" aria-hidden="true" />
						</span>
					</div>
				</Card>
			))}
		</section>
	);
}
