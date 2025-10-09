import {
	Table,
	TableBody,
	TableHead,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { Button } from "@/Components/Catalyst/button";
import { BarsArrowDownIcon, BarsArrowUpIcon } from "@heroicons/react/16/solid";
import Card from "@/Components/Card";

export default function PurchaseCard({
	href,
	cardContent,
	tableHeaders,
	tableRows,
}) {
	return (
		<div>
			<Card
				hoverable
				href={href}
				className="group flex flex-col items-center justify-between gap-4 px-4 py-6 sm:flex-row sm:p-6 lg:p-8"
			>
				{cardContent}
			</Card>

			<Disclosure>
				{({ open }) => (
					<div>
						<DisclosurePanel className="ml-auto max-w-4xl p-4">
							<Table className="[--gutter:theme(spacing.4)]">
								<TableHead>
									<TableRow>{tableHeaders}</TableRow>
								</TableHead>
								<TableBody>{tableRows}</TableBody>
							</Table>
						</DisclosurePanel>
						<DisclosureButton className="mt-4 flex w-full justify-end">
							<Button as="div" outline>
								{open ? (
									<BarsArrowUpIcon className="size-4" />
								) : (
									<BarsArrowDownIcon className="size-4" />
								)}
								{open ? "Ocultar" : "Mostrar"} detalle
							</Button>
						</DisclosureButton>
					</div>
				)}
			</Disclosure>
		</div>
	);
}
