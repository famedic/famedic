import Card from "@/Components/Card";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

export default function MembershipHolderCard({ holder }) {
	if (!holder) {
		return null;
	}

	return (
		<Card className="rounded-2xl p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
			<div className="flex items-start gap-4">
				<Avatar
					src={holder.avatarUrl}
					initials={holder.initials}
					className="size-14 bg-violet-100 text-violet-700"
				/>
				<div className="min-w-0 flex-1">
					<div className="flex flex-wrap items-start justify-between gap-3">
						<div>
							<h4 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
								{holder.name}
							</h4>
							<Text className="text-sm text-zinc-500">
								{holder.userType}
							</Text>
						</div>
						<Badge color={holder.statusKey === "active" ? "emerald" : "zinc"}>
							{holder.status}
						</Badge>
					</div>

					<dl className="mt-4 space-y-2 text-sm">
						<div className="flex justify-between gap-4">
							<dt className="text-zinc-500">Correo</dt>
							<dd className="truncate font-medium text-zinc-800 dark:text-slate-100">
								{holder.email ?? "—"}
							</dd>
						</div>
						<div className="flex justify-between gap-4">
							<dt className="text-zinc-500">Teléfono</dt>
							<dd className="font-medium text-zinc-800 dark:text-slate-100">
								{holder.formattedPhone ?? holder.phone ?? "—"}
							</dd>
						</div>
						<div className="flex justify-between gap-4">
							<dt className="text-zinc-500">Nacimiento</dt>
							<dd className="font-medium text-zinc-800 dark:text-slate-100">
								{holder.birthDate ?? "—"}
							</dd>
						</div>
					</dl>

					<div className="mt-5">
						<Button outline href={holder.editUrl} className="w-full sm:w-auto">
							Editar información
						</Button>
					</div>
				</div>
			</div>
		</Card>
	);
}
