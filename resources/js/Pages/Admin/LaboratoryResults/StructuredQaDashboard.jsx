import { useState } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import BillingMetricCard from "@/Components/Admin/LaboratoryBilling/BillingMetricCard";
import * as Headless from "@headlessui/react";
import { XMarkIcon } from "@heroicons/react/16/solid";

const REFERENCE_BADGE = {
	normal: "emerald",
	low: "amber",
	high: "red",
	unknown: "zinc",
	not_applicable: "zinc",
};

const PROMOTION_BADGE = {
	validated: "emerald",
	needs_review: "amber",
	rejected: "red",
	shadow: "zinc",
};

const APPROVAL_BADGE = {
	pending: "amber",
	approved: "emerald",
	rejected: "red",
};

function KpiGrid({ summary = [] }) {
	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
			{summary.map((card) => (
				<BillingMetricCard
					key={card.id}
					label={card.label}
					value={card.value}
					tone={card.tone || "default"}
				/>
			))}
		</div>
	);
}

function Filters({ filters, filterOptions }) {
	const apply = (event) => {
		event.preventDefault();
		const form = new FormData(event.currentTarget);
		const next = Object.fromEntries(form.entries());
		router.get(route("admin.laboratory-results.shadow-qa"), next, {
			preserveState: true,
			preserveScroll: true,
		});
	};

	return (
		<form onSubmit={apply} className="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
			<label className="block text-xs">
				<span className="text-zinc-500">Analyte</span>
				<select
					name="analyte_code"
					defaultValue={filters.analyte_code || ""}
					className="mt-1 w-full rounded-lg border border-zinc-200 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<option value="">Todos</option>
					{(filterOptions.analyte_codes || []).map((code) => (
						<option key={code} value={code}>
							{code}
						</option>
					))}
				</select>
			</label>
			<label className="block text-xs">
				<span className="text-zinc-500">Out-of-range</span>
				<select
					name="reference_status"
					defaultValue={filters.reference_status || ""}
					className="mt-1 w-full rounded-lg border border-zinc-200 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<option value="">Todos</option>
					{(filterOptions.reference_statuses || []).map((status) => (
						<option key={status} value={status}>
							{status}
						</option>
					))}
				</select>
			</label>
			<label className="block text-xs">
				<span className="text-zinc-500">Promotion</span>
				<select
					name="promotion_status"
					defaultValue={filters.promotion_status || ""}
					className="mt-1 w-full rounded-lg border border-zinc-200 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<option value="">Todos</option>
					{(filterOptions.promotion_statuses || []).map((status) => (
						<option key={status} value={status}>
							{status}
						</option>
					))}
				</select>
			</label>
			<label className="block text-xs">
				<span className="text-zinc-500">Approval</span>
				<select
					name="approval_status"
					defaultValue={filters.approval_status || ""}
					className="mt-1 w-full rounded-lg border border-zinc-200 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<option value="">Todos</option>
					{(filterOptions.approval_statuses || []).map((status) => (
						<option key={status} value={status}>
							{status}
						</option>
					))}
				</select>
			</label>
			<label className="block text-xs">
				<span className="text-zinc-500">Version ID</span>
				<select
					name="version_id"
					defaultValue={filters.version_id || ""}
					className="mt-1 w-full rounded-lg border border-zinc-200 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<option value="">Todas</option>
					{(filterOptions.version_ids || []).map((id) => (
						<option key={id} value={id}>
							v{id}
						</option>
					))}
				</select>
			</label>
			<div className="flex items-end">
				<Button type="submit">Filtrar</Button>
			</div>
		</form>
	);
}

function ApproveConfirmDialog({ open, onClose, onConfirm, processing, reason, setReason }) {
	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-[60]">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/50" />
			<div className="fixed inset-0 flex items-center justify-center p-4">
				<Headless.DialogPanel className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-zinc-900">
					<Headless.DialogTitle className="text-lg font-semibold">
						Aprobar para futura publicación
					</Headless.DialogTitle>
					<p className="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
						Este resultado está validado técnicamente y quedará aprobado para una futura
						publicación al paciente. Esta acción NO publica todavía el resultado.
					</p>
					<label className="mt-4 block text-xs">
						<span className="text-zinc-500">Razón (opcional)</span>
						<textarea
							value={reason}
							onChange={(e) => setReason(e.target.value)}
							rows={3}
							maxLength={500}
							className="mt-1 w-full rounded-lg border border-zinc-200 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
							placeholder="QA review completed for pilot publication."
						/>
					</label>
					<div className="mt-5 flex justify-end gap-2">
						<Button outline onClick={onClose} disabled={processing}>
							Cancel
						</Button>
						<Button color="emerald" onClick={onConfirm} disabled={processing}>
							Approve
						</Button>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}

function PublishConfirmDialog({ open, onClose, onConfirm, processing, detail }) {
	const preview = detail?.publication?.preview || {};

	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-[60]">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/50" />
			<div className="fixed inset-0 flex items-center justify-center p-4">
				<Headless.DialogPanel className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-zinc-900">
					<Headless.DialogTitle className="text-lg font-semibold">
						Publish Result
					</Headless.DialogTitle>
					<p className="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
						Esta acción hará que el resultado estructurado esté disponible para el paciente.
					</p>
					<ul className="mt-4 space-y-1 text-xs text-zinc-600 dark:text-zinc-400">
						<li>Report: {preview.report_id ?? detail?.report_id ?? "—"}</li>
						<li>Version: {preview.version_id ?? "—"}</li>
						<li>Observations: {preview.observation_count ?? "—"}</li>
						<li>Promotion validated: {preview.promotion_validated ? "yes" : "no"}</li>
						<li>Approval: {preview.approval_status ?? detail?.approval?.status ?? "—"}</li>
						<li>PII-safe: {preview.pii_safe ? "yes" : "no"}</li>
					</ul>
					<div className="mt-5 flex justify-end gap-2">
						<Button outline onClick={onClose} disabled={processing}>
							Cancel
						</Button>
						<Button color="sky" onClick={onConfirm} disabled={processing}>
							Publish Result
						</Button>
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}

function DetailDrawer({ open, detail, onClose, onApproveClick, onRejectClick, onPublishClick }) {
	return (
		<Headless.Dialog open={open} onClose={onClose} className="relative z-50">
			<Headless.DialogBackdrop className="fixed inset-0 bg-zinc-950/40" />
			<div className="fixed inset-0 flex justify-end">
				<Headless.DialogPanel className="flex h-full w-full max-w-lg flex-col bg-white shadow-xl dark:bg-zinc-900">
					<div className="flex items-start justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
						<div>
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
								Detalle Shadow QA
							</p>
							<Headless.DialogTitle className="text-lg font-semibold">
								{detail?.identity?.code || "Observación"}
							</Headless.DialogTitle>
						</div>
						<button type="button" onClick={onClose} className="rounded p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">
							<XMarkIcon className="size-5" />
						</button>
					</div>
					<div className="flex-1 overflow-y-auto px-5 py-4 text-sm space-y-4">
						{detail ? (
							<>
								<section>
									<h3 className="font-semibold text-zinc-900 dark:text-zinc-100">Why is this result here?</h3>
									<p className="mt-1 text-zinc-600 dark:text-zinc-400">{detail.why_here}</p>
								</section>
								<section>
									<h3 className="font-semibold">Promotion</h3>
									<Badge color={PROMOTION_BADGE[detail.qa.promotion_status] || "zinc"}>
										{(detail.qa.promotion_status || "shadow").toUpperCase()}
									</Badge>
									{detail.qa.reasons?.length ? (
										<ul className="mt-2 list-disc pl-5 text-zinc-600 dark:text-zinc-400">
											{detail.qa.reasons.map((reason) => (
												<li key={reason}>{reason}</li>
											))}
										</ul>
									) : null}
									<p className="mt-2 text-xs text-zinc-500">Gate: {detail.qa.gate_version || "—"}</p>
								</section>
								<section>
									<h3 className="font-semibold">Publication Approval</h3>
									<Badge color={APPROVAL_BADGE[detail.approval?.status] || "zinc"}>
										{(detail.approval?.status || "pending").toUpperCase()}
									</Badge>
									{detail.approval?.approved_by_name ? (
										<div className="mt-2 text-xs text-zinc-600 dark:text-zinc-400 space-y-1">
											<p>Approved by: {detail.approval.approved_by_name}</p>
											<p>Approved at: {detail.approval.approved_at}</p>
											{detail.approval.reason ? <p>Reason: {detail.approval.reason}</p> : null}
										</div>
									) : null}
									{detail.approval?.rejected_by_name ? (
										<div className="mt-2 text-xs text-zinc-600 dark:text-zinc-400 space-y-1">
											<p>Rejected by: {detail.approval.rejected_by_name}</p>
											<p>Rejected at: {detail.approval.rejected_at}</p>
											{detail.approval.rejection_reason ? (
												<p>Reason: {detail.approval.rejection_reason}</p>
											) : null}
										</div>
									) : null}
									{detail.approval?.can_approve ? (
										<div className="mt-3">
											<Button color="emerald" onClick={onApproveClick}>
												Approve for publication
											</Button>
										</div>
									) : null}
									{detail.approval?.block_reasons?.length ? (
										<ul className="mt-2 list-disc pl-5 text-xs text-amber-700 dark:text-amber-400">
											{detail.approval.block_reasons.map((reason) => (
												<li key={reason}>{reason}</li>
											))}
										</ul>
									) : null}
									{detail.approval?.can_reject && detail.approval?.status === "pending" ? (
										<div className="mt-2">
											<Button outline color="red" onClick={onRejectClick}>
												Reject approval
											</Button>
										</div>
									) : null}
								</section>
								{detail.approval?.status === "approved" ? (
									<section>
										<h3 className="font-semibold">Controlled Publication</h3>
										{detail.publication?.can_publish ? (
											<div className="mt-2">
												<Button color="sky" onClick={onPublishClick}>
													Publish
												</Button>
											</div>
										) : (
											<ul className="mt-2 list-disc pl-5 text-xs text-amber-700 dark:text-amber-400">
												{(detail.publication?.block_reasons || ["Publication not eligible"]).map((reason) => (
													<li key={reason}>{reason}</li>
												))}
											</ul>
										)}
										<p className="mt-2 text-xs text-zinc-500">
											Gate: {detail.publication?.gate_version || "—"} · Feature:{" "}
											{detail.publication?.feature_enabled ? "enabled" : "disabled"}
										</p>
									</section>
								) : null}
								<section>
									<h3 className="font-semibold">Identidad</h3>
									<dl className="mt-1 grid grid-cols-2 gap-2 text-xs">
										<div><dt className="text-zinc-500">Value</dt><dd>{detail.identity.value}</dd></div>
										<div><dt className="text-zinc-500">Unit</dt><dd>{detail.identity.unit_raw || detail.identity.unit}</dd></div>
									</dl>
								</section>
								<section>
									<h3 className="font-semibold">Referencia / Out-of-range</h3>
									<p className="text-xs text-zinc-600">{detail.reference.text || "—"}</p>
									<p className="text-xs text-zinc-500 mt-1">
										Status: {detail.out_of_range.status} · Method: {detail.out_of_range.method || "—"}
									</p>
								</section>
								<section>
									<h3 className="font-semibold">Extracción</h3>
									<p className="text-xs text-zinc-600">
										{detail.extraction.method} · conf {detail.extraction.confidence ?? "—"} · p.{detail.extraction.source_page ?? "—"}
									</p>
								</section>
							</>
						) : (
							<p className="text-zinc-500">Selecciona una observación.</p>
						)}
					</div>
				</Headless.DialogPanel>
			</div>
		</Headless.Dialog>
	);
}

export default function StructuredQaDashboard({ filters, filterOptions, summary, rows, detail, meta }) {
	const [selectedId, setSelectedId] = useState(null);
	const [approveOpen, setApproveOpen] = useState(false);
	const [publishOpen, setPublishOpen] = useState(false);
	const [approveReason, setApproveReason] = useState("");
	const pageDetail = usePage().props.detail;
	const rejectForm = useForm({ reason: "" });
	const approveForm = useForm({ reason: "" });
	const publishForm = useForm({});

	const openDetail = (row) => {
		setSelectedId(row.id);
		router.get(
			route("admin.laboratory-results.shadow-qa"),
			{ ...filters, observation_id: row.id },
			{ preserveState: true, preserveScroll: true, only: ["detail"] },
		);
	};

	const closeDetail = () => {
		setSelectedId(null);
		setApproveOpen(false);
		router.get(route("admin.laboratory-results.shadow-qa"), filters, {
			preserveState: true,
			preserveScroll: true,
			only: ["detail"],
		});
	};

	const activeDetail = pageDetail?.id === selectedId ? pageDetail : detail;

	const confirmApprove = () => {
		if (!activeDetail?.report_id) {
			return;
		}

		approveForm.setData("reason", approveReason);
		approveForm.post(route("admin.laboratory-results.shadow-qa.approve", activeDetail.report_id), {
			preserveScroll: true,
			onSuccess: () => {
				setApproveOpen(false);
				setApproveReason("");
			},
		});
	};

	const confirmPublish = () => {
		if (!activeDetail?.report_id) {
			return;
		}

		publishForm.post(route("admin.laboratory-results.shadow-qa.publish", activeDetail.report_id), {
			preserveScroll: true,
			onSuccess: () => setPublishOpen(false),
		});
	};

	const rejectApproval = () => {
		if (!activeDetail?.report_id) {
			return;
		}

		const reason = window.prompt("Razón del rechazo (requerida):");
		if (!reason || !reason.trim()) {
			return;
		}

		rejectForm.setData("reason", reason.trim());
		rejectForm.post(route("admin.laboratory-results.shadow-qa.reject", activeDetail.report_id), {
			preserveScroll: true,
		});
	};

	return (
		<AdminLayout title="Laboratory Results — Shadow QA">
			<div className="space-y-6 pb-8">
				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Laboratory Results — Shadow QA</Heading>
						<Badge color="zinc">QA only</Badge>
						<Badge color="sky">{meta?.phase}</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Validación técnica y aprobación humana antes de futura publicación. Sin PII. Aprobación ≠ publicación.
					</Text>
				</div>

				<KpiGrid summary={summary} />

				<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					<p className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">
						Publication Approval
					</p>
					<Filters filters={filters} filterOptions={filterOptions} />
				</div>

				<div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
					<table className="min-w-full text-left text-sm">
						<thead className="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700">
							<tr>
								<th className="px-4 py-3">PDF / Version</th>
								<th className="px-4 py-3">Analyte</th>
								<th className="px-4 py-3">Value</th>
								<th className="px-4 py-3">Reference</th>
								<th className="px-4 py-3">Out-of-range</th>
								<th className="px-4 py-3">Confidence</th>
								<th className="px-4 py-3">Promotion</th>
								<th className="px-4 py-3">Approval</th>
								<th className="px-4 py-3"></th>
							</tr>
						</thead>
						<tbody>
							{rows.map((row) => (
								<tr key={row.id} className="border-b border-zinc-100 dark:border-zinc-800">
									<td className="px-4 py-3">
										<div className="font-medium">{row.pdf}</div>
										<div className="text-xs text-zinc-500">v{row.version_id}</div>
									</td>
									<td className="px-4 py-3">
										<div>{row.analyte}</div>
										<div className="text-xs text-zinc-500">{row.analyte_code}</div>
									</td>
									<td className="px-4 py-3">
										{row.value} {row.unit}
									</td>
									<td className="px-4 py-3 text-xs">{row.reference || "—"}</td>
									<td className="px-4 py-3">
										<Badge color={REFERENCE_BADGE[row.reference_status] || "zinc"}>
											{(row.reference_status || "unknown").toUpperCase()}
										</Badge>
									</td>
									<td className="px-4 py-3">{row.confidence ?? "—"}</td>
									<td className="px-4 py-3">
										<Badge color={PROMOTION_BADGE[row.promotion_status] || "zinc"}>
											{(row.promotion_status || "shadow").toUpperCase()}
										</Badge>
									</td>
									<td className="px-4 py-3">
										<Badge color={APPROVAL_BADGE[row.approval_status] || "zinc"}>
											{(row.approval_status || "—").toUpperCase()}
										</Badge>
									</td>
									<td className="px-4 py-3">
										<Button outline onClick={() => openDetail(row)}>
											Detalle
										</Button>
									</td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			</div>

			<DetailDrawer
				open={selectedId !== null}
				detail={activeDetail}
				onClose={closeDetail}
				onApproveClick={() => setApproveOpen(true)}
				onRejectClick={rejectApproval}
				onPublishClick={() => setPublishOpen(true)}
			/>

			<ApproveConfirmDialog
				open={approveOpen}
				onClose={() => setApproveOpen(false)}
				onConfirm={confirmApprove}
				processing={approveForm.processing}
				reason={approveReason}
				setReason={setApproveReason}
			/>

			<PublishConfirmDialog
				open={publishOpen}
				onClose={() => setPublishOpen(false)}
				onConfirm={confirmPublish}
				processing={publishForm.processing}
				detail={activeDetail}
			/>
		</AdminLayout>
	);
}
