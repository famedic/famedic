import { Subheading, Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import AdminLayout from "@/Layouts/AdminLayout";
import PurchasesChart from "@/Components/PurchasesChart";
import {
	Bar,
	BarChart,
	CartesianGrid,
	Legend,
	ResponsiveContainer,
	Tooltip,
	XAxis,
	YAxis,
} from "recharts";

const PAYMENT_METHODS = {
	paypal: {
		label: "PayPal",
		color: "#0070BA",
		amountKey: "paypalAmountCents",
		formattedAmountKey: "paypalFormattedAmount",
	},
	efevoopay: {
		label: "EfevooPay",
		color: "#7C3AED",
		amountKey: "efevoopayAmountCents",
		formattedAmountKey: "efevoopayFormattedAmount",
	},
	savingsBank: {
		label: "Caja de ahorro",
		color: "#F59E0B",
		amountKey: "savingsBankAmountCents",
		formattedAmountKey: "savingsBankFormattedAmount",
	},
	other: {
		label: "Otro",
		color: "#6B7280",
		amountKey: "otherAmountCents",
		formattedAmountKey: "otherFormattedAmount",
	},
};

export default function Admin({
	laboratory,
	onlinePharmacy,
	medicalAttention,
	paymentMethods = [],
	dateRange,
}) {
	return (
		<AdminLayout title="Administración">
			<Heading>Resumen de {dateRange}</Heading>

			{/* <Navbar className="my-2">
				<NavbarItem>Hoy</NavbarItem>
				<NavbarItem>Últimos 15 días</NavbarItem>
				<NavbarItem>Últimos 30 días</NavbarItem>
			</Navbar> */}

			<div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
				<div className="rounded-lg bg-zinc-50 px-8 py-6 shadow-sm ring-1 ring-zinc-950/5 lg:col-span-2 dark:bg-slate-950 dark:ring-white/10">
					<Subheading>Laboratorio</Subheading>
					<PurchasesChart chart={laboratory} />
				</div>{" "}
				<div className="rounded-lg bg-zinc-50 px-8 py-6 shadow-sm ring-1 ring-zinc-950/5 dark:bg-slate-950 dark:ring-white/10">
					<Subheading>Farmacia en línea</Subheading>
					<PurchasesChart chart={onlinePharmacy} />
				</div>{" "}
				<div className="rounded-lg bg-zinc-50 px-8 py-6 shadow-sm ring-1 ring-zinc-950/5 dark:bg-slate-950 dark:ring-white/10">
					<Subheading>Atención médica</Subheading>
					<PurchasesChart chart={medicalAttention} />
				</div>{" "}
				<div className="rounded-lg bg-zinc-50 px-8 py-6 shadow-sm ring-1 ring-zinc-950/5 lg:col-span-2 dark:bg-slate-950 dark:ring-white/10">
					<Subheading>Ventas diarias por método de pago</Subheading>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
						Número de compras completadas por día
					</Text>
					<PaymentMethodsChart data={paymentMethods} />
				</div>
			</div>
		</AdminLayout>
	);
}

function PaymentMethodsChart({ data }) {
	const dataPoints = Array.isArray(data?.dataPoints) ? data.dataPoints : [];
	const series = Array.isArray(data?.series) ? data.series : [];

	if (dataPoints.length === 0 || series.length === 0) {
		return (
			<div className="mt-4 rounded-lg border border-dashed border-zinc-300 bg-white/60 p-6 text-center dark:border-zinc-700 dark:bg-slate-900/50">
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Sin compras en este periodo.
				</Text>
			</div>
		);
	}

	return (
		<ResponsiveContainer width="100%" height={320} className="mt-4">
			<BarChart
				data={dataPoints}
				margin={{ top: 24, right: 12, bottom: 48, left: 12 }}
				className="[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-slate-700 [&_.recharts-tooltip-cursor]:fill-famedic-dark/5 dark:[&_.recharts-tooltip-cursor]:fill-white/5"
			>
				<CartesianGrid vertical={false} />
				<XAxis
					dataKey="label"
					interval={0}
					angle={-18}
					textAnchor="end"
					height={70}
					tickLine={false}
					axisLine={false}
					className="text-xs"
				/>
				<YAxis
					allowDecimals={false}
					tickLine={false}
					axisLine={false}
					className="text-xs"
				/>
				<Tooltip shared={false} content={<PaymentMethodTooltip />} />
				<Legend wrapperStyle={{ paddingTop: 12 }} />
				{series.map((item) => (
					<Bar
						key={item.key}
						dataKey={item.key}
						name={PAYMENT_METHODS[item.key]?.label || item.label}
						fill={PAYMENT_METHODS[item.key]?.color || item.color}
						radius={[4, 4, 0, 0]}
					/>
				))}
			</BarChart>
		</ResponsiveContainer>
	);
}

function PaymentMethodTooltip({ active, payload }) {
	if (!active || !payload?.length) {
		return null;
	}

	const bar = payload[0];
	const row = bar.payload;
	const methodKey = bar.dataKey;
	const method = PAYMENT_METHODS[methodKey];

	if (!method) {
		return null;
	}

	const count = Number(row[methodKey] ?? bar.value ?? 0);
	const amount =
		row[method.formattedAmountKey] || formatCents(row[method.amountKey]);

	return (
		<div className="max-w-[min(18rem,calc(100vw-2rem))] rounded-lg bg-white shadow-lg ring-1 ring-slate-950/10 dark:bg-slate-900 dark:ring-white/10">
			<div className="px-4 py-2">
				<Subheading>
					{row.fullDateLabel || formatFullDate(row.date)}
				</Subheading>
				<div className="mt-1 flex items-center gap-2">
					<span
						className="size-2 rounded-full"
						style={{ backgroundColor: method.color }}
					/>
					<Text>{method.label}</Text>
				</div>
				<Text>{formatPurchaseCount(count)}</Text>
				<Text>{amount}</Text>
			</div>
		</div>
	);
}

function formatPurchaseCount(count) {
	const value = Number.isFinite(Number(count)) ? Number(count) : 0;

	return `${value.toLocaleString("es-MX")} ${value === 1 ? "compra" : "compras"}`;
}

function formatCents(amountCents) {
	const value = Number.isFinite(Number(amountCents))
		? Number(amountCents)
		: 0;

	return `$${(value / 100).toLocaleString("en-US", {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})} MXN`;
}

function formatFullDate(date) {
	if (!date || typeof date !== "string") {
		return "";
	}

	const [year, month, day] = date.split("-").map(Number);
	if (!year || !month || !day) {
		return date;
	}

	return new Intl.DateTimeFormat("es-MX", {
		day: "numeric",
		month: "long",
		year: "numeric",
		timeZone: "UTC",
	}).format(new Date(Date.UTC(year, month - 1, day)));
}
