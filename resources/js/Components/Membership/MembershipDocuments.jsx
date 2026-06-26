import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	ArrowDownTrayIcon,
	DocumentTextIcon,
} from "@heroicons/react/24/outline";

const TYPE_ICONS = {
	receipt: DocumentTextIcon,
	contract: DocumentTextIcon,
	invoice: DocumentTextIcon,
	terms: DocumentTextIcon,
};

export default function MembershipDocuments({ documents = [] }) {
	return (
		<div className="space-y-6">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Documentos
				</h3>
				<Text className="text-sm text-zinc-500">
					Comprobantes, contratos y términos de tu membresía.
				</Text>
			</div>

			<div className="grid gap-4 sm:grid-cols-2">
				{documents.map((document) => {
					const Icon = TYPE_ICONS[document.type] ?? DocumentTextIcon;

					return (
						<Card
							key={document.id}
							className="rounded-2xl p-5 shadow-sm ring-1 ring-slate-100"
						>
							<div className="flex items-start gap-4">
								<div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
									<Icon className="size-5" />
								</div>
								<div className="min-w-0 flex-1">
									<p className="font-medium text-zinc-800 dark:text-slate-100">
										{document.label}
									</p>
									<Text className="mt-1 text-sm text-zinc-500">
										{document.description}
									</Text>
									<div className="mt-4">
										<Button
											outline
											disabled={!document.available}
											href={
												document.available
													? document.downloadUrl
													: undefined
											}
											className="w-full sm:w-auto"
										>
											<ArrowDownTrayIcon className="size-4" />
											{document.available
												? "Descargar"
												: "Próximamente"}
										</Button>
									</div>
								</div>
							</div>
						</Card>
					);
				})}
			</div>
		</div>
	);
}
