import Card from "@/Components/Card";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { PlusIcon } from "@heroicons/react/16/solid";
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

export default function MembershipCoverage({ coverage = [], capabilities }) {
	return (
		<section className="space-y-4">
			<div className="flex flex-wrap items-end justify-between gap-4">
				<div>
					<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
						Cobertura
					</h3>
					<Text className="text-sm text-zinc-500">
						Pacientes protegidos por tu membresía.
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

			<Card className="overflow-hidden shadow-sm ring-1 ring-slate-100">
				<div className="hidden sm:block">
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Beneficiario</TableHeader>
								<TableHeader>Parentesco</TableHeader>
								<TableHeader>Edad</TableHeader>
								<TableHeader>Estado</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{coverage.map((person) => (
								<TableRow key={person.id}>
									<TableCell>
										<div className="flex items-center gap-3">
											<Avatar
												src={person.avatarUrl}
												initials={person.initials}
												className="size-9 bg-sky-100 text-sky-700"
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
												STATUS_COLORS[
													person.statusKey
												] ?? "zinc"
											}
										>
											{person.status}
										</Badge>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>

				<div className="divide-y divide-slate-100 sm:hidden dark:divide-slate-800">
					{coverage.map((person) => (
						<div
							key={person.id}
							className="flex items-center gap-3 p-4"
						>
							<Avatar
								src={person.avatarUrl}
								initials={person.initials}
								className="size-10 bg-sky-100 text-sky-700"
							/>
							<div className="min-w-0 flex-1">
								<p className="truncate font-medium text-zinc-800 dark:text-slate-100">
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
					))}
				</div>
			</Card>
		</section>
	);
}
