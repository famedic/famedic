import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";
import { PlusIcon } from "@heroicons/react/16/solid";
import { UsersIcon } from "@heroicons/react/24/outline";
import { useState } from "react";
import clsx from "clsx";

const STATUS_COLORS = {
	active: "emerald",
	inactive: "zinc",
};

const PREVIEW_LIMIT = 4;

function FamilyMemberCard({ person }) {
	return (
		<Card className="w-[220px] shrink-0 rounded-2xl p-4 shadow-sm ring-1 ring-slate-100 sm:w-[240px]">
			<div className="flex items-start justify-between gap-2">
				<Avatar
					src={person.avatarUrl}
					initials={person.initials}
					className="size-11 bg-violet-100 text-violet-700"
				/>
				{person.isHolder && (
					<Badge color="violet" className="text-[10px]">
						Titular
					</Badge>
				)}
			</div>
			<div className="mt-3 min-w-0 space-y-1">
				<p className="truncate font-medium text-zinc-800 dark:text-slate-100">
					{person.name}
				</p>
				<Text className="text-sm text-zinc-500">
					{person.age != null ? `${person.age} años` : "—"}
					{person.kinship ? ` · ${person.kinship}` : ""}
				</Text>
			</div>
			<div className="mt-3">
				<Badge color={STATUS_COLORS[person.statusKey] ?? "zinc"}>
					{person.status}
				</Badge>
			</div>
		</Card>
	);
}

export default function MembershipFamily({
	coverage = [],
	capabilities,
	onViewAll,
}) {
	const [showAll, setShowAll] = useState(false);
	const hasMore = coverage.length > PREVIEW_LIMIT;
	const visibleMembers = showAll
		? coverage
		: coverage.slice(0, PREVIEW_LIMIT);

	return (
		<section className="space-y-4">
			<div className="flex flex-wrap items-end justify-between gap-3">
				<div className="flex items-center gap-3">
					<div className="flex size-9 items-center justify-center rounded-xl bg-rose-50 text-rose-500">
						<UsersIcon className="size-5" />
					</div>
					<div>
						<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
							Miembros de la familia
						</h3>
						<Text className="text-sm text-zinc-500">
							{coverage.length} integrante
							{coverage.length === 1 ? "" : "s"} en tu cobertura
						</Text>
					</div>
				</div>

				<div className="flex w-full flex-wrap gap-2 sm:w-auto">
					{capabilities?.canAddBeneficiary && (
						<Button
							outline
							href={capabilities.addBeneficiaryUrl}
							className="flex-1 sm:flex-none"
						>
							<PlusIcon />
							Agregar beneficiario
						</Button>
					)}
					{hasMore && !showAll && (
						<Button
							plain
							type="button"
							onClick={() => {
								setShowAll(true);
								onViewAll?.();
							}}
							className="flex-1 sm:flex-none"
						>
							Ver todos
						</Button>
					)}
				</div>
			</div>

			<div
				className={clsx(
					"flex gap-3 overflow-x-auto pb-1 [-webkit-overflow-scrolling:touch]",
					showAll && "flex-wrap overflow-x-visible",
				)}
			>
				{visibleMembers.map((person) => (
					<FamilyMemberCard key={person.id} person={person} />
				))}
			</div>
		</section>
	);
}
