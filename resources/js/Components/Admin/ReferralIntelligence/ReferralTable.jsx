import { Avatar } from "@/Components/Catalyst/avatar";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import ReferralBadge from "./ReferralBadge";
import ReferralEmptyState from "./ReferralEmptyState";

export default function ReferralTable({
	inviters,
	view = "table",
	onOpen,
}) {
	const rows = inviters?.data || [];

	if (!rows.length) {
		return <ReferralEmptyState />;
	}

	if (view === "cards") {
		return (
			<div className="space-y-4">
				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
					{rows.map((row) => (
						<button
							key={row.id}
							type="button"
							onClick={() => onOpen?.(row)}
							className="rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="flex items-start gap-3">
								<Avatar src={row.avatar} className="size-11" />
								<div className="min-w-0 flex-1">
									<p className="truncate font-semibold text-zinc-900 dark:text-zinc-50">
										{row.name}
									</p>
									<p className="truncate text-xs text-zinc-500">{row.email}</p>
									<div className="mt-2">
										{row.level ? (
											<ReferralBadge
												tone={row.level.key}
												label={row.level.label}
												medal={row.level.medal}
											/>
										) : null}
									</div>
								</div>
							</div>
							<div className="mt-4 grid grid-cols-3 gap-2 border-t border-zinc-100 pt-3 text-center dark:border-zinc-800">
								<div>
									<p className="text-[10px] uppercase text-zinc-400">Referidos</p>
									<p className="font-semibold tabular-nums">{row.referrals}</p>
								</div>
								<div>
									<p className="text-[10px] uppercase text-zinc-400">Conv.</p>
									<p className="font-semibold tabular-nums">{row.conversion}%</p>
								</div>
								<div>
									<p className="text-[10px] uppercase text-zinc-400">Ingresos</p>
									<p className="text-xs font-semibold">{row.revenue_formatted}</p>
								</div>
							</div>
						</button>
					))}
				</div>
				{inviters.last_page > 1 ? <PaginatedTable paginatedData={inviters} /> : null}
			</div>
		);
	}

	return (
		<div className="space-y-4">
			<div className="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
				<Table dense>
					<TableHead>
						<TableRow>
							<TableHeader>Invitador</TableHeader>
							<TableHeader>Empresa</TableHeader>
							<TableHeader>Referidos</TableHeader>
							<TableHeader>Clientes</TableHeader>
							<TableHeader>Conversión</TableHeader>
							<TableHeader>Ingresos</TableHeader>
							<TableHeader>Créditos</TableHeader>
							<TableHeader>Última invitación</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader className="text-right">Acciones</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.map((row) => (
							<TableRow key={row.id}>
								<TableCell>
									<div className="flex items-center gap-3">
										<Avatar src={row.avatar} className="size-9" />
										<div className="min-w-0">
											<p className="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50">
												{row.name}
											</p>
											<p className="truncate text-xs text-zinc-500">{row.email}</p>
											{row.level ? (
												<div className="mt-1">
													<ReferralBadge
														tone={row.level.key}
														label={row.level.label}
														medal={row.level.medal}
													/>
												</div>
											) : null}
										</div>
									</div>
								</TableCell>
								<TableCell>
									<span className="text-sm text-zinc-600 dark:text-zinc-300">
										{row.company || "—"}
									</span>
								</TableCell>
								<TableCell className="tabular-nums">{row.referrals}</TableCell>
								<TableCell className="tabular-nums">{row.buyers}</TableCell>
								<TableCell className="tabular-nums">{row.conversion}%</TableCell>
								<TableCell className="text-sm">{row.revenue_formatted}</TableCell>
								<TableCell className="text-sm">{row.credits_formatted}</TableCell>
								<TableCell className="text-sm text-zinc-500">
									{row.last_referral_at || "—"}
								</TableCell>
								<TableCell>
									<ReferralBadge
										tone={row.status}
										label={row.status_label || row.status}
									/>
								</TableCell>
								<TableCell className="text-right">
									<Button outline onClick={() => onOpen?.(row)}>
										Abrir
									</Button>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
			{inviters.last_page > 1 ? <PaginatedTable paginatedData={inviters} /> : null}
		</div>
	);
}
