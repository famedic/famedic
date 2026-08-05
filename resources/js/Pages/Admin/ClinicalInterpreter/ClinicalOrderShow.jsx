import { useEffect, useRef, useState } from "react";
import { Link, router } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label, Description } from "@/Components/Catalyst/fieldset";
import {
	ArrowRightIcon,
	ChevronRightIcon,
	DocumentIcon,
	SparklesIcon,
} from "@heroicons/react/16/solid";
import { PRODUCT_SCOPE } from "@/Components/Admin/ClinicalInterpreter/productScope";
import { getCsrf } from "@/Components/Admin/ClinicalInterpreter/Assistant/interpretApi";
import AiReviewCenter from "@/Components/Admin/ClinicalInterpreter/AiReview/AiReviewCenter";

const BRIDGE_PROGRESS = [
	{ id: "preparing_cart", label: "Preparando carrito…" },
	{ id: "creating_studies", label: "Creando estudios…" },
	{ id: "generating_checkout", label: "Generando Checkout…" },
	{ id: "opening", label: "Abriendo Famedic…" },
];

const INTEGRATION_STEPS = [
	{ id: "preparing_cart", pending: "Preparando carrito", done: "Carrito listo" },
	{ id: "cart_created", pending: "Creando estudios", done: "Carrito creado" },
	{ id: "checkout_generated", pending: "Generando Checkout", done: "Checkout generado" },
	{ id: "link_ready", pending: "Preparando enlace", done: "Enlace listo" },
];

function statusTone(status) {
	switch (status) {
		case "validated":
		case "completed":
			return "emerald";
		case "quote_prepared":
		case "cart_prepared":
		case "checkout_started":
			return "sky";
		case "cancelled":
			return "red";
		case "draft":
		case "interpreted":
			return "amber";
		default:
			return "zinc";
	}
}

function formatWhen(iso) {
	if (!iso) return null;
	try {
		return new Date(iso).toLocaleString("es-MX", {
			dateStyle: "medium",
			timeStyle: "short",
		});
	} catch {
		return null;
	}
}

function SectionLabel({ children }) {
	return (
		<p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
			{children}
		</p>
	);
}

function Divider() {
	return <div className="border-t border-zinc-100 dark:border-zinc-800" />;
}

function CheckRow({ children }) {
	return (
		<li className="flex items-start gap-2.5 text-sm text-zinc-700 dark:text-zinc-200">
			<span
				className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-[10px] font-bold text-white"
				aria-hidden
			>
				✓
			</span>
			<span className="min-w-0 leading-snug">{children}</span>
		</li>
	);
}

function TimelineDot({ done, current, muted }) {
	return (
		<span
			className={`mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold ${
				done
					? "bg-emerald-600 text-white"
					: current
						? "bg-famedic-dark text-white dark:bg-famedic-light dark:text-zinc-950"
						: muted
							? "border border-zinc-300 bg-transparent text-zinc-400 dark:border-zinc-600"
							: "bg-zinc-200 text-zinc-500 dark:bg-zinc-800"
			}`}
		>
			{done ? "✓" : "○"}
		</span>
	);
}

function findTimelineEvent(events, id) {
	return (events || []).find((e) => e.id === id) || null;
}

/**
 * Dual-world timeline: AI Interpreter (done) → Checkout Famedic (ahead).
 */
function DualTimeline({ order, prepareError, bridgePhase }) {
	const summary = order?.summary || {};
	const status = summary.status;
	const checkout = order?.integrations?.checkout || {};
	const events = order?.integrations?.timeline?.events || [];
	const completed = status === "completed";
	const checkoutStarted =
		status === "checkout_started" ||
		Boolean(checkout.checkout_url) ||
		Boolean(findTimelineEvent(events, "checkout_prepared"));
	const paidAt =
		checkout.paid_at ||
		findTimelineEvent(events, "payment_completed")?.at ||
		null;
	const startedAt =
		findTimelineEvent(events, "checkout_prepared")?.at ||
		checkout.prepared_at ||
		null;

	const pastValidated = [
		"validated",
		"quote_prepared",
		"cart_prepared",
		"checkout_started",
		"completed",
	].includes(status);

	const aiSteps = [
		{
			id: "interpret",
			label: "Interpretación",
			detail: formatWhen(order?.document?.uploaded_at) || "Receta leída",
			done: Boolean(order?.document || order?.interpretation),
		},
		{
			id: "validate",
			label: "Validación",
			detail: formatWhen(summary.validated_at) || "Estudios confirmados",
			done: pastValidated || Boolean(summary.validated_at),
		},
		{
			id: "order",
			label: "Laboratory Order",
			detail: formatWhen(summary.created_at) || "Expediente generado",
			done: true,
		},
	];

	const checkoutSteps = [
		{
			id: "patient",
			label: "Paciente",
			detail: checkoutStarted && !completed ? "Paso de entrada" : null,
			done: completed,
			current: checkoutStarted && !completed,
		},
		{
			id: "address",
			label: "Dirección",
			done: completed,
			current: false,
		},
		{
			id: "payment",
			label: "Pago",
			done: completed,
			current: false,
		},
		{
			id: "appointment",
			label: "Cita",
			detail: "Cuando aplique",
			done: completed,
			current: false,
		},
		{
			id: "purchase",
			label: "Compra",
			detail: completed
				? formatWhen(paidAt) || "Completada"
				: null,
			done: completed,
			current: false,
		},
	];

	return (
		<section className="space-y-5">
			<div className="space-y-3">
				<SectionLabel>AI Laboratory Interpreter</SectionLabel>
				<ol className="space-y-2">
					{aiSteps.map((step) => (
						<li key={step.id} className="flex items-start gap-3 px-1 py-1">
							<TimelineDot done={step.done} />
							<div className="min-w-0 pt-0.5">
								<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
									{step.label}
								</p>
								{step.detail && (
									<p className="text-xs text-zinc-400">{step.detail}</p>
								)}
							</div>
						</li>
					))}
				</ol>
			</div>

			<div
				className="flex items-center gap-3 px-1"
				role="separator"
				aria-label="Handoff al Checkout"
			>
				<div className="h-px flex-1 bg-zinc-200 dark:bg-zinc-700" />
				<span className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-famedic-dark dark:text-famedic-light">
					<ArrowRightIcon className="size-3" aria-hidden />
					Handoff
				</span>
				<div className="h-px flex-1 bg-zinc-200 dark:bg-zinc-700" />
			</div>

			<div className="space-y-3">
				<div className="flex flex-wrap items-baseline justify-between gap-2">
					<SectionLabel>Checkout Famedic</SectionLabel>
					{completed && (
						<span className="text-xs font-medium text-emerald-700 dark:text-emerald-400">
							Compra completada
							{formatWhen(paidAt) ? ` · ${formatWhen(paidAt)}` : ""}
						</span>
					)}
					{!completed && checkoutStarted && (
						<span className="text-xs font-medium text-sky-700 dark:text-sky-400">
							Checkout iniciado
							{formatWhen(startedAt) ? ` · ${formatWhen(startedAt)}` : ""}
						</span>
					)}
					{!completed && !checkoutStarted && prepareError && (
						<span className="text-xs font-medium text-amber-700 dark:text-amber-400">
							Preparación incompleta
						</span>
					)}
					{!completed &&
						!checkoutStarted &&
						!prepareError &&
						bridgePhase === "idle" && (
							<span className="text-xs text-zinc-400">
								Pendiente de continuar
							</span>
						)}
				</div>

				<ol className="space-y-2 rounded-xl border border-zinc-100 bg-zinc-50/60 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-950/40">
					{checkoutSteps.map((step) => (
						<li key={step.id} className="flex items-start gap-3 px-1 py-0.5">
							<TimelineDot
								done={step.done}
								current={step.current}
								muted={!step.done && !step.current}
							/>
							<div className="min-w-0 pt-0.5">
								<p
									className={`text-sm font-medium ${
										step.done || step.current
											? "text-zinc-900 dark:text-zinc-50"
											: "text-zinc-400"
									}`}
								>
									{step.label}
								</p>
								{step.detail && (
									<p className="text-xs text-zinc-400">{step.detail}</p>
								)}
							</div>
						</li>
					))}
				</ol>
			</div>
		</section>
	);
}

function CheckoutPreview() {
	const steps = ["Paciente", "Dirección", "Pago", "Cita (cuando aplique)", "Compra"];

	return (
		<div className="rounded-xl border border-dashed border-zinc-300 bg-white/70 px-4 py-4 dark:border-zinc-600 dark:bg-zinc-900/50">
			<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
				Checkout Famedic
			</p>
			<ul className="mt-3 space-y-2" aria-hidden>
				{steps.map((label) => (
					<li
						key={label}
						className="flex items-center gap-2.5 text-sm text-zinc-500"
					>
						<span className="flex size-4 items-center justify-center text-zinc-300">
							○
						</span>
						{label}
					</li>
				))}
			</ul>
			<p className="mt-3 text-xs leading-relaxed text-zinc-400">
				Estos pasos continuarán en el Checkout existente.
			</p>
		</div>
	);
}

function IntegrationStatus({ phase, error, ready }) {
	if (phase === "idle" && !ready && !error) return null;

	const phaseOrder = [
		"preparing_cart",
		"creating_studies",
		"generating_checkout",
		"opening",
		"ready",
	];
	const currentIdx =
		phase === "error"
			? -1
			: phase === "ready" || ready
				? phaseOrder.length
				: Math.max(0, phaseOrder.indexOf(phase));

	const visualSteps = INTEGRATION_STEPS.map((step, idx) => {
		if (error && phase === "error") {
			return { ...step, state: "error" };
		}
		if (ready || phase === "ready" || currentIdx > idx) {
			return { ...step, state: "done" };
		}
		if (currentIdx === idx) {
			return { ...step, state: "active" };
		}
		return { ...step, state: "pending" };
	});

	return (
		<div
			className={`rounded-xl border px-4 py-4 ${
				error
					? "border-amber-200/80 bg-amber-50/60 dark:border-amber-800/40 dark:bg-amber-950/20"
					: "border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
			}`}
		>
			<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
				Estado de integración
			</p>

			{error ? (
				<p className="mt-3 text-sm font-medium text-amber-900 dark:text-amber-200">
					❌ No fue posible preparar el Checkout.
				</p>
			) : (
				<ul className="mt-3 space-y-2">
					{visualSteps.map((step) => (
						<li
							key={step.id}
							className="flex items-center gap-2.5 text-sm text-zinc-700 dark:text-zinc-200"
						>
							<span className="w-4 shrink-0 text-center" aria-hidden>
								{step.state === "done"
									? "🟢"
									: step.state === "active"
										? "🟡"
										: "○"}
							</span>
							<span
								className={
									step.state === "pending" ? "text-zinc-400" : undefined
								}
							>
								{step.state === "done" ? step.done : step.pending}
							</span>
						</li>
					))}
				</ul>
			)}

			{error && (
				<p className="mt-2 text-xs leading-relaxed text-amber-800/80 dark:text-amber-300/80">
					{error}
				</p>
			)}
		</div>
	);
}

/**
 * Laboratory Order = expediente IA que prepara y abre el Checkout Famedic.
 */
export default function ClinicalOrderShow({ order: initialOrder }) {
	const [order, setOrder] = useState(initialOrder);
	const [busy, setBusy] = useState(false);
	const [bridgePhase, setBridgePhase] = useState("idle");
	const [actionMessage, setActionMessage] = useState(null);
	const [actionTone, setActionTone] = useState("success");
	const [prepareError, setPrepareError] = useState(null);
	const [checkoutUrl, setCheckoutUrl] = useState(
		initialOrder?.integrations?.checkout?.checkout_url || null,
	);

	const [customerQuery, setCustomerQuery] = useState("");
	const [customerResults, setCustomerResults] = useState([]);
	const [searchingCustomers, setSearchingCustomers] = useState(false);
	const [selectedCustomer, setSelectedCustomer] = useState(null);

	const progressTimers = useRef([]);

	useEffect(() => {
		setOrder(initialOrder);
		setCheckoutUrl(initialOrder?.integrations?.checkout?.checkout_url || null);
	}, [initialOrder]);

	useEffect(() => {
		return () => {
			progressTimers.current.forEach(clearTimeout);
		};
	}, []);

	const summary = order?.summary || {};
	const patient = order?.patient || {};
	const studies = order?.studies || [];
	const documentMeta = order?.document || {};
	const commercial = order?.commercial || {};
	const checkout = order?.integrations?.checkout || {};
	const events = order?.integrations?.timeline?.events || [];

	const commercialSummary = commercial.summary || {};
	const participatingLabs = [
		...new Set(studies.map((s) => s.laboratory).filter(Boolean)),
	];
	const primaryLab =
		checkout.brand_label || participatingLabs[0] || null;
	const estimatedTotal =
		commercialSummary.total || summary.total || null;

	const confirmedStudies = studies.filter(
		(s) => (s.status || "confirmed") === "confirmed" && s.laboratory_test_id,
	);
	const canPrepare =
		["validated", "quote_prepared", "cart_prepared", "checkout_started"].includes(
			summary.status,
		) && confirmedStudies.length > 0;

	const checkoutAlreadyReady = Boolean(checkoutUrl);
	const isCompleted = summary.status === "completed";
	const isCheckoutStarted =
		summary.status === "checkout_started" ||
		(checkoutAlreadyReady && !isCompleted);

	const startedAt =
		findTimelineEvent(events, "checkout_prepared")?.at ||
		checkout.prepared_at ||
		null;
	const paidAt =
		checkout.paid_at ||
		findTimelineEvent(events, "payment_completed")?.at ||
		null;

	useEffect(() => {
		if (!customerQuery || customerQuery.trim().length < 2) {
			setCustomerResults([]);
			return undefined;
		}

		const handle = setTimeout(async () => {
			setSearchingCustomers(true);
			try {
				const res = await fetch(
					route("admin.clinical-interpreter.customers.search", {
						q: customerQuery.trim(),
					}),
					{
						headers: {
							Accept: "application/json",
							"X-Requested-With": "XMLHttpRequest",
						},
						credentials: "same-origin",
					},
				);
				const data = await res.json().catch(() => ({}));
				setCustomerResults(data.customers || []);
			} catch {
				setCustomerResults([]);
			} finally {
				setSearchingCustomers(false);
			}
		}, 300);

		return () => clearTimeout(handle);
	}, [customerQuery]);

	const clearProgressTimers = () => {
		progressTimers.current.forEach(clearTimeout);
		progressTimers.current = [];
	};

	const startProgressSequence = () => {
		clearProgressTimers();
		setBridgePhase("preparing_cart");
		BRIDGE_PROGRESS.forEach((step, index) => {
			if (index === 0) return;
			const timer = setTimeout(() => {
				setBridgePhase((current) =>
					current === "ready" || current === "error" ? current : step.id,
				);
			}, index * 700);
			progressTimers.current.push(timer);
		});
	};

	const openCheckout = (url) => {
		if (!url) return;
		window.open(url, "_blank", "noopener,noreferrer");
	};

	const prepareAndOpenCheckout = async () => {
		if (!summary.uuid || !selectedCustomer || busy) return;
		setBusy(true);
		setActionMessage(null);
		setPrepareError(null);
		startProgressSequence();

		try {
			const res = await fetch(
				route("admin.clinical-interpreter.clinical-orders.cart", summary.uuid),
				{
					method: "POST",
					headers: {
						Accept: "application/json",
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
						"X-CSRF-TOKEN": getCsrf(),
					},
					credentials: "same-origin",
					body: JSON.stringify({
						customer_id: selectedCustomer.id,
						contact_id: null,
					}),
				},
			);
			const data = await res.json().catch(() => ({}));

			if (!res.ok || !data.ok) {
				clearProgressTimers();
				const msg =
					data.message ||
					"No se pudo preparar el Checkout. Revisa la cuenta e intenta de nuevo.";
				setBridgePhase("error");
				setActionTone("error");
				setActionMessage(msg);
				setPrepareError(msg);
				setBusy(false);
				return;
			}

			clearProgressTimers();
			setBridgePhase("opening");
			setPrepareError(null);
			setActionTone("success");
			setActionMessage(
				"Checkout listo. Se abrió Famedic — ahí continúan paciente, dirección y pago.",
			);
			setCheckoutUrl(data.checkout_url || null);

			if (data.clinical_order_detail) {
				setOrder(data.clinical_order_detail);
			}

			if (data.checkout_url) {
				openCheckout(data.checkout_url);
			}

			setBridgePhase("ready");
			router.reload({ only: ["order"], preserveScroll: true });
		} catch {
			clearProgressTimers();
			const msg = "Se interrumpió la conexión al preparar el Checkout.";
			setBridgePhase("error");
			setActionTone("error");
			setActionMessage(msg);
			setPrepareError(msg);
		} finally {
			setBusy(false);
		}
	};

	const progressLabel =
		BRIDGE_PROGRESS.find((s) => s.id === bridgePhase)?.label || null;

	const integrationReady =
		bridgePhase === "ready" || (checkoutAlreadyReady && bridgePhase === "idle");

	return (
		<AdminLayout title="Laboratory Order">
			<div className="relative mx-auto max-w-3xl space-y-8 pb-16 pt-1">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<Link
						href={route("admin.clinical-interpreter.index")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						{PRODUCT_SCOPE.productName}
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300" aria-hidden />
					<span className="font-semibold text-zinc-600 dark:text-zinc-300">
						Laboratory Order
					</span>
				</nav>

				{/* Hero */}
				<section className="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white px-5 py-6 dark:border-zinc-700 dark:bg-zinc-900 sm:px-7 sm:py-7">
					<div
						aria-hidden
						className="pointer-events-none absolute -right-10 -top-12 size-44 rounded-full bg-famedic-light/15 blur-3xl"
					/>
					<div className="relative space-y-5">
						<div className="flex flex-wrap items-start justify-between gap-4">
							<div className="flex gap-3 sm:gap-4">
								<span className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-famedic-light/20 text-famedic-dark dark:text-famedic-light">
									<SparklesIcon className="size-5" aria-hidden />
								</span>
								<div className="min-w-0 space-y-2">
									<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
										Expediente generado
									</p>
									<h1 className="text-xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50 sm:text-2xl">
										Laboratory Order
									</h1>
									<p className="max-w-xl text-sm leading-relaxed text-zinc-500">
										La interpretación terminó. Este expediente prepara el
										carrito y entrega el proceso al{" "}
										<span className="font-medium text-zinc-800 dark:text-zinc-100">
											Checkout Famedic
										</span>
										.
									</p>
								</div>
							</div>
							<Badge color={statusTone(summary.status)} className="!text-[11px]">
								{summary.status_label || summary.status || "—"}
							</Badge>
						</div>

						{isCompleted && (
							<div className="rounded-xl border border-emerald-200/80 bg-emerald-50/70 px-4 py-3 dark:border-emerald-800/40 dark:bg-emerald-950/30">
								<p className="text-sm font-semibold text-emerald-900 dark:text-emerald-200">
									Compra completada
								</p>
								{formatWhen(paidAt) && (
									<p className="mt-0.5 text-xs text-emerald-800/80 dark:text-emerald-300/80">
										{formatWhen(paidAt)}
									</p>
								)}
								{checkout.purchase_id && (
									<Button
										plain
										href={route(
											"admin.laboratory-purchases.show",
											checkout.purchase_id,
										)}
										className="mt-2 !text-sm"
									>
										Ver Laboratory Purchase
										<ArrowRightIcon data-slot="icon" />
									</Button>
								)}
							</div>
						)}

						{!isCompleted && isCheckoutStarted && (
							<div className="rounded-xl border border-sky-200/80 bg-sky-50/70 px-4 py-3 dark:border-sky-800/40 dark:bg-sky-950/30">
								<p className="text-sm font-semibold text-sky-900 dark:text-sky-200">
									Checkout iniciado
								</p>
								{formatWhen(startedAt) && (
									<p className="mt-0.5 text-xs text-sky-800/80 dark:text-sky-300/80">
										{formatWhen(startedAt)}
									</p>
								)}
								<p className="mt-1 text-xs text-sky-800/70 dark:text-sky-300/70">
									El proceso continúa en el Checkout Famedic.
								</p>
							</div>
						)}

						{patient.name && (
							<p className="text-sm text-zinc-600 dark:text-zinc-300">
								<span className="text-zinc-400">Detectado en receta · </span>
								<span className="font-medium text-zinc-900 dark:text-zinc-50">
									{patient.name}
								</span>
								<span className="text-zinc-400">
									{" "}
									(se confirma en el checkout)
								</span>
							</p>
						)}
					</div>
				</section>

				{/* Dual timeline */}
				<DualTimeline
					order={order}
					prepareError={prepareError}
					bridgePhase={bridgePhase}
				/>

				<Divider />

				{/* Documento */}
				<section className="space-y-3">
					<SectionLabel>Documento interpretado</SectionLabel>
					<div className="flex items-center gap-3">
						<span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
							<DocumentIcon className="size-5" aria-hidden />
						</span>
						<div className="min-w-0">
							<p className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-50">
								{documentMeta.filename || "Documento de la receta"}
							</p>
							<p className="text-xs text-zinc-400">
								{[documentMeta.mime, formatWhen(documentMeta.uploaded_at)]
									.filter(Boolean)
									.join(" · ") || "Origen de la interpretación"}
							</p>
						</div>
					</div>
				</section>

				<Divider />

				{/* Estudios */}
				<section className="space-y-3">
					<div className="flex items-baseline justify-between gap-3">
						<SectionLabel>Estudios del expediente</SectionLabel>
						<span className="text-sm font-semibold tabular-nums">
							{confirmedStudies.length}
						</span>
					</div>
					{confirmedStudies.length === 0 ? (
						<p className="text-sm text-zinc-400">
							No hay estudios confirmados con catálogo.
						</p>
					) : (
						<ul>
							{confirmedStudies.map((study, idx) => (
								<li
									key={`${study.code || study.name}-${idx}`}
									className="flex items-start justify-between gap-4 border-b border-zinc-100 py-3 last:border-0 dark:border-zinc-800"
								>
									<div className="min-w-0">
										<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
											{study.name || "Estudio"}
										</p>
										<p className="mt-0.5 text-xs text-zinc-400">
											{[study.laboratory, study.code]
												.filter(Boolean)
												.join(" · ")}
										</p>
									</div>
									{study.price && (
										<p className="shrink-0 text-sm tabular-nums text-zinc-600">
											{study.price}
										</p>
									)}
								</li>
							))}
						</ul>
					)}
				</section>

				<Divider />

				{/* FASE 6 — transparencia (no modifica el handoff) */}
				<section className="space-y-4">
					<AiReviewCenter
						order={order}
						showStudyList
						showDecisionHistory
					/>
				</section>

				<Divider />

				{/* Continuidad: checklist + preview */}
				{!isCompleted && confirmedStudies.length > 0 && (
					<section className="space-y-4">
						<div className="space-y-1">
							<SectionLabel>Continuidad</SectionLabel>
							<h2 className="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
								Lo que ocurrirá al continuar
							</h2>
						</div>

						<div className="rounded-2xl border border-zinc-200 bg-white px-5 py-5 dark:border-zinc-700 dark:bg-zinc-900">
							<ul className="space-y-3">
								{confirmedStudies.map((study, idx) => (
									<CheckRow key={`will-${study.laboratory_test_id || idx}`}>
										Se agregará el estudio{" "}
										<span className="font-medium">{study.name || "Estudio"}</span>
									</CheckRow>
								))}
								{primaryLab && (
									<CheckRow>
										Laboratorio seleccionado:{" "}
										<span className="font-medium">{primaryLab}</span>
									</CheckRow>
								)}
								{estimatedTotal && (
									<CheckRow>
										Total estimado:{" "}
										<span className="font-medium">{estimatedTotal}</span>
									</CheckRow>
								)}
								<CheckRow>
									Se abrirá el Checkout Famedic en el paso:{" "}
									<span className="font-medium">Paciente</span>
								</CheckRow>
							</ul>
						</div>

						<CheckoutPreview />
					</section>
				)}

				{/* Handoff */}
				{!isCompleted && (
					<section className="space-y-5 rounded-2xl border border-famedic-light/30 bg-famedic-light/5 px-5 py-6 dark:border-famedic-light/20">
						<div className="space-y-1.5">
							<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-famedic-dark dark:text-famedic-light">
								Siguiente paso
							</p>
							<h2 className="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
								Continuar al Checkout
							</h2>
							<p className="max-w-lg text-sm leading-relaxed text-zinc-500">
								El AI Laboratory Interpreter entrega el proceso al Checkout
								Famedic existente. Un solo flujo, sin pasos duplicados.
							</p>
						</div>

						{canPrepare && (
							<div className="space-y-2">
								<Field>
									<Label>Cuenta Famedic</Label>
									<Description>
										Selecciona la cuenta que continuará el Checkout.
									</Description>
									<Input
										value={customerQuery}
										onChange={(e) => setCustomerQuery(e.target.value)}
										placeholder="Buscar por nombre, correo o teléfono"
										className="mt-2"
									/>
								</Field>

								{searchingCustomers && (
									<p className="text-xs text-zinc-400">Buscando…</p>
								)}

								{customerResults.length > 0 && (
									<ul className="max-h-44 space-y-1 overflow-y-auto">
										{customerResults.map((customer) => (
											<li key={customer.id}>
												<button
													type="button"
													onClick={() => {
														setSelectedCustomer(customer);
														setCustomerResults([]);
														setCustomerQuery(customer.name || "");
													}}
													className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-left text-sm hover:border-famedic-light/50 dark:border-zinc-700 dark:bg-zinc-900"
												>
													<p className="font-medium text-zinc-900 dark:text-zinc-50">
														{customer.name}
													</p>
													<p className="text-xs text-zinc-400">
														{[customer.email, customer.phone]
															.filter(Boolean)
															.join(" · ")}
													</p>
												</button>
											</li>
										))}
									</ul>
								)}

								{selectedCustomer && (
									<p className="text-sm text-zinc-700 dark:text-zinc-200">
										Cuenta:{" "}
										<span className="font-medium">{selectedCustomer.name}</span>
									</p>
								)}
							</div>
						)}

						{(busy ||
							bridgePhase === "error" ||
							bridgePhase === "ready" ||
							(checkoutAlreadyReady && bridgePhase !== "idle")) && (
							<IntegrationStatus
								phase={bridgePhase}
								error={prepareError}
								ready={integrationReady && bridgePhase !== "error"}
							/>
						)}

						{checkout.brand_label && checkoutAlreadyReady && !busy && (
							<p className="text-xs text-zinc-500">
								Marca del carrito · {checkout.brand_label}
								{checkout.customer_name ? ` · ${checkout.customer_name}` : ""}
							</p>
						)}

						{actionMessage && bridgePhase !== "error" && (
							<p
								className={`text-sm ${
									actionTone === "error"
										? "text-amber-800 dark:text-amber-300"
										: "text-emerald-800 dark:text-emerald-300"
								}`}
							>
								{actionMessage}
							</p>
						)}

						<div className="flex flex-wrap gap-2.5">
							{canPrepare && (
								<Button
									disabled={!selectedCustomer || busy}
									onClick={prepareAndOpenCheckout}
									className="!text-sm"
								>
									{busy
										? progressLabel || "Preparando…"
										: "Continuar al Checkout"}
									{!busy && <ArrowRightIcon data-slot="icon" />}
								</Button>
							)}

							{checkoutAlreadyReady && !busy && (
								<Button
									outline
									onClick={() => openCheckout(checkoutUrl)}
									className="!text-sm"
								>
									Abrir Checkout Famedic
								</Button>
							)}

							{checkout.purchase_id && (
								<Button
									outline
									href={route(
										"admin.laboratory-purchases.show",
										checkout.purchase_id,
									)}
									className="!text-sm"
								>
									Ver compra
								</Button>
							)}
						</div>

						{!canPrepare && (
							<p className="text-xs text-zinc-400">
								Se necesita al menos un estudio confirmado con catálogo para
								continuar al Checkout.
							</p>
						)}
					</section>
				)}

				{isCompleted && checkout.purchase_id && (
					<section className="rounded-2xl border border-emerald-200/60 bg-emerald-50/40 px-5 py-5 dark:border-emerald-800/30 dark:bg-emerald-950/20">
						<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Compra completada
						</p>
						{formatWhen(paidAt) && (
							<p className="mt-1 text-xs text-zinc-500">{formatWhen(paidAt)}</p>
						)}
						<Button
							className="mt-3 !text-sm"
							href={route(
								"admin.laboratory-purchases.show",
								checkout.purchase_id,
							)}
						>
							Ver Laboratory Purchase
							<ArrowRightIcon data-slot="icon" />
						</Button>
					</section>
				)}

				<div className="flex flex-wrap justify-between gap-2 pt-2">
					<Button
						plain
						href={route("admin.clinical-interpreter.orders.index")}
						className="!text-sm text-zinc-500"
					>
						Ver listado
					</Button>
					<Button
						outline
						href={route("admin.clinical-interpreter.assistant")}
						className="!text-sm"
					>
						Nueva interpretación
					</Button>
				</div>
			</div>
		</AdminLayout>
	);
}
