// Components/UserDashboardStats.jsx
import { useEffect, useState } from "react";
import { 
    CreditCardIcon, 
    MapPinIcon, 
    UserGroupIcon,
    ShoppingBagIcon,
    DocumentChartBarIcon,
    ReceiptPercentIcon,
    ChartBarIcon,
    ArrowTrendingUpIcon,
    UserCircleIcon,
    InformationCircleIcon,
    CheckCircleIcon
} from "@heroicons/react/24/outline";
import { Link } from "@inertiajs/react";
import clsx from "clsx";

// Paleta de colores sobrios y profesionales
const colors = {
    primary: "#1e2a3e",      // Azul marino oscuro (principal)
    primaryLight: "#2d3a4e",  // Azul marino más claro
    secondary: "#4a5b6e",     // Gris azulado
    accent: "#2c3e50",        // Azul grisáceo
    success: "#2e7d64",       // Verde oscuro sobrio
    warning: "#b45f2b",       // Naranja terracota
    danger: "#c44536",        // Rojo terracota
    info: "#3a6b8c",          // Azul acero
    textPrimary: "#1f2937",   // Gris muy oscuro
    textSecondary: "#4b5563", // Gris medio
    textMuted: "#9ca3af",     // Gris claro
    white: "#ffffff",
    grayLight: "#f3f4f6",
    grayMedium: "#e5e7eb",
    border: "#e5e7eb",
};

export default function UserDashboardStats({ user, stats, recentResults }) {
    const [showGraph, setShowGraph] = useState(false);
    const [showFinancialDetails, setShowFinancialDetails] = useState(false);

    const formatCurrency = (amount) => {
        if (!amount && amount !== 0) return '$0';
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    };

    // Tarjetas informativas - fondo blanco con acentos sobrios
    const infoCards = [
        {
            id: "family",
            title: "Familiares",
            value: stats?.contactsCount || 0,
            icon: UserGroupIcon,
            status: stats?.hasContacts ? "completed" : "pending",
            iconColor: stats?.hasContacts ? colors.success : colors.warning,
            description: stats?.hasContacts 
                ? `${stats.contactsCount} familiar${stats.contactsCount !== 1 ? 'es' : ''} registrado${stats.contactsCount !== 1 ? 's' : ''}` 
                : "Aún no tienes familiares registrados",
            actionText: stats?.hasContacts ? "Ver familiares" : "Agregar familiares",
            href: route("contacts.index")
        },
        {
            id: "addresses",
            title: "Direcciones",
            value: stats?.addressesCount || 0,
            icon: MapPinIcon,
            status: stats?.hasAddresses ? "completed" : "pending",
            iconColor: stats?.hasAddresses ? colors.success : colors.warning,
            description: stats?.hasAddresses 
                ? `${stats.addressesCount} dirección${stats.addressesCount !== 1 ? 'es' : ''} guardada${stats.addressesCount !== 1 ? 's' : ''}` 
                : "Aún no tienes direcciones registradas",
            actionText: stats?.hasAddresses ? "Ver direcciones" : "Agregar dirección",
            href: route("addresses.index")
        },
        {
            id: "payment-methods",
            title: "Métodos de pago",
            value: stats?.paymentMethodsCount || 0,
            icon: CreditCardIcon,
            status: stats?.hasPaymentMethods ? "completed" : "pending",
            iconColor: stats?.hasPaymentMethods ? colors.success : colors.warning,
            description: stats?.hasPaymentMethods 
                ? `${stats.paymentMethodsCount} tarjeta${stats.paymentMethodsCount !== 1 ? 's' : ''} registrada${stats.paymentMethodsCount !== 1 ? 's' : ''}` 
                : "Aún no tienes métodos de pago",
            actionText: stats?.hasPaymentMethods ? "Ver tarjetas" : "Agregar tarjeta",
            href: route("payment-methods.index")
        }
    ];

    // Tarjetas de actividad reciente - fondo blanco
    const recentActivity = [
        {
            id: "recent-purchases",
            title: "Compras recientes",
            value: stats?.recentPurchasesCount || 0,
            icon: ShoppingBagIcon,
            iconColor: colors.primary,
            description: "últimos 30 días",
            period: "30 días",
            href: route("laboratory-purchases.index")
        },
        {
            id: "recent-results",
            title: "Resultados nuevos",
            value: recentResults?.length || 0,
            icon: DocumentChartBarIcon,
            iconColor: colors.primary,
            description: "últimos 30 días",
            period: "30 días",
            href: route("laboratory-results.index")
        },
        {
            id: "recent-invoices",
            title: "Facturas emitidas",
            value: stats?.recentInvoicesCount || 0,
            icon: ReceiptPercentIcon,
            iconColor: colors.primary,
            description: "últimos 30 días",
            period: "30 días",
            href: route("tax-profiles.index")
        }
    ];

    const financialSummary = stats?.financialSummary;

    return (
        <div className="space-y-4">
            {/* Tarjetas informativas - fondo blanco */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                {infoCards.map((card) => (
                    <Link
                        key={card.id}
                        href={card.href}
                        className="group relative overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-all duration-300 hover:shadow-md hover:border-gray-300"
                    >
                        <div className="relative flex items-start justify-between">
                            <div className="flex-1">
                                <div className="flex items-center gap-2">
                                    <card.icon className="h-5 w-5" style={{ color: card.iconColor }} />
                                    <h3 className="text-sm font-medium text-gray-500">
                                        {card.title}
                                    </h3>
                                </div>
                                <p className="mt-2 text-3xl font-bold text-gray-800">
                                    {card.value}
                                </p>
                                <p className="mt-1 text-xs text-gray-400">
                                    {card.description}
                                </p>
                                <div className="mt-3">
                                    <span className="text-xs font-medium text-gray-500 transition-colors group-hover:text-gray-700 group-hover:underline">
                                        {card.actionText} →
                                    </span>
                                </div>
                            </div>
                            <div className="rounded-full p-2 transition-all group-hover:scale-110" style={{ backgroundColor: `${card.iconColor}10` }}>
                                <card.icon className="h-4 w-4" style={{ color: card.iconColor }} />
                            </div>
                        </div>
                        
                        {/* Indicador de estado */}
                        <div 
                            className="absolute bottom-0 left-0 h-0.5 rounded-full transition-all duration-300"
                            style={{ 
                                backgroundColor: card.status === "completed" ? colors.success : colors.warning,
                                width: card.status === "completed" ? "100%" : "30%"
                            }}
                        />
                    </Link>
                ))}
            </div>

            {/* Tarjetas de actividad reciente */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                {recentActivity.map((card) => (
                    <div
                        key={card.id}
                        className="relative overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-all duration-300 hover:shadow-md"
                    >
                        <div className="relative flex items-start justify-between">
                            <div className="flex-1">
                                <div className="flex items-center gap-2">
                                    <card.icon className="h-5 w-5" style={{ color: colors.primary }} />
                                    <h3 className="text-sm font-medium text-gray-500">
                                        {card.title}
                                    </h3>
                                </div>
                                <p className="mt-2 text-3xl font-bold text-gray-800">
                                    {card.value}
                                </p>
                                <div className="mt-1 flex items-center gap-1">
                                    <span className="text-xs text-gray-400">
                                        {card.description}
                                    </span>
                                    <span className="rounded-full bg-gray-100 px-1.5 py-0.5 text-[9px] font-medium text-gray-500">
                                        {card.period}
                                    </span>
                                </div>
                            </div>
                            <div className="rounded-full bg-gray-100 p-2">
                                <card.icon className="h-4 w-4 text-gray-500" />
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {/* Gráfica de gastos mensuales - colores sobrios */}
            {stats?.monthlySpending && stats.monthlySpending.length > 0 && (
                <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <button
                        onClick={() => setShowGraph(!showGraph)}
                        className="flex w-full items-center justify-between"
                    >
                        <div className="flex items-center gap-2">
                            <ChartBarIcon className="h-5 w-5 text-gray-600" />
                            <span className="text-sm font-semibold text-gray-700">
                                Historial de gastos
                            </span>
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">
                                Últimos 12 meses
                            </span>
                        </div>
                        <ArrowTrendingUpIcon className={clsx(
                            "h-4 w-4 text-gray-400 transition-transform duration-300",
                            showGraph && "rotate-180"
                        )} />
                    </button>
                    
                    {showGraph && (
                        <div className="mt-4 space-y-3">
                            {stats.monthlySpending.map((month, idx) => (
                                <div key={idx} className="flex items-center gap-3">
                                    <span className="w-16 text-sm font-medium text-gray-500">
                                        {month.month}
                                    </span>
                                    <div className="flex-1">
                                        <div className="h-8 overflow-hidden rounded-full bg-gray-100">
                                            <div
                                                className="flex h-full items-center justify-end rounded-full px-2 transition-all duration-500"
                                                style={{ 
                                                    width: `${Math.min((month.amount / (stats.maxSpending || 1)) * 100, 100)}%`,
                                                    backgroundColor: colors.primary
                                                }}
                                            >
                                                {month.amount > 0 && (
                                                    <span className="text-xs font-bold text-white drop-shadow">
                                                        {formatCurrency(month.amount)}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Resumen financiero - estilo uniforme y sobrio */}
            {financialSummary && (
                <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <button
                        onClick={() => setShowFinancialDetails(!showFinancialDetails)}
                        className="flex w-full items-center justify-between"
                    >
                        <div className="flex items-center gap-2">
                            <InformationCircleIcon className="h-5 w-5 text-gray-600" />
                            <span className="text-sm font-semibold text-gray-700">
                                Resumen de inversión en salud
                            </span>
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                {formatCurrency(financialSummary.totalHistorical)} total
                            </span>
                        </div>
                        <ArrowTrendingUpIcon className={clsx(
                            "h-4 w-4 text-gray-400 transition-transform duration-300",
                            showFinancialDetails && "rotate-180"
                        )} />
                    </button>
                    
                    {showFinancialDetails && (
                        <div className="mt-4 space-y-3">
                            {/* Total histórico */}
                            <div className="rounded-lg bg-gray-50 p-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm font-medium text-gray-700">
                                            Total desde el inicio
                                        </p>
                                        <p className="text-xs text-gray-400">
                                            Desde que te registraste en Famedic
                                        </p>
                                    </div>
                                    <p className="text-xl font-bold text-gray-800">
                                        {formatCurrency(financialSummary.totalHistorical)}
                                    </p>
                                </div>
                            </div>
                            
                            {/* Últimos 12 meses */}
                            <div className="rounded-lg bg-gray-50 p-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm font-medium text-gray-700">
                                            Últimos 12 meses
                                        </p>
                                        <p className="text-xs text-gray-400">
                                            Compras realizadas en el último año
                                        </p>
                                    </div>
                                    <p className="text-xl font-bold text-gray-800">
                                        {formatCurrency(financialSummary.totalLast12Months)}
                                    </p>
                                </div>
                            </div>
                            
                            {/* Últimos 30 días */}
                            <div className="rounded-lg bg-gray-50 p-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm font-medium text-gray-700">
                                            Últimos 30 días
                                        </p>
                                        <p className="text-xs text-gray-400">
                                            Compras más recientes
                                        </p>
                                    </div>
                                    <p className="text-xl font-bold text-gray-800">
                                        {formatCurrency(financialSummary.totalLast30Days)}
                                    </p>
                                </div>
                            </div>
                            
                            {/* Compras antiguas (si existen) */}
                            {financialSummary.hasOldPurchases && (
                                <div className="rounded-lg bg-gray-50 p-3">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-sm font-medium text-gray-700">
                                                Compras anteriores
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                Realizadas hace más de 12 meses
                                            </p>
                                        </div>
                                        <p className="text-xl font-bold text-gray-800">
                                            {formatCurrency(financialSummary.totalOld)}
                                        </p>
                                    </div>
                                </div>
                            )}
                            
                            {/* Mensaje explicativo */}
                            <div className="mt-2 rounded-lg bg-gray-100 p-2 text-center">
                                <p className="text-xs text-gray-500">
                                    💡 El total de {formatCurrency(financialSummary.totalHistorical)} incluye todas tus compras desde que eres parte de Famedic.
                                    {financialSummary.hasOldPurchases && 
                                        ` De este total, ${formatCurrency(financialSummary.totalOld)} corresponden a compras realizadas hace más de un año.`
                                    }
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Botones de acción en negro */}
            <div className="grid grid-cols-2 gap-3">
                <Link
                    href={route("laboratory-purchases.index")}
                    className="flex items-center justify-center gap-2 rounded-xl bg-black px-4 py-3 font-semibold text-white transition-all duration-300 hover:bg-gray-800 hover:scale-105 hover:shadow-lg"
                >
                    <ShoppingBagIcon className="h-5 w-5" />
                    Ver mis compras
                </Link>
                <Link
                    href={route("user.edit")}
                    className="flex items-center justify-center gap-2 rounded-xl bg-black px-4 py-3 font-semibold text-white transition-all duration-300 hover:bg-gray-800 hover:scale-105 hover:shadow-lg"
                >
                    <UserCircleIcon className="h-5 w-5" />
                    Ver mi cuenta
                </Link>
            </div>

            {/* Mensaje de estado con nuevo texto */}
            <div className="mt-2 rounded-xl bg-gray-50 p-4 shadow-sm border border-gray-100">
                <div className="flex items-center justify-center gap-3">
                    <CheckCircleIcon className="h-5 w-5 text-gray-600" />
                    <span className="text-sm font-medium text-gray-600">
                        Membresías y estudios de laboratorio en una misma plataforma
                    </span>
                </div>
            </div>
        </div>
    );
}