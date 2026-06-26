import Card from "@/Components/Card";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { PlusIcon } from "@heroicons/react/16/solid";
import {
	PencilSquareIcon,
	TrashIcon,
} from "@heroicons/react/24/outline";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

const STATUS_COLORS = {
	active: "emerald",
	inactive: "zinc",
};

const KINSHIP_ORDER = ["Titular", "Cónyuge", "Hijo", "Hija", "Beneficiario"];

function sortByKinship(coverage) {
	return [...coverage].sort((a, b) => {
		const indexA = KINSHIP_ORDER.findIndex((item) =>
			a.kinship?.includes(item),
		);
		const indexB = KINSHIP_ORDER.findIndex((item) =>
			b.kinship?.includes(item),
		);

		return (indexA === -1 ? 99 : indexA) - (indexB === -1 ? 99 : indexB);
	});
}

export default function MembershipCoverage({ coverage = [], capabilities }) {
	const sortedCoverage = sortByKinship(coverage);

	return (
		<div className="space-y-6">
			<div className="flex flex-wrap items-end justify-between gap-4">
				<div>
					<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
						Cobertura familiar
					</h3>
					<Text className="text-sm text-zinc-500">
						Titular, cónyuge, hijos y beneficiarios protegidos.
					</Text>
				</div>

				{capabilities?.canAddBeneficiary && (
					<Button
						outline
						href={capabilities.addBeneficiaryUrl}
						className="w-full sm:w-auto"
					>
						<PlusIcon />
						Agregar beneficiario
					</Button>
				)}
			</div>

			<Card className="overflow-hidden rounded-2xl shadow-sm ring-1 ring-slate-100">
				<div className="hidden md:block">
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Beneficiario</TableHeader>
								<TableHeader>Parentesco</TableHeader>
								<TableHeader>Edad</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader className="text-right">
									Acciones
								</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{sortedCoverage.map((person) => (
								<TableRow key={person.id}>
									<TableCell>
										<div className="flex items-center gap-3">
											<Avatar
												src={person.avatarUrl}
												initials={person.initials}
												className="size-9 bg-violet-100 text-violet-700"
											/>
											<span className="font-medium">
												{person.name}
											</span>
										</div>
									</TableCell>
									<TableCell>{person.kinship}</TableCell>
									<TableCell>
										{person.age != null
											? `${person.age} años`
											: "—"}
									</TableCell>
									<TableCell>
										<Badge
											color={
												STATUS_COLORS[person.statusKey] ??
												"zinc"
											}
										>
											{person.status}
										</Badge>
									</TableCell>
									<TableCell className="text-right">
										<div className="flex justify-end gap-2">
											{person.editUrl && (
												<Button
													plain
													href={person.editUrl}
													className="!px-2"
												>
													<PencilSquareIcon className="size-4" />
													Editar
												</Button>
											)}
											{person.familyAccountId && (
												<Button
													plain
													href={route(
														"family.destroy",
														person.familyAccountId,
													)}
													method="delete"
													className="!px-2 !text-rose-600"
												>
													<TrashIcon className="size-4" />
													Eliminar
												</Button>
											)}
										</div>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>

				<div className="divide-y divide-slate-100 md:hidden">
					{sortedCoverage.map((person) => (
						<div key={person.id} className="space-y-3 p-4">
							<div className="flex items-center gap-3">
								<Avatar
									src={person.avatarUrl}
									initials={person.initials}
									className="size-10 bg-violet-100 text-violet-700"
								/>
								<div className="min-w-0 flex-1">
									<p className="truncate font-medium">
										{person.name}
									</p>
									<Text className="text-sm text-zinc-500">
										{person.kinship}
										{person.age != null
											? ` · ${person.age} años`
											: ""}
									</Text>
								</div>
								<Badge
									color={
										STATUS_COLORS[person.statusKey] ?? "zinc"
									}
								>
									{person.status}
								</Badge>
							</div>
							<div className="flex gap-2">
								{person.editUrl && (
									<Button
										outline
										href={person.editUrl}
										className="flex-1"
									>
										Editar
									</Button>
								)}
								{person.familyAccountId && (
									<Button
										outline
										href={route(
											"family.destroy",
											person.familyAccountId,
										)}
										method="delete"
										className="flex-1 !text-rose-600"
									>
										Eliminar
									</Button>
								)}
							</div>
						</div>
					))}
				</div>
			</Card>
		</div>
	);
}
